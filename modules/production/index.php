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
        $elementsData = $_POST['elements'] ?? [];

        // Calculate pieces entered across elements or shift fields
        $calcDayPcs = 0;
        $calcNightPcs = 0;
        if (!empty($elementsData)) {
            foreach ($elementsData as $elId => $vals) {
                $calcDayPcs += max(0, (int)($vals['op1_pieces'] ?? 0));
                if ($shiftType === 'Double Shift') {
                    $calcNightPcs += max(0, (int)($vals['op2_pieces'] ?? 0));
                }
            }
        } elseif ($sizeMode === 'Multi Size') {
            foreach ($daySizes as $sQty) $calcDayPcs += max(0, (int)$sQty);
            if ($shiftType === 'Double Shift') {
                foreach ($nightSizes as $sQty) $calcNightPcs += max(0, (int)$sQty);
            }
        } else {
            $calcDayPcs = $dayPieces;
            $calcNightPcs = ($shiftType === 'Double Shift') ? $nightPieces : 0;
        }

        $hasDayPieces = ($calcDayPcs > 0 || $dayPieces > 0);
        $hasNightPieces = ($shiftType === 'Double Shift' && ($calcNightPcs > 0 || $nightPieces > 0));

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
                    $hasNightPieces = false;
                }

                if (!$hasDayPieces && !$hasNightPieces) {
                    $error = 'Please enter produced pieces for at least one element or shift (کم از کم ایک حصے یا شفٹ کے پیسز درج کریں).';
                } elseif ($hasDayPieces && $dayWorkerId <= 0) {
                    $error = 'Please select Day Operator for day produced pieces (ڈے آپریٹر منتخب کریں).';
                } elseif ($hasNightPieces && $nightWorkerId <= 0) {
                    $error = 'Please select Night Operator for night produced pieces (نائٹ آپریٹر منتخب کریں).';
                } elseif ($shiftType !== 'Double Shift' && $dayWorkerId <= 0) {
                    $error = 'Please select Day Operator.';
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

                    if ($dayWorkerId > 0 && $calcElemDayLabour > 0) {
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

                        $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-D');
                        $ins = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, 'Day Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $dayWorkerId, $singleSizeId, $dayPieces, $dayPieces, $effectiveDayRate, $effectiveDayMins, $dayMinRate, $minsTotal, $dayTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                        $firstDayId = (int)$db->lastInsertId();
                    } elseif ($dayWorkerId > 0 && $sizeMode === 'Multi Size' && !empty($daySizes)) {
                        $seq = 1;
                        foreach ($daySizes as $sId => $sQty) {
                            $sQty = (int)$sQty;
                            if ($sQty <= 0) continue;
                            $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-D' . ($seq++));
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
                    } elseif ($dayWorkerId > 0 && ($dayPieces > 0 || empty($elementsData))) {
                        $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-D');
                        $dayTotalPcs = $dayPieces;

                        // Check Day Worker Wage Type
                        $dayWTypeStmt = $db->prepare("SELECT worker_type FROM workers WHERE id = ?");
                        $dayWTypeStmt->execute([$dayWorkerId]);
                        $dayWorkerType = (string)($dayWTypeStmt->fetchColumn() ?: 'Minute Rate');
                        $isDayDaily = (stripos($dayWorkerType, 'daily') !== false || stripos($dayWorkerType, 'یومیہ') !== false);
                        $isDayMonthly = (stripos($dayWorkerType, 'month') !== false || stripos($dayWorkerType, 'ماہانہ') !== false || stripos($dayWorkerType, 'salary') !== false);

                        if ($isDayDaily) {
                            $dayTotalLabour = $dayRate;
                        } elseif ($isDayMonthly) {
                            $dayTotalLabour = 0.00;
                        } else {
                            $dayTotalLabour = round($dayPieces * $dayRate, 2);
                        }
                        $minsTotal = round($dayPieces * $dayMins, 2);
                        $ins = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, 'Day Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $dayWorkerId, $singleSizeId, $dayPieces, $dayPieces, $dayRate, $dayMins, $dayMinRate, $minsTotal, $dayTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                        $firstDayId = (int)$db->lastInsertId();
                    }

                    // Day Operator Ledger Update
                    if ($dayTotalLabour > 0 && $firstDayId && $dayWorkerId > 0) {
                        $wStmt = $db->prepare("SELECT COALESCE((SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), (SELECT opening_balance FROM workers WHERE id = ?))");
                        $wStmt->execute([$dayWorkerId, $dayWorkerId]);
                        $lastBal = (float)$wStmt->fetchColumn();
                        $newBal = $lastBal + $dayTotalLabour;

                        $dayLedgerDesc = ($isDayDaily ?? false)
                            ? "Day Operator daily shift on Lot #$lotNum ($dayTotalPcs pcs @ Rs. " . number_format($dayRate, 2) . "/day Daily Wage)"
                            : "Day Operator initial batch on Lot #$lotNum ($dayTotalPcs pcs @ Rs. " . number_format($dayRate, 2) . ")";

                        $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                      VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                           ->execute([$dayWorkerId, $entryDate, $firstDayId, $lotId, $operationId, $dayTotalLabour, $newBal, $dayLedgerDesc, $_SESSION['user_id'] ?? null]);
                    }

                    // 2. Night Operator Entries (if Double Shift & Night Worker selected)
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

                            $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-N');
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
                                $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-N' . ($seq++));
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
                        } elseif ($nightPieces > 0 || empty($elementsData)) {
                            $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-N');
                            $nightTotalPcs = $nightPieces;

                            // Check Night Worker Wage Type
                            $nightWTypeStmt = $db->prepare("SELECT worker_type FROM workers WHERE id = ?");
                            $nightWTypeStmt->execute([$nightWorkerId]);
                            $nightWorkerType = (string)($nightWTypeStmt->fetchColumn() ?: 'Minute Rate');
                            $isNightDaily = (stripos($nightWorkerType, 'daily') !== false || stripos($nightWorkerType, 'یومیہ') !== false);
                            $isNightMonthly = (stripos($nightWorkerType, 'month') !== false || stripos($nightWorkerType, 'ماہانہ') !== false || stripos($nightWorkerType, 'salary') !== false);

                            if ($isNightDaily) {
                                $nightTotalLabour = $nightRate;
                            } elseif ($isNightMonthly) {
                                $nightTotalLabour = 0.00;
                            } else {
                                $nightTotalLabour = round($nightPieces * $nightRate, 2);
                            }

                            $minsTotal = round($nightPieces * $nightMins, 2);
                            $ins = $db->prepare("INSERT INTO production_entries 
                                (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 'Night Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $nightWorkerId, $singleSizeId, $nightPieces, $nightPieces, $nightRate, $nightMins, $nightMinRate, $minsTotal, $nightTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                            $firstNightId = (int)$db->lastInsertId();
                        }

                        // Night Operator Ledger Update
                        if ($nightTotalLabour > 0 && $firstNightId && $nightWorkerId > 0) {
                            $wStmt = $db->prepare("SELECT COALESCE((SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), (SELECT opening_balance FROM workers WHERE id = ?))");
                            $wStmt->execute([$nightWorkerId, $nightWorkerId]);
                            $lastBal = (float)$wStmt->fetchColumn();
                            $newBal = $lastBal + $nightTotalLabour;

                            $nightLedgerDesc = ($isNightDaily ?? false)
                                ? "Night Operator daily shift on Lot #$lotNum ($nightTotalPcs pcs @ Rs. " . number_format($nightRate, 2) . "/day Daily Wage)"
                                : "Night Operator initial batch on Lot #$lotNum ($nightTotalPcs pcs @ Rs. " . number_format($nightRate, 2) . ")";

                            $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                          VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                               ->execute([$nightWorkerId, $entryDate, $firstNightId, $lotId, $operationId, $nightTotalLabour, $newBal, $nightLedgerDesc, $_SESSION['user_id'] ?? null]);
                        }
                    }

                    // Update Lot Status
                    if ($lotRow['status'] === 'Draft') {
                        $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                        $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'Operator initial production run submitted', ?)")
                           ->execute([$lotId, $_SESSION['user_id'] ?? null]);
                    }

                    // Sync lot_operator_element_entries (Cumulative additions preserving element-specific operators)
                    if (!empty($elementsData)) {
                        foreach ($elementsData as $elId => $vals) {
                            $elId = (int)$elId;
                            $req = (int)($vals['required'] ?? $lotRow['total_pieces']);
                            $p1 = max(0, (int)($vals['op1_pieces'] ?? 0));
                            $p2 = ($shiftType === 'Double Shift') ? max(0, (int)($vals['op2_pieces'] ?? 0)) : 0;
                            $sam = max(0, (float)($vals['sam_minutes'] ?? 0));
                            $spentMins = round(($p1 + $p2) * $sam, 2);

                            $chkEl = $db->prepare("SELECT id, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, minutes_spent FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                            $chkEl->execute([$lotId, $elId]);
                            $existingEl = $chkEl->fetch();

                            if ($existingEl) {
                                $newP1 = (int)$existingEl['operator1_pieces'] + $p1;
                                $newP2 = (int)$existingEl['operator2_pieces'] + $p2;
                                $newTot = $newP1 + $newP2;
                                if ($newTot > $req) $newTot = $req;
                                $newSpentMins = (float)$existingEl['minutes_spent'] + $spentMins;

                                // Preserve existing operator IDs if no pieces entered in this run for that shift
                                $saveOp1Id = ($p1 > 0 && $dayWorkerId > 0) ? $dayWorkerId : ($existingEl['operator1_id'] ?: ($dayWorkerId ?: null));
                                $saveOp2Id = ($p2 > 0 && $nightWorkerId > 0) ? $nightWorkerId : ($existingEl['operator2_id'] ?: ($nightWorkerId ?: null));

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
                                   ->execute([$entryDate, $saveOp1Id, $newP1, $saveOp2Id, $newP2, $newTot, $req, $newSpentMins, $existingEl['id']]);
                            } else {
                                $newTot = $p1 + $p2;
                                if ($newTot > $req) $newTot = $req;
                                $saveOp1Id = ($dayWorkerId > 0) ? $dayWorkerId : null;
                                $saveOp2Id = ($nightWorkerId > 0) ? $nightWorkerId : null;
                                $db->prepare("INSERT INTO lot_operator_element_entries 
                                              (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, required_pieces, minutes_spent, created_by) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$lotId, $elId, $entryDate, $saveOp1Id, $p1, $saveOp2Id, $p2, $newTot, $req, $spentMins, $_SESSION['user_id'] ?? null]);
                            }
                        }
                    } else {
                        $peList = $db->prepare("SELECT id, multiplier, quantity FROM product_elements WHERE product_id = (SELECT product_id FROM lots WHERE id = ?)");
                        $peList->execute([$lotId]);
                        foreach ($peList->fetchAll() as $pel) {
                            $m = (float)($pel['quantity'] ?: $pel['multiplier'] ?: 1);
                            if ($m <= 0) $m = 1.0;
                            $elId = (int)$pel['id'];
                            $req = (int)round($m * (int)$lotRow['total_pieces']);
                            $p1 = (int)round($m * $dayTotalPcs);
                            $p2 = (int)round($m * $nightTotalPcs);

                            $chkEl = $db->prepare("SELECT id, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                            $chkEl->execute([$lotId, $elId]);
                            $existingEl = $chkEl->fetch();

                            if ($existingEl) {
                                $newP1 = (int)$existingEl['operator1_pieces'] + $p1;
                                $newP2 = (int)$existingEl['operator2_pieces'] + $p2;
                                $newTot = $newP1 + $newP2;
                                if ($newTot > $req) $newTot = $req;
                                $saveOp1Id = ($p1 > 0 && $dayWorkerId > 0) ? $dayWorkerId : ($existingEl['operator1_id'] ?: ($dayWorkerId ?: null));
                                $saveOp2Id = ($p2 > 0 && $nightWorkerId > 0) ? $nightWorkerId : ($existingEl['operator2_id'] ?: ($nightWorkerId ?: null));

                                $db->prepare("UPDATE lot_operator_element_entries 
                                              SET entry_date = ?, operator1_id = ?, operator1_pieces = ?, operator2_id = ?, operator2_pieces = ?, total_pieces = ?, required_pieces = ? 
                                              WHERE id = ?")
                                   ->execute([$entryDate, $saveOp1Id, $newP1, $saveOp2Id, $newP2, $newTot, $req, $existingEl['id']]);
                            } else {
                                $newTot = $p1 + $p2;
                                if ($newTot > $req) $newTot = $req;
                                $saveOp1Id = ($dayWorkerId > 0) ? $dayWorkerId : null;
                                $saveOp2Id = ($nightWorkerId > 0) ? $nightWorkerId : null;
                                $db->prepare("INSERT INTO lot_operator_element_entries 
                                              (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, required_pieces, created_by) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$lotId, $elId, $entryDate, $saveOp1Id, $p1, $saveOp2Id, $p2, $newTot, $req, $_SESSION['user_id'] ?? null]);
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
                    $msgParts = [];
                    if ($dayTotalPcs > 0 && $dayWorkerId > 0) {
                        $wName1 = (string)($db->query("SELECT name FROM workers WHERE id = $dayWorkerId")->fetchColumn() ?: 'Day Operator');
                        $msgParts[] = "$wName1 (Day: $dayTotalPcs pcs)";
                    }
                    if ($nightTotalPcs > 0 && $nightWorkerId > 0) {
                        $wName2 = (string)($db->query("SELECT name FROM workers WHERE id = $nightWorkerId")->fetchColumn() ?: 'Night Operator');
                        $msgParts[] = "$wName2 (Night: $nightTotalPcs pcs)";
                    }
                    $msg = "Operator Production recorded: " . (!empty($msgParts) ? implode(' + ', $msgParts) : "Pieces saved");

                    if ($stepStatus === 'Completed') {
                        $msg .= " — تمام اعضاء مکمل ہو گئے! اگلا مرحلہ (کٹنگ) لوڈ ہو گیا۔";
                        redirect_next_production_step($lotId, $operationId, $msg, 'cutting');
                    } else {
                        $msg .= " — جزوی انٹری محفوظ ہو گئی (ابھی $incompleteCount حصے باقی ہیں)";
                        redirect("modules/production/index.php?lot_id=$lotId&operation_id=$operationId&mode=operator_initial", $msg);
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    // Auto-heal MySQL 3105 generated column error if total_pieces is a generated column
                    if (strpos($e->getMessage(), '3105') !== false || stripos($e->getMessage(), 'generated column') !== false) {
                        try {
                            $db->exec("ALTER TABLE lot_operator_element_entries MODIFY COLUMN total_pieces INT NOT NULL DEFAULT 0");
                            $error = 'ڈیٹابیس کا کالم (total_pieces) خودکار طور پر اپڈیٹ کر دیا گیا ہے۔ براہ کرم "Save" بٹن پر دوبارہ کلک کریں۔ (Database column auto-repaired. Please click Save again.)';
                        } catch (Exception $eFix) {
                            $error = 'Error saving operator entry: ' . $e->getMessage();
                        }
                    } else {
                        $error = 'Error saving operator entry: ' . $e->getMessage();
                    }
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
                            $mult = $multRow ? (float)($multRow['quantity'] ?: $multRow['multiplier'] ?: 1) : 1;
                            if ($mult <= 0) $mult = 1.0;
                            $req = (int)round($mult * (int)$lotRow['total_pieces']);

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
                                $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum . '-' . ($seq++));

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

                        // Check worker wage type for Cutting
                        $cutWTypeStmt = $db->prepare("SELECT worker_type FROM workers WHERE id = ?");
                        $cutWTypeStmt->execute([$workerId]);
                        $cutWorkerWageType = (string)($cutWTypeStmt->fetchColumn() ?: 'Piece Rate');
                        $isCutDaily = (stripos($cutWorkerWageType, 'daily') !== false || stripos($cutWorkerWageType, 'یومیہ') !== false);
                        $isCutMonthly = (stripos($cutWorkerWageType, 'month') !== false || stripos($cutWorkerWageType, 'ماہانہ') !== false || stripos($cutWorkerWageType, 'salary') !== false);

                        if ($isCutDaily) {
                            $labourAmount = $rate;
                            if ($matrixSavedCount > 0 && $lastPeId) {
                                // For daily wage, total credit is the daily wage rate
                                $db->prepare("UPDATE production_entries SET labour_amount = 0.00 WHERE lot_id = ? AND operation_id = ? AND worker_id = ? AND entry_date = ? AND entry_number LIKE 'PE-CUT-%'")
                                   ->execute([$lotId, $operationId, $workerId, $entryDate]);
                                $db->prepare("UPDATE production_entries SET labour_amount = ? WHERE id = ?")
                                   ->execute([$labourAmount, $lastPeId]);
                            }
                        } elseif ($isCutMonthly) {
                            $labourAmount = 0.00;
                            if ($matrixSavedCount > 0) {
                                $db->prepare("UPDATE production_entries SET labour_amount = 0.00 WHERE lot_id = ? AND operation_id = ? AND worker_id = ? AND entry_date = ? AND entry_number LIKE 'PE-CUT-%'")
                                   ->execute([$lotId, $operationId, $workerId, $entryDate]);
                            }
                        } else {
                            if ($matrixSavedCount > 0) {
                                $wholePieces = $matrixTotalGood;
                                $labourAmount = $matrixTotalLabour;
                            }
                        }
                    }

                    // Fallback single whole pieces entry if matrix was empty
                    if ($matrixSavedCount === 0 && $wholePieces > 0) {
                        if ($isCutDaily) {
                            $labourAmount = $rate;
                        } elseif ($isCutMonthly) {
                            $labourAmount = 0.00;
                        } else {
                            $labourAmount = round($wholePieces * $rate, 2);
                        }
                        $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', generate_doc_number('PE-CUT-', 'production_entries', 'entry_number'));

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

                        $cutLedgerDesc = $isCutDaily
                            ? "Cutting master daily shift on Lot #{$lotRow['lot_number']} ($wholePieces pcs @ Rs. " . number_format($rate, 2) . "/day Daily Wage)"
                            : "Cutting master batch on Lot #{$lotRow['lot_number']} ($wholePieces pcs @ Rs. " . number_format($rate, 2) . "/pc)";

                        $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                      VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                           ->execute([$workerId, $entryDate, $lastPeId, $lotId, $operationId, $labourAmount, $newBal, $cutLedgerDesc, $_SESSION['user_id'] ?? null]);
                    }

                    if ($lotRow['status'] === 'Draft') {
                        $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                    }
                    $db->prepare("UPDATE lot_workflow_steps SET status = 'In Progress' WHERE lot_id = ? AND operation_id = ? AND status = 'Pending'")
                       ->execute([$lotId, $operationId]);

                    $db->commit();
                    $cutSuccessMsg = "Cutting entry saved successfully! $wholePieces pieces cut (Labour: " . format_currency($labourAmount) . ").";

                    // Auto-advance to next workflow step
                    redirect_next_production_step($lotId, $operationId, $cutSuccessMsg, 'matrix');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Error saving cutting entry: ' . $e->getMessage();
                }
            }
        }
    }

    // =========================================================================
    // 2. DOWNSTREAM MODES (FAST MATRIX BATCH & SINGLE ITEM ENTRY)
    // =========================================================================
    elseif ($entryMode === 'matrix' || $entryMode === 'single') {
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
                        // Fetch operation and worker names & wage type
                        $opQuery = $db->prepare("SELECT name FROM operations WHERE id = ?");
                        $opQuery->execute([$operationId]);
                        $opName = $opQuery->fetchColumn();

                        $wQuery = $db->prepare("SELECT name, worker_type FROM workers WHERE id = ?");
                        $wQuery->execute([$workerId]);
                        $workerRow = $wQuery->fetch();
                        $workerName = $workerRow['name'] ?? '';
                        $workerWageType = (string)($workerRow['worker_type'] ?? 'Piece Rate');
                        $isMatDaily = (stripos($workerWageType, 'daily') !== false || stripos($workerWageType, 'یومیہ') !== false);
                        $isMatMonthly = (stripos($workerWageType, 'month') !== false || stripos($workerWageType, 'ماہانہ') !== false || stripos($workerWageType, 'salary') !== false);

                        if ($isMatDaily) {
                            $totalLabour = $rate;
                        } elseif ($isMatMonthly) {
                            $totalLabour = 0.00;
                        }

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
                    foreach ($entriesToSave as $entryIdx => $entry) {
                        $candidateEntry = 'PE-' . str_pad((string)$docSeq++, 4, '0', STR_PAD_LEFT);
                        $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $candidateEntry);
                        $entryTotalMinutes = round($entry['good_qty'] * $minutesPerPiece, 2);
                        $entryLabour = $entry['labour_amount'];
                        if ($isMatDaily) {
                            $entryLabour = ($entryIdx === 0) ? $rate : 0.00;
                        } elseif ($isMatMonthly) {
                            $entryLabour = 0.00;
                        }
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
                            $entryLabour,
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
                            $selectedWhId = !empty($_POST['packing_warehouse_id']) ? (int)$_POST['packing_warehouse_id'] : get_default_warehouse_id('finished');
                            $packNum = ensure_unique_doc_number($db, 'packing_entries', 'packing_number', generate_doc_number('PKG-', 'packing_entries', 'packing_number'));
                            $packageNo = 'SET-LOT' . $lotId . '-' . $entry['color_id'] . '-' . $entry['size_id'];
                            $pkStmt = $db->prepare("INSERT INTO packing_entries 
                                (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, package_no, remarks, warehouse_id, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Master Set', ?, ?, ?, ?)");
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
                                $selectedWhId,
                                $_SESSION['user_id'] ?? null
                            ]);
                            $packingId = (int)$db->lastInsertId();

                            // Update Finished Goods Stock in selected destination warehouse
                            $finWhId = $selectedWhId;
                            $stkCheck = $db->prepare("SELECT id FROM finished_goods_stock WHERE lot_id = ? AND color_id = ? AND size_id = ? AND warehouse_id = ? LIMIT 1");
                            $stkCheck->execute([$lotId, $entry['color_id'], $entry['size_id'], $finWhId]);
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
                        if ($isMatDaily) {
                            $ledgerDesc = "Batch daily shift labour on Lot #$lotNum - $opName ($totalGood good pcs @ Rs. " . number_format($rate, 2) . "/day Daily Wage)";
                        } else {
                            $ledgerDesc = "Batch labour on Lot #$lotNum - $opName ($totalGood good pcs";
                            if ($totalRejected > 0) $ledgerDesc .= ", $totalRejected rej";
                            if ($totalRework > 0) $ledgerDesc .= ", $totalRework rew";
                            $ledgerDesc .= " across $varCount variants @ Rs. " . number_format($rate, 2) . "/pc)";
                        }

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
                    redirect_next_production_step($lotId, $operationId, $feedbackMsg, 'matrix');
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

                // Check worker wage type for Single Mode
                $wTypeStmt = $db->prepare("SELECT worker_type FROM workers WHERE id = ?");
                $wTypeStmt->execute([$workerId]);
                $singleWorkerWageType = (string)($wTypeStmt->fetchColumn() ?: 'Piece Rate');
                $isSingleDaily = (stripos($singleWorkerWageType, 'daily') !== false || stripos($singleWorkerWageType, 'یومیہ') !== false);
                $isSingleMonthly = (stripos($singleWorkerWageType, 'month') !== false || stripos($singleWorkerWageType, 'ماہانہ') !== false || stripos($singleWorkerWageType, 'salary') !== false);

                if ($isSingleDaily) {
                    $labourAmount = $rate;
                } elseif ($isSingleMonthly) {
                    $labourAmount = 0.00;
                } else {
                    $labourAmount = round($goodQty * $rate, 2);
                }
                $cumulativeGood = $previouslyDone + $goodQty;

                $db->beginTransaction();
                try {

                // Generate entry number
                $entryNumber = ensure_unique_doc_number($db, 'production_entries', 'entry_number', generate_doc_number('PE-', 'production_entries', 'entry_number'));

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
                    $selectedWhId = !empty($_POST['packing_warehouse_id']) ? (int)$_POST['packing_warehouse_id'] : get_default_warehouse_id('finished');
                    $packNum = ensure_unique_doc_number($db, 'packing_entries', 'packing_number', generate_doc_number('PKG-', 'packing_entries', 'packing_number'));
                    $packageNo = 'SET-LOT' . $lotId . '-' . $colorId . '-' . $sizeId;
                    $pkStmt = $db->prepare("INSERT INTO packing_entries 
                        (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, package_no, remarks, warehouse_id, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Master Set', ?, ?, ?, ?)");
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
                        $selectedWhId,
                        $_SESSION['user_id'] ?? null
                    ]);
                    $packingId = (int)$db->lastInsertId();

                    // Update Finished Goods Stock in selected warehouse
                    $finWhId = $selectedWhId;
                    $stkCheck = $db->prepare("SELECT id FROM finished_goods_stock WHERE lot_id = ? AND color_id = ? AND size_id = ? AND warehouse_id = ? LIMIT 1");
                    $stkCheck->execute([$lotId, $colorId, $sizeId, $finWhId]);
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

                $ledgerDesc = $isSingleDaily
                    ? "Labour earned on Lot #$lotNum - $opName ($goodQty pcs @ Rs. " . number_format($rate, 2) . "/day Daily Wage)"
                    : "Labour earned on Lot #$lotNum - $opName ($goodQty pcs @ Rs. " . number_format($rate, 2) . "/pc)";
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
                redirect_next_production_step($lotId, $operationId, $feedbackMsg, 'single');
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Error saving entry: ' . $e->getMessage();
            }
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

// Fetch active warehouses for packing stage
$finishedWarehouses = $db->query("SELECT id, name, location FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch all active operations for fast desk filters
$operationsList = $db->query("SELECT o.*, dep.name AS department_name 
                             FROM operations o 
                             LEFT JOIN departments dep ON o.department_id = dep.id 
                             WHERE o.is_active = 1 
                             ORDER BY o.sequence ASC, o.name ASC")->fetchAll();

// Active/Preselected lot for recent entries panel
$activePanelLotId = $preselectedLotId ?: (int)($_POST['lot_id'] ?? 0);
if ($activePanelLotId <= 0 && !empty($lots)) {
    $activePanelLotId = (int)$lots[0]['id'];
}

$recentLot = null;
$recentEntries = [];
if ($activePanelLotId > 0) {
    $rLotStmt = $db->prepare("SELECT id, lot_number FROM lots WHERE id = ?");
    $rLotStmt->execute([$activePanelLotId]);
    $recentLot = $rLotStmt->fetch();

    $recentStmt = $db->prepare("SELECT pe.*, l.lot_number, o.name AS op_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                                FROM production_entries pe 
                                JOIN lots l ON pe.lot_id = l.id 
                                JOIN operations o ON pe.operation_id = o.id 
                                JOIN workers w ON pe.worker_id = w.id 
                                LEFT JOIN colors c ON pe.color_id = c.id 
                                LEFT JOIN sizes s ON pe.size_id = s.id 
                                WHERE pe.lot_id = ?
                                ORDER BY pe.id DESC LIMIT 10");
    $recentStmt->execute([$activePanelLotId]);
    $recentEntries = $recentStmt->fetchAll();
}

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

        <!-- WORKFLOW ENTRY MODE SWITCHER TABS -->
        <div class="card-modern shadow-sm mb-4 border-0 bg-white">
            <div class="card-modern-body p-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="btn-group" role="group">
                    <button type="button" class="btn <?= (empty($preselectedMode) && empty($_POST)) ? 'btn-primary' : 'btn-outline-secondary' ?> fw-bold px-3 py-2" id="tabBtnFastDesk">
                        <i class="bi bi-lightning-charge-fill me-1"></i> ⚡ کاریگر فاسٹ ڈیسک (Worker Fast Desk)
                    </button>
                    <button type="button" class="btn <?= (!empty($preselectedMode) || !empty($_POST)) ? 'btn-primary' : 'btn-outline-secondary' ?> fw-semibold px-3 py-2" id="tabBtnAdvancedMatrix">
                        <i class="bi bi-sliders me-1"></i> 📋 تفصیلی میٹرکس فارم (Advanced Matrix Form)
                    </button>
                </div>
                <div class="text-muted small">
                    <span class="badge bg-success-subtle text-success me-1">Real-time</span>
                    ورکر اور آپریشن منتخب کر کے تمام لاٹس کی پروڈکشن انٹری رو وائز محفوظ کریں
                </div>
            </div>
        </div>

        <!-- 1. WORKER-CENTRIC FAST PRODUCTION DESK (DEFAULT PRIMARY VIEW) -->
        <div id="fastDeskContainer" class="<?= (!empty($preselectedMode) || !empty($_POST)) ? 'd-none' : '' ?>">
            <!-- TOP FILTER BAR -->
            <div class="card-modern shadow-sm mb-4 border-0">
                <div class="card-modern-header py-2 px-3 bg-light border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-person-lines-fill text-white fs-6"></i></span>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">کاریگر اور پروڈکشن فلٹرز (Worker Production Desk)</h6>
                            <span class="text-muted small" style="font-size: 0.75rem;">ورکر سلیکٹ کریں، اس کے اسائنڈ آپریشنز اور ایکٹیو لاٹس خودکار لوڈ ہو جائیں گی</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnDeskReload" title="ریفریش شیٹ">
                            <i class="bi bi-arrow-clockwise me-1"></i> ریفریش (Refresh)
                        </button>
                    </div>
                </div>
                <div class="card-modern-body p-3">
                    <div class="row g-2 align-items-end">
                        <!-- Filter 1: Worker (Primary) -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold small text-dark mb-1">
                                <i class="bi bi-person-fill text-primary me-1"></i> کاریگر منتخب کریں (Worker) *
                            </label>
                            <select id="deskWorkerSelect" class="form-select form-select-sm fw-bold border-primary shadow-sm">
                                <option value="">-- تمام فعال کاریگر (All Workers) --</option>
                                <?php foreach ($workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" data-dept="<?= $w['department_id'] ?>" data-rate="<?= $w['default_rate'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>" data-type="<?= clean($w['worker_type']) ?>">
                                        <?= clean($w['name']) ?> (<?= clean($w['code']) ?>) — <?= clean($w['department_name'] ?: 'عام') ?> [<?= clean($w['worker_type']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter 2: Workflow Step / Operation -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold small text-dark mb-1">
                                <i class="bi bi-gear-wide-connected text-secondary me-1"></i> مرحلہ / آپریشن (Operation)
                            </label>
                            <select id="deskOpFilter" class="form-select form-select-sm fw-semibold">
                                <option value="">-- تمام متعلقہ آپریشنز (All Operations) --</option>
                                <?php foreach ($operationsList as $op): ?>
                                    <option value="<?= $op['id'] ?>" data-dept="<?= $op['department_id'] ?>" data-rate="<?= $op['default_rate'] ?>" data-mins="<?= $op['estimated_minutes'] ?>" <?= ($preselectedOpId === $op['id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['department_name'] ?: 'Dept') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter 3: Lot Filter -->
                        <div class="col-12 col-md-2">
                            <label class="form-label fw-bold small text-dark mb-1">
                                <i class="bi bi-stack text-secondary me-1"></i> لاٹ فلٹر (Lot #)
                            </label>
                            <select id="deskLotFilter" class="form-select form-select-sm fw-semibold">
                                <option value="">-- تمام ایکٹیو لاٹس (All Lots) --</option>
                                <?php foreach ($lots as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id']) ? 'selected' : '' ?>>
                                        LOT #<?= clean($l['lot_number']) ?> (<?= $l['total_pieces'] ?> pcs)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter 4: Entry Date -->
                        <div class="col-6 col-md-2">
                            <label class="form-label fw-bold small text-dark mb-1">
                                <i class="bi bi-calendar-event text-secondary me-1"></i> تاریخ (Date)
                            </label>
                            <input type="date" id="deskEntryDate" class="form-control form-control-sm fw-semibold" value="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- Filter 5: Shift -->
                        <div class="col-6 col-md-2">
                            <label class="form-label fw-bold small text-dark mb-1">
                                <i class="bi bi-sun text-secondary me-1"></i> شفٹ (Shift)
                            </label>
                            <select id="deskShiftSelect" class="form-select form-select-sm fw-semibold">
                                <option value="Day Shift" selected>Day Shift (دن)</option>
                                <option value="Night Shift">Night Shift (رات)</option>
                                <option value="Single Shift">Single Shift (سنگل)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Second row of Filter: Search & Status summary -->
                    <div class="row g-2 mt-2 pt-2 border-top align-items-center">
                        <div class="col-12 col-md-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="deskQuickSearch" class="form-control form-control-sm border-start-0" placeholder="فوری تلاش (Lot #, Design, Product, Operation)...">
                            </div>
                        </div>
                        <div class="col-12 col-md-8 text-md-end">
                            <div class="d-flex flex-wrap align-items-center justify-content-md-end gap-2">
                                <span class="badge bg-light text-dark border px-2 py-1" id="deskWorkerInfoBadge">
                                    <i class="bi bi-person me-1"></i> کاریگر: <strong>تمام کاریگر</strong>
                                </span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" id="deskLotsCountBadge">
                                    <i class="bi bi-boxes me-1"></i> <strong>0</strong> لاٹس
                                </span>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1" id="deskPendingCountBadge">
                                    <i class="bi bi-hourglass-split me-1"></i> <strong>0</strong> پینڈنگ مراحل
                                </span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" id="deskSavedCountBadge">
                                    <i class="bi bi-check-circle-fill me-1"></i> <strong>0</strong> محفوظ شدہ انٹریز
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NOTIFICATION / TOAST ALERT CONTAINER -->
            <div id="fastDeskAlertContainer" style="position: sticky; top: 10px; z-index: 1050;"></div>

            <!-- MAIN ACTIVE LOTS SHEET CONTAINER -->
            <div id="fastDeskSheet">
                <!-- Rendered dynamically by loadFastDeskSheet() -->
            </div>
        </div>

        <!-- 2. ADVANCED FORM CONTAINER (ORIGINAL MULTI-MODE FORM) -->
        <div id="advancedFormContainer" class="<?= (!empty($preselectedMode) || !empty($_POST)) ? '' : 'd-none' ?>">
        <form method="POST" action="" id="prodEntryForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="entry_mode" id="pe_entry_mode" value="operator_initial">

            <!-- ROW 1: TOP CONTROL BAR & COMPACT RECENT SUBMISSIONS (SIDE BY SIDE AT TOP) -->
            <div class="row g-3 mb-3 align-items-stretch" style="position: relative; z-index: 20;">
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
                    <div class="card-modern shadow-sm h-100 d-flex flex-column" style="max-height: 240px;">
                        <div class="card-modern-header py-2 px-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark small" id="lblRecentSubHeader">
                                    <i class="bi bi-clock-history text-primary me-1"></i> Recent Entries: <span id="lblRecentLotNum" class="text-primary font-monospace"><?= $recentLot ? '#' . clean($recentLot['lot_number']) : 'Select Lot' ?></span>
                                </h6>
                                <span class="text-muted" style="font-size: 0.72rem;" id="lblRecentSubSubtitle">Entries recorded for this lot</span>
                            </div>
                            <a id="btnRecentFullLog" href="<?= BASE_URL ?>modules/production/history.php<?= $recentLot ? '?lot_id=' . $recentLot['id'] : '' ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;">Full Log</a>
                        </div>
                        <div class="card-modern-body p-0 flex-grow-1 overflow-auto" style="max-height: 185px;">
                            <table class="table table-sm table-modern align-middle mb-0" style="font-size: 0.78rem;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Lot / Op</th>
                                        <th>Worker</th>
                                        <th class="text-center">Good</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody id="recentSubmissionsTbody">
                                    <?php if (empty($recentEntries)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted" id="recentEmptyRow"><i class="bi bi-inbox d-block fs-5 text-secondary opacity-50 mb-1"></i>No entries yet for <?= $recentLot ? 'Lot #' . clean($recentLot['lot_number']) : 'selected lot' ?>.</td></tr>
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
                                                    <?php if (!empty($re['rework_qty']) && $re['rework_qty'] > 0): ?>
                                                        <div class="text-warning small" style="font-size: 0.68rem;"><?= $re['rework_qty'] ?> rew</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($re['labour_amount']) ?>
                                                    <div>
                                                        <a href="<?= BASE_URL ?>modules/production/edit.php?id=<?= $re['id'] ?>" class="btn btn-xs btn-outline-primary py-0 px-2 mt-1" style="font-size: 0.7rem;" title="Edit Entry">
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
                                                    <?php foreach ($workers as $w): 
                                                        $wMin = (float)($w['rate_per_minute'] ?? 2.50);
                                                        $wPiece = (float)($w['default_rate'] ?? 0.00);
                                                        $wType = $w['worker_type'] ?? 'Minute Rate';
                                                        $wBasis = $w['rate_basis'] ?? 'element_wise';
                                                        $rateTxt = format_worker_wage_label($w);
                                                    ?>
                                                        <option value="<?= $w['id'] ?>" data-minrate="<?= $wMin ?>" data-min-rate="<?= $wMin ?>" data-piecerate="<?= $wPiece ?>" data-piece-rate="<?= $wPiece ?>" data-dailyrate="<?= $wPiece ?>" data-daily-rate="<?= $wPiece ?>" data-rate="<?= $wPiece ?>" data-workertype="<?= clean($wType) ?>" data-worker-type="<?= clean($wType) ?>" data-ratebasis="<?= clean($wBasis) ?>" data-rate-basis="<?= clean($wBasis) ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>) <?= $rateTxt ?></option>
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
                                                            <?php foreach ($workers as $w): 
                                                                $wMin = (float)($w['rate_per_minute'] ?? 2.50);
                                                                $wPiece = (float)($w['default_rate'] ?? 0.00);
                                                                $wType = $w['worker_type'] ?? 'Minute Rate';
                                                                $wBasis = $w['rate_basis'] ?? 'element_wise';
                                                                $rateTxt = format_worker_wage_label($w);
                                                            ?>
                                                                <option value="<?= $w['id'] ?>" data-minrate="<?= $wMin ?>" data-min-rate="<?= $wMin ?>" data-piecerate="<?= $wPiece ?>" data-piece-rate="<?= $wPiece ?>" data-dailyrate="<?= $wPiece ?>" data-daily-rate="<?= $wPiece ?>" data-rate="<?= $wPiece ?>" data-workertype="<?= clean($wType) ?>" data-worker-type="<?= clean($wType) ?>" data-ratebasis="<?= clean($wBasis) ?>" data-rate-basis="<?= clean($wBasis) ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>) <?= $rateTxt ?></option>
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
                                                            <?php foreach ($workers as $w): 
                                                                $wMin = (float)($w['rate_per_minute'] ?? 2.50);
                                                                $wPiece = (float)($w['default_rate'] ?? 0.00);
                                                                $wType = $w['worker_type'] ?? 'Minute Rate';
                                                                $wBasis = $w['rate_basis'] ?? 'element_wise';
                                                                $rateTxt = format_worker_wage_label($w);
                                                            ?>
                                                                <option value="<?= $w['id'] ?>" data-minrate="<?= $wMin ?>" data-min-rate="<?= $wMin ?>" data-piecerate="<?= $wPiece ?>" data-piece-rate="<?= $wPiece ?>" data-dailyrate="<?= $wPiece ?>" data-daily-rate="<?= $wPiece ?>" data-rate="<?= $wPiece ?>" data-workertype="<?= clean($wType) ?>" data-worker-type="<?= clean($wType) ?>" data-ratebasis="<?= clean($wBasis) ?>" data-rate-basis="<?= clean($wBasis) ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>) <?= $rateTxt ?></option>
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
                                                <?php foreach ($cuttingWorkers as $w): 
                                                    $cPieceRate = (float)($w['default_rate'] ?? 0.00);
                                                    $cMinRate = (float)($w['rate_per_minute'] ?? 2.50);
                                                    $cType = $w['worker_type'] ?? 'Piece Rate';
                                                    $cBasis = $w['rate_basis'] ?? 'whole_sweater';
                                                    $rateTxt = format_worker_wage_label($w);
                                                ?>
                                                    <option value="<?= $w['id'] ?>" data-minrate="<?= $cMinRate ?>" data-min-rate="<?= $cMinRate ?>" data-piecerate="<?= $cPieceRate ?>" data-piece-rate="<?= $cPieceRate ?>" data-dailyrate="<?= $cPieceRate ?>" data-daily-rate="<?= $cPieceRate ?>" data-rate="<?= $cPieceRate ?>" data-workertype="<?= clean($cType) ?>" data-worker-type="<?= clean($cType) ?>" data-ratebasis="<?= clean($cBasis) ?>" data-rate-basis="<?= clean($cBasis) ?>" <?= (stripos($w['name'], 'Cut') !== false ? 'selected' : '') ?>>
                                                        <?= clean($w['name']) ?> (<?= clean($w['code']) ?>) <?= $rateTxt ?>
                                                    </option>
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

                                    <!-- Real-time Rate & Wage Calculation Scheme for Cutting -->
                                    <div class="p-3 bg-white border rounded-2 mb-3 shadow-sm" id="cuttingRateSchemeBox">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label small fw-bold text-dark mb-0">
                                                <i class="bi bi-calculator text-primary me-1"></i> Rate & Wage Scheme for Specific Lot (ریئل ٹائم ریٹ و اجرت کا تعین)
                                            </label>
                                            <span class="badge bg-light text-muted border small" id="lblCutFormula">Minutes × Rate/min = Piece Rate</span>
                                        </div>
                                        <div class="row g-3" id="cuttingRateRow">
                                            <div class="col-12 col-md-4" id="grpCutMinutes">
                                                <label class="form-label small fw-semibold text-muted mb-1">وقت فی پیس (SAM Minutes)</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="cutting_minutes" id="cut_minutes" class="form-control fw-bold text-center text-primary" step="any" min="0" value="2.0">
                                                    <span class="input-group-text px-2 small text-muted">min</span>
                                                </div>
                                                <div class="form-text small" style="font-size: 10px;">لاٹ کٹنگ کا معیاری وقت</div>
                                            </div>
                                            <div class="col-12 col-md-4" id="grpCutMinRate">
                                                <label class="form-label small fw-semibold text-muted mb-1">کاریگر ریٹ فی منٹ (Rs/min)</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text px-2 small text-muted">Rs</span>
                                                    <input type="number" name="cutting_min_rate" id="cut_min_rate" class="form-control fw-bold text-center text-success" step="any" min="0" value="2.50">
                                                    <span class="input-group-text px-2 small text-muted">/m</span>
                                                </div>
                                                <div class="form-text small" style="font-size: 10px;">ماسٹر پروفائل ریٹ فی منٹ</div>
                                            </div>
                                            <div class="col-12 col-md-4" id="grpCutFinalRate">
                                                <label class="form-label small fw-semibold text-muted mb-1" id="lblCutRate">فائنل ریٹ (Rate)</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text px-2 small text-muted">Rs</span>
                                                    <input type="number" name="rate" id="cut_rate" class="form-control fw-bold text-end text-dark" step="any" min="0" value="5.00">
                                                    <span class="input-group-text px-2 small text-muted" id="unitCutRate">/pc</span>
                                                </div>
                                                <div class="form-text small" id="hintCutRate" style="font-size: 10px;">ریئل ٹائم ریٹ (آپ تبدیل کر سکتے ہیں)</div>
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
                                <div class="col-12 col-md-5" id="grpDownstreamWorkerCol">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0">Worker / Operator *</label>
                                        <span id="workerCountBadge" class="badge bg-light text-secondary border small d-none">0 matching</span>
                                    </div>
                                    <select name="worker_id" id="pe_worker_id" class="form-select fw-semibold">
                                        <option value="">-- Select Operation First --</option>
                                    </select>
                                </div>

                                <!-- Destination Warehouse (Shown dynamically when operation is Packing) -->
                                <div class="col-12 col-md-3 d-none" id="grpPackingWarehouse">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-bold text-success mb-0">
                                            <i class="bi bi-building me-1"></i> Deposit Warehouse *
                                        </label>
                                    </div>
                                    <select name="packing_warehouse_id" id="pe_packing_wh_id" class="form-select fw-semibold border-success">
                                        <?php foreach ($finishedWarehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>" <?= (stripos($wh['name'], 'Main') !== false ? 'selected' : '') ?>>
                                                📦 <?= clean($wh['name']) ?> <?= !empty($wh['location']) ? ' (' . clean($wh['location']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small text-success" style="font-size: 10px;">فنش گودام جہاں اسٹاک جمع ہوگا</div>
                                </div>

                                <div class="col-12 col-md-7" id="grpDownstreamRateSchemeCol">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0">Rate Calculation Scheme (ریٹ کا حساب)</label>
                                        <span class="text-success small fw-bold" id="lblFormulaBadge">
                                            <i class="bi bi-calculator me-1"></i>Minutes × Rate/min = Piece Rate
                                        </span>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-4" id="grpDownstreamMinutes">
                                            <div class="input-group">
                                                <input type="number" name="minutes_per_piece" id="pe_minutes" class="form-control fw-bold text-center text-primary" step="any" min="0" value="0.0" placeholder="SAM Min" title="Operation Standard Time (SAM Minutes per piece)">
                                                <span class="input-group-text px-1 small text-muted">min</span>
                                            </div>
                                            <div class="form-text text-center small" style="font-size: 10px;">وقت فی پیس</div>
                                        </div>
                                        <div class="col-4" id="grpDownstreamMinRate">
                                            <div class="input-group">
                                                <span class="input-group-text px-1 small text-muted">Rs</span>
                                                <input type="number" name="worker_minute_rate" id="pe_min_rate" class="form-control fw-bold text-center text-success" step="any" min="0" value="2.50" placeholder="Rs/min" title="Worker's agreed rate per minute">
                                                <span class="input-group-text px-1 small text-muted">/m</span>
                                            </div>
                                            <div class="form-text text-center small" style="font-size: 10px;">ریٹ فی منٹ</div>
                                        </div>
                                        <div class="col-4" id="grpDownstreamRate">
                                            <div class="input-group">
                                                <span class="input-group-text px-1 small text-muted">Rs</span>
                                                <input type="number" name="rate" id="pe_rate" class="form-control fw-bold text-end text-dark" step="any" min="0" value="0.00" title="Final Rate">
                                                <span class="input-group-text px-1 small text-muted" id="unitDownstreamRate">/pc</span>
                                            </div>
                                            <div class="form-text text-center small" id="lblDownstreamRateType" style="font-size: 10px;">ریٹ (Rate)</div>
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
                                            <div>🎯 ہدف (Planned): <strong id="lblPlanned" class="text-dark">0</strong></div>
                                            <div>✂️ موصولہ تیار (Ready): <strong id="lblAllowable" class="text-primary">0</strong></div>
                                            <div id="boxPrevDefects" class="d-flex flex-wrap gap-1">
                                                <span class="badge bg-danger text-white" id="badgePrevRej">🔴 0 خراب</span>
                                                <span class="badge bg-warning text-dark" id="badgePrevRew">🟡 0 ری ورک</span>
                                                <span class="badge bg-secondary text-white" id="badgePrevUnproduced">⏳ 0 غیر تیار</span>
                                            </div>
                                            <div>✅ مکمل (Good Done): <strong id="lblDone" class="text-success">0</strong></div>
                                            <div>⚡ بقایا (Pending): <strong id="lblPending" class="text-danger fw-bold">0</strong></div>
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
        </div> <!-- /#advancedFormContainer -->
    </div>

<script>
const BASE_URL = window.BASE_URL || '<?= BASE_URL ?>';
document.addEventListener('DOMContentLoaded', function() {
    // =========================================================================
    // FAST WORKER PRODUCTION DESK CONTROLLER
    // =========================================================================
    const fastDeskContainer = document.getElementById('fastDeskContainer');
    const advancedFormContainer = document.getElementById('advancedFormContainer');
    const tabBtnFastDesk = document.getElementById('tabBtnFastDesk');
    const tabBtnAdvancedMatrix = document.getElementById('tabBtnAdvancedMatrix');

    const deskWorkerSelect = document.getElementById('deskWorkerSelect');
    const deskOpFilter = document.getElementById('deskOpFilter');
    const deskLotFilter = document.getElementById('deskLotFilter');
    const deskEntryDate = document.getElementById('deskEntryDate');
    const deskShiftSelect = document.getElementById('deskShiftSelect');
    const deskQuickSearch = document.getElementById('deskQuickSearch');
    const btnDeskReload = document.getElementById('btnDeskReload');

    const fastDeskSheet = document.getElementById('fastDeskSheet');
    const fastDeskAlertContainer = document.getElementById('fastDeskAlertContainer');
    const deskWorkerInfoBadge = document.getElementById('deskWorkerInfoBadge');
    const deskLotsCountBadge = document.getElementById('deskLotsCountBadge');
    const deskPendingCountBadge = document.getElementById('deskPendingCountBadge');
    const deskSavedCountBadge = document.getElementById('deskSavedCountBadge');

    let savedEntriesCount = 0;
    let fastSheetCachedData = null;

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showDeskToast(type, title, message) {
        if (!fastDeskAlertContainer) return;
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show shadow-sm d-flex align-items-center justify-content-between p-2 px-3 mb-3`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger'} fs-5"></i>
                <div>
                    <strong class="text-dark">${escapeHtml(title)}</strong>
                    ${message ? `<div class="small text-muted">${message}</div>` : ''}
                </div>
            </div>
            <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        `;
        fastDeskAlertContainer.prepend(alertDiv);
        setTimeout(() => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 250);
        }, 7000);
    }

    function switchTopTab(tab) {
        if (tab === 'advanced') {
            if (fastDeskContainer) fastDeskContainer.classList.add('d-none');
            if (advancedFormContainer) advancedFormContainer.classList.remove('d-none');
            if (tabBtnFastDesk) {
                tabBtnFastDesk.className = 'btn btn-outline-secondary fw-semibold px-3 py-2';
            }
            if (tabBtnAdvancedMatrix) {
                tabBtnAdvancedMatrix.className = 'btn btn-primary fw-bold px-3 py-2';
            }
        } else {
            if (fastDeskContainer) fastDeskContainer.classList.remove('d-none');
            if (advancedFormContainer) advancedFormContainer.classList.add('d-none');
            if (tabBtnFastDesk) {
                tabBtnFastDesk.className = 'btn btn-primary fw-bold px-3 py-2';
            }
            if (tabBtnAdvancedMatrix) {
                tabBtnAdvancedMatrix.className = 'btn btn-outline-secondary fw-semibold px-3 py-2';
            }
            loadFastDeskSheet();
        }
    }

    if (tabBtnFastDesk) tabBtnFastDesk.addEventListener('click', () => switchTopTab('fast_desk'));
    if (tabBtnAdvancedMatrix) tabBtnAdvancedMatrix.addEventListener('click', () => switchTopTab('advanced'));

    async function loadFastDeskSheet() {
        if (!fastDeskSheet) return;
        const workerId = deskWorkerSelect ? deskWorkerSelect.value : '';
        const opId = deskOpFilter ? deskOpFilter.value : '';
        const lotId = deskLotFilter ? deskLotFilter.value : '';

        fastDeskSheet.innerHTML = `
            <div class="card-modern shadow-sm text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-dark fw-bold">لاٹس اور آپریشنز لوڈ ہو رہے ہیں...</div>
                <div class="text-muted small">براہ کرم انتظار کریں</div>
            </div>
        `;

        try {
            const url = `${BASE_URL}modules/production/ajax.php?action=get_fast_sheet_data&worker_id=${encodeURIComponent(workerId)}&operation_id=${encodeURIComponent(opId)}&lot_id=${encodeURIComponent(lotId)}`;
            const res = await fetch(url);
            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                throw new Error(rawText.substring(0, 150) || 'سرور سے نامکمل جواب موصول ہوا');
            }

            if (data.status !== 'success') {
                fastDeskSheet.innerHTML = `
                    <div class="alert alert-danger py-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> ${escapeHtml(data.message || 'Error loading sheet data')}
                    </div>
                `;
                return;
            }

            fastSheetCachedData = data;
            renderFastDeskSheet(data);
        } catch (err) {
            fastDeskSheet.innerHTML = `
                <div class="alert alert-danger py-3">
                    <i class="bi bi-exclamation-circle me-2"></i> نیٹ ورک یا سرور کنکشن میں خرابی: ${escapeHtml(err.message)}
                </div>
            `;
        }
    }

    function renderFastDeskSheet(data) {
        const worker = data.worker;
        const assignedOps = data.assigned_operation_ids || [];
        const allWorkers = data.all_workers || [];
        const lots = data.sheet || [];

        // Update summary badges
        if (deskWorkerInfoBadge) {
            if (worker) {
                deskWorkerInfoBadge.innerHTML = `<i class="bi bi-person-check-fill text-success me-1"></i> کاریگر: <strong>${escapeHtml(worker.name)}</strong> (${escapeHtml(worker.department_name || 'عام')}) [${escapeHtml(worker.worker_type)}]`;
            } else {
                deskWorkerInfoBadge.innerHTML = `<i class="bi bi-people me-1"></i> کاریگر: <strong>تمام کاریگر (All Workers)</strong>`;
            }
        }

        let totalPendingStages = 0;
        lots.forEach(l => {
            totalPendingStages += (l.operations || []).filter(o => o.pending_balance > 0).length;
        });

        if (deskLotsCountBadge) {
            deskLotsCountBadge.innerHTML = `<i class="bi bi-boxes me-1"></i> <strong>${lots.length}</strong> لاٹس`;
        }
        if (deskPendingCountBadge) {
            deskPendingCountBadge.innerHTML = `<i class="bi bi-hourglass-split me-1"></i> <strong>${totalPendingStages}</strong> باقی مراحل`;
        }

        if (lots.length === 0) {
            fastDeskSheet.innerHTML = `
                <div class="card-modern shadow-sm text-center py-5">
                    <i class="bi bi-check-circle-fill text-success fs-1 mb-2"></i>
                    <h5 class="fw-bold text-dark">کوئی پینڈنگ کام نہیں ملا</h5>
                    <p class="text-muted small mb-0">منتخب کردہ فلٹرز کے لیے تمام کام مکمل ہے یا کوئی فعال لاٹ موجود نہیں ہے۔</p>
                </div>
            `;
            return;
        }

        const selectedWorkerId = deskWorkerSelect ? parseInt(deskWorkerSelect.value, 10) : 0;

        let html = '';
        lots.forEach(lot => {
            const ops = lot.operations || [];
            if (ops.length === 0) return;

            html += `
            <div class="card-modern mb-4 shadow-sm lot-block" data-lot-id="${lot.lot_id}">
                <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 px-3 bg-light border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fs-5 fw-bold text-primary">LOT #${escapeHtml(lot.lot_number)}</span>
                        <span class="badge bg-white text-dark border fw-semibold">${escapeHtml(lot.product_name)}</span>
                        ${lot.design_number ? `<span class="badge bg-primary-subtle text-primary border border-primary-subtle">Design #${escapeHtml(lot.design_number)}</span>` : ''}
                        <span class="badge bg-secondary-subtle text-secondary">${escapeHtml(lot.single_size_name || 'Standard Size')}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-dark fs-6 px-2 py-1">ٹوٹل لاٹ: ${lot.total_pieces} pcs</span>
                        <span class="badge ${lot.lot_status === 'In Production' ? 'bg-primary' : 'bg-secondary'}">${escapeHtml(lot.lot_status)}</span>
                        <a href="${BASE_URL}modules/lots/view.php?id=${lot.lot_id}" target="_blank" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;">
                            <i class="bi bi-box-arrow-up-right me-1"></i> لاٹ فائل
                        </a>
                    </div>
                </div>
                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr class="small text-muted text-uppercase" style="font-size: 0.75rem;">
                                    <th style="width: 45px;" class="text-center">ترتیب</th>
                                    <th>مرحلہ / آپریشن (Operation)</th>
                                    <th style="min-width: 160px;">کاریگر (Worker)</th>
                                    <th style="width: 100px;">شفٹ</th>
                                    <th class="text-center" style="width: 85px;">ٹوٹل لاٹ</th>
                                    <th class="text-center" style="width: 85px;">پہلے شدہ</th>
                                    <th class="text-center" style="width: 85px;">باقی پینڈنگ</th>
                                    <th class="text-center" style="min-width: 130px;">آج کے پیس (Good Pcs)</th>
                                    <th class="text-center" style="width: 110px;">خراب / ری ورک</th>
                                    <th class="text-end" style="min-width: 120px;">ریٹ اور اجرت</th>
                                    <th class="text-center" style="width: 125px;">محفوظ کریں</th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            ops.forEach(op => {
                const isCompleted = (op.pending_balance <= 0);
                const currentShiftVal = deskShiftSelect ? deskShiftSelect.value : 'Day Shift';

                let workerOptionsHtml = `<option value="">-- کاریگر منتخب کریں --</option>`;
                allWorkers.forEach(w => {
                    const isSelected = selectedWorkerId > 0 ? (w.id === selectedWorkerId) : (worker && w.id === worker.id);
                    workerOptionsHtml += `<option value="${w.id}" data-rate="${w.default_rate}" data-minrate="${w.rate_per_minute}" ${isSelected ? 'selected' : ''}>${escapeHtml(w.name)} (${escapeHtml(w.code)}) - ${escapeHtml(w.department_name || '')}</option>`;
                });

                const initialLabour = (op.pending_balance * op.default_rate).toFixed(2);

                html += `
                <tr class="op-row ${isCompleted ? 'table-success bg-opacity-25' : ''}" 
                    data-lot-id="${lot.lot_id}" 
                    data-op-id="${op.operation_id}" 
                    data-pending="${op.pending_balance}" 
                    data-total="${op.required_qty}"
                    data-search-text="${escapeHtml(lot.lot_number + ' ' + lot.product_name + ' ' + (lot.design_number || '') + ' ' + op.op_name + ' ' + (op.dept_name || '')).toLowerCase()}">
                    <td class="text-center fw-bold text-muted">${op.sequence}</td>
                    <td>
                        <div class="fw-bold text-dark">${escapeHtml(op.op_name)}</div>
                        <span class="badge bg-light text-secondary border small" style="font-size: 0.7rem;">${escapeHtml(op.dept_name || 'عام')}</span>
                        ${(op.is_operator_or_cutting && op.elements && op.elements.length > 0) ? `
                            <button type="button" class="btn btn-link btn-xs p-0 text-decoration-none text-primary fw-semibold toggle-elements-btn ms-1" style="font-size: 0.72rem;" data-target="elem-drawer-${lot.lot_id}-${op.operation_id}">
                                <i class="bi bi-puzzle me-1"></i>اعضاء و پلے (${op.elements.length})
                            </button>
                        ` : ''}
                    </td>
                    <td>
                        <select class="form-select form-select-sm row-worker-select fw-semibold" style="font-size: 0.78rem;" ${isCompleted ? 'disabled' : ''}>
                            ${workerOptionsHtml}
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm row-shift-select" style="font-size: 0.78rem;" ${isCompleted ? 'disabled' : ''}>
                            <option value="Day Shift" ${currentShiftVal === 'Day Shift' ? 'selected' : ''}>Day (دن)</option>
                            <option value="Night Shift" ${currentShiftVal === 'Night Shift' ? 'selected' : ''}>Night (رات)</option>
                        </select>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-secondary">${op.required_qty}</span>
                        <div class="text-muted" style="font-size: 0.65rem;">ٹوٹل اتنے ہیں</div>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-success fs-6 cell-good-done">${op.good_done}</span>
                        <div class="text-muted" style="font-size: 0.65rem;">پہلے شدہ</div>
                    </td>
                    <td class="text-center">
                        <span class="badge ${op.pending_balance > 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success'} fs-6 cell-pending-bal">${op.pending_balance}</span>
                        <div class="text-muted" style="font-size: 0.65rem;">باقی پینڈنگ</div>
                    </td>
                    <td class="text-center">
                        <div class="input-group input-group-sm mx-auto" style="max-width: 105px;">
                            <input type="number" class="form-control form-control-sm text-center fw-bold input-good-qty text-primary fs-6" 
                                   value="${op.pending_balance}" min="0" max="${op.pending_balance}" step="1" 
                                   ${isCompleted ? 'disabled' : ''}>
                        </div>
                        <span class="text-muted d-block validation-hint" style="font-size: 0.65rem;">(میکس: <span class="max-bal-text">${op.pending_balance}</span>)</span>
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <input type="number" class="form-control form-control-sm px-1 text-center input-rej text-danger fw-semibold" placeholder="خراب" title="خراب پیسز (Rejections)" value="0" min="0" style="width: 48px; font-size: 0.75rem;" ${isCompleted ? 'disabled' : ''}>
                            <input type="number" class="form-control form-control-sm px-1 text-center input-rew text-warning-emphasis fw-semibold" placeholder="ری ورک" title="ری ورک پیسز (Rework)" value="0" min="0" style="width: 48px; font-size: 0.75rem;" ${isCompleted ? 'disabled' : ''}>
                        </div>
                    </td>
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-1 mb-1">
                            <span class="text-muted small" style="font-size: 0.68rem;">Rs.</span>
                            <input type="number" step="0.25" class="form-control form-control-sm px-1 text-end fw-bold input-rate" value="${op.default_rate.toFixed(2)}" style="width: 65px; font-size: 0.78rem;" ${isCompleted ? 'disabled' : ''}>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-bold preview-labour-badge" style="font-size: 0.75rem;">
                            Rs. ${initialLabour}
                        </span>
                    </td>
                    <td class="text-center">
                        ${isCompleted ? `
                            <span class="badge bg-success text-white py-1 px-2"><i class="bi bi-check-circle-fill me-1"></i> مکمل</span>
                        ` : `
                            <button type="button" class="btn btn-success btn-sm fw-bold px-2 py-1 btn-save-fast-row shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> محفوظ کریں
                            </button>
                        `}
                    </td>
                </tr>
                `;

                // Elements Sub-Row
                if (op.is_operator_or_cutting && op.elements && op.elements.length > 0) {
                    html += `
                    <tr class="table-light elements-drawer-row d-none" id="elem-drawer-${lot.lot_id}-${op.operation_id}">
                        <td colspan="11" class="py-2 px-3 border-bottom">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="badge bg-primary text-white fw-bold"><i class="bi bi-puzzle me-1"></i> اعضاء اور پلے (Elements Breakdown):</span>
                                ${op.elements.map(el => `
                                    <div class="badge bg-white text-dark border p-1 px-2 text-start" style="font-size: 0.75rem;">
                                        <div><strong>${escapeHtml(el.element_name)}</strong>: 
                                            <span class="text-primary fw-bold el-calc-pcs" data-mult="${el.multiplier}">${Math.ceil(op.pending_balance * el.multiplier)} pcs</span>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.68rem;">
                                            (کل مطلوب: ${el.required_pieces} | ہو چکے: ${el.done_pieces} | باقی: ${el.pending_pieces})
                                            ${el.pieces_per_palla > 1 ? ` &bull; <em>${el.pieces_per_palla} pcs/پلہ</em>` : ''}
                                            ${el.rate_per_palla > 0 ? ` &bull; <strong class="text-success">Rs. ${el.rate_per_palla}/پلہ</strong>` : ''}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </td>
                    </tr>
                    `;
                }
            });

            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            `;
        });

        fastDeskSheet.innerHTML = html;
        attachFastDeskRowEvents();
    }

    function attachFastDeskRowEvents() {
        if (!fastDeskSheet) return;

        // 1. Toggle Elements Drawer
        fastDeskSheet.querySelectorAll('.toggle-elements-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const drawer = document.getElementById(targetId);
                if (drawer) {
                    drawer.classList.toggle('d-none');
                }
            });
        });

        // 2. Row Calculations & Validation (Good Qty, Rate, Defects)
        fastDeskSheet.querySelectorAll('.op-row').forEach(row => {
            const lotId = row.getAttribute('data-lot-id');
            const opId = row.getAttribute('data-op-id');
            const inpGood = row.querySelector('.input-good-qty');
            const inpRate = row.querySelector('.input-rate');
            const previewLabour = row.querySelector('.preview-labour-badge');
            const validationHint = row.querySelector('.validation-hint');
            const saveBtn = row.querySelector('.btn-save-fast-row');
            const workerSelect = row.querySelector('.row-worker-select');
            const drawerRow = document.getElementById(`elem-drawer-${lotId}-${opId}`);

            function updateRowCalculations() {
                if (!inpGood || !inpRate) return;
                const maxVal = parseInt(row.getAttribute('data-pending'), 10) || 0;
                let goodVal = parseInt(inpGood.value, 10);
                if (isNaN(goodVal)) goodVal = 0;

                const rateVal = parseFloat(inpRate.value) || 0.00;

                // Capacity check: Cannot exceed pending balance
                if (goodVal > maxVal) {
                    inpGood.classList.add('is-invalid');
                    inpGood.classList.add('border-danger');
                    if (validationHint) {
                        validationHint.innerHTML = `<span class="text-danger fw-bold">⚠️ زیادہ نہیں ہو سکتے! باقی صرف ${maxVal} ہیں</span>`;
                    }
                    if (saveBtn) saveBtn.disabled = true;
                } else {
                    inpGood.classList.remove('is-invalid');
                    inpGood.classList.remove('border-danger');
                    if (validationHint) {
                        validationHint.innerHTML = `(میکس: <span class="max-bal-text">${maxVal}</span>)`;
                    }
                    if (saveBtn) saveBtn.disabled = false;
                }

                // Update labour preview
                if (previewLabour) {
                    const totalLabour = (Math.max(0, goodVal) * rateVal).toFixed(2);
                    previewLabour.textContent = `Rs. ${totalLabour}`;
                }

                // Update element drawer numbers
                if (drawerRow) {
                    drawerRow.querySelectorAll('.el-calc-pcs').forEach(elSpan => {
                        const mult = parseFloat(elSpan.getAttribute('data-mult')) || 1.0;
                        const calcPcs = Math.ceil(Math.max(0, goodVal) * mult);
                        elSpan.textContent = `${calcPcs} pcs`;
                    });
                }
            }

            if (inpGood) {
                inpGood.addEventListener('input', updateRowCalculations);
            }
            if (inpRate) {
                inpRate.addEventListener('input', updateRowCalculations);
            }

            // Worker change in row
            if (workerSelect) {
                workerSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    if (opt) {
                        const optRate = parseFloat(opt.getAttribute('data-rate')) || 0;
                        if (optRate > 0 && inpRate) {
                            inpRate.value = optRate.toFixed(2);
                            updateRowCalculations();
                        }
                    }
                });
            }

            // Save Row Entry Button
            if (saveBtn) {
                saveBtn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const workerId = workerSelect ? parseInt(workerSelect.value, 10) : 0;
                    if (!workerId) {
                        alert('براہ کرم کاریگر منتخب کریں (Please select a worker)');
                        if (workerSelect) workerSelect.focus();
                        return;
                    }

                    const maxVal = parseInt(row.getAttribute('data-pending'), 10) || 0;
                    const goodVal = parseInt(inpGood ? inpGood.value : 0, 10) || 0;
                    const rejInp = row.querySelector('.input-rej');
                    const rewInp = row.querySelector('.input-rew');
                    const rejVal = parseInt(rejInp ? rejInp.value : 0, 10) || 0;
                    const rewVal = parseInt(rewInp ? rewInp.value : 0, 10) || 0;
                    const rateVal = parseFloat(inpRate ? inpRate.value : 0) || 0.00;
                    const shiftVal = row.querySelector('.row-shift-select') ? row.querySelector('.row-shift-select').value : 'Day Shift';
                    const entryDateVal = deskEntryDate ? deskEntryDate.value : '';

                    if (goodVal > maxVal) {
                        alert(`تعداد باقی پینڈنگ پیسز (${maxVal}) سے زیادہ نہیں ہو سکتی!`);
                        if (inpGood) inpGood.focus();
                        return;
                    }

                    if (goodVal <= 0 && rejVal <= 0 && rewVal <= 0) {
                        alert('براہ کرم کم از کم 1 پیس درج کریں');
                        if (inpGood) inpGood.focus();
                        return;
                    }

                    // Disable button and show spinner
                    const originalBtnContent = saveBtn.innerHTML;
                    saveBtn.disabled = true;
                    saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status"></span> محفوظ ہو رہا ہے...`;

                    try {
                        const formData = new FormData();
                        formData.append('action', 'save_fast_row_entry');
                        formData.append('lot_id', lotId);
                        formData.append('operation_id', opId);
                        formData.append('worker_id', workerId);
                        formData.append('shift', shiftVal);
                        formData.append('good_qty', goodVal);
                        formData.append('rejected_qty', rejVal);
                        formData.append('rework_qty', rewVal);
                        formData.append('rate', rateVal);
                        formData.append('entry_date', entryDateVal);

                        const response = await fetch(`${BASE_URL}modules/production/ajax.php`, {
                            method: 'POST',
                            body: formData
                        });
                        const rawText = await response.text();
                        let res;
                        try {
                            res = JSON.parse(rawText);
                        } catch (parseErr) {
                            throw new Error(rawText.substring(0, 150) || 'سرور سے نامکمل یا غیر متوقع جواب موصول ہوا');
                        }

                        if (res.status === 'success') {
                            savedEntriesCount++;
                            if (deskSavedCountBadge) {
                                deskSavedCountBadge.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> <strong>${savedEntriesCount}</strong> محفوظ شدہ انٹریز`;
                            }

                            // Update row indicators
                            const cellGood = row.querySelector('.cell-good-done');
                            const cellPending = row.querySelector('.cell-pending-bal');
                            if (cellGood) cellGood.textContent = res.new_good_done;
                            if (cellPending) {
                                cellPending.textContent = res.new_pending;
                                if (res.new_pending === 0) {
                                    cellPending.className = 'badge bg-success-subtle text-success fs-6 cell-pending-bal';
                                }
                            }

                            row.setAttribute('data-pending', res.new_pending);

                            if (res.new_pending === 0) {
                                row.classList.add('table-success', 'bg-opacity-25');
                                if (inpGood) inpGood.disabled = true;
                                if (rejInp) rejInp.disabled = true;
                                if (rewInp) rewInp.disabled = true;
                                if (inpRate) inpRate.disabled = true;
                                if (workerSelect) workerSelect.disabled = true;

                                saveBtn.outerHTML = `<span class="badge bg-success text-white py-1 px-2"><i class="bi bi-check-circle-fill me-1"></i> مکمل</span>`;
                            } else {
                                if (inpGood) {
                                    inpGood.value = res.new_pending;
                                    inpGood.setAttribute('max', res.new_pending);
                                }
                                if (rejInp) rejInp.value = 0;
                                if (rewInp) rewInp.value = 0;
                                updateRowCalculations();

                                saveBtn.disabled = false;
                                saveBtn.className = 'btn btn-outline-success btn-sm fw-bold px-2 py-1 btn-save-fast-row shadow-sm';
                                saveBtn.innerHTML = `<i class="bi bi-check-all me-1"></i> محفوظ شد! اگلا درج کریں`;
                                setTimeout(() => {
                                    saveBtn.className = 'btn btn-success btn-sm fw-bold px-2 py-1 btn-save-fast-row shadow-sm';
                                    saveBtn.innerHTML = `<i class="bi bi-check2-circle me-1"></i> محفوظ کریں`;
                                }, 3000);
                            }

                            showDeskToast('success', res.message, `اجرت: <strong>${res.labour_formatted}</strong> | انٹری نمبر: <code>${res.entry_number}</code>`);
                        } else {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = originalBtnContent;
                            showDeskToast('danger', 'انٹری محفوظ نہ ہو سکی', res.message || 'Error occurred');
                        }
                    } catch (err) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = originalBtnContent;
                        showDeskToast('danger', 'کنکشن کی خرابی', err.message);
                    }
                });
            }
        });
    }

    // 3. Quick Search Filter
    if (deskQuickSearch) {
        deskQuickSearch.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            if (!fastDeskSheet) return;

            fastDeskSheet.querySelectorAll('.lot-block').forEach(lotBlock => {
                let anyVisibleInLot = false;
                lotBlock.querySelectorAll('.op-row').forEach(row => {
                    const text = row.getAttribute('data-search-text') || '';
                    if (!query || text.includes(query)) {
                        row.classList.remove('d-none');
                        anyVisibleInLot = true;
                    } else {
                        row.classList.add('d-none');
                    }
                });

                if (anyVisibleInLot) {
                    lotBlock.classList.remove('d-none');
                } else {
                    lotBlock.classList.add('d-none');
                }
            });
        });
    }

    // 4. Filters Change Listeners
    if (deskWorkerSelect) {
        deskWorkerSelect.addEventListener('change', function() {
            const selectedOpt = this.options[this.selectedIndex];
            const workerDept = selectedOpt ? selectedOpt.getAttribute('data-dept') : null;

            // Auto-highlight worker's operations in operation filter
            if (deskOpFilter && workerDept) {
                Array.from(deskOpFilter.options).forEach(opt => {
                    if (!opt.value) return;
                    const opDept = opt.getAttribute('data-dept');
                    if (opDept === workerDept) {
                        opt.style.fontWeight = 'bold';
                        opt.style.color = '#0d6efd';
                    } else {
                        opt.style.fontWeight = 'normal';
                        opt.style.color = '';
                    }
                });
            }
            loadFastDeskSheet();
        });
    }

    if (deskOpFilter) deskOpFilter.addEventListener('change', loadFastDeskSheet);
    if (deskLotFilter) deskLotFilter.addEventListener('change', loadFastDeskSheet);
    if (deskShiftSelect) {
        deskShiftSelect.addEventListener('change', function() {
            const newShift = this.value;
            if (fastDeskSheet) {
                fastDeskSheet.querySelectorAll('.row-shift-select').forEach(sel => {
                    sel.value = newShift;
                });
            }
        });
    }
    if (btnDeskReload) btnDeskReload.addEventListener('click', loadFastDeskSheet);

    // Initial Load: If preselectedMode or POST was present, open advanced tab; otherwise open Fast Desk
    const initialModeVal = <?= json_encode(!empty($preselectedMode) ? clean($preselectedMode) : (!empty($_POST) ? 'advanced' : 'fast_desk')) ?>;
    if (initialModeVal === 'advanced' || initialModeVal === 'matrix' || initialModeVal === 'operator_initial' || initialModeVal === 'cutting' || initialModeVal === 'single') {
        switchTopTab('advanced');
    } else {
        switchTopTab('fast_desk');
    }

    // =========================================================================
    // LEGACY / ADVANCED MATRIX FORM CONTROLLER
    // =========================================================================
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

    function getWorkerWageInfo(opt) {
        if (!opt || !opt.value) {
            return {
                workerType: 'Piece Rate',
                minRate: 2.50,
                pieceRate: 0.00,
                dailyRate: 0.00,
                rateBasis: 'whole_sweater',
                rate: 0.00,
                isDaily: false,
                isMonthly: false,
                isMinute: false,
                isPiece: true
            };
        }
        const rawType = (opt.dataset.workerType || opt.dataset.workertype || opt.getAttribute('data-worker-type') || opt.getAttribute('data-workertype') || 'Piece Rate').trim();
        const minR = parseFloat(opt.dataset.minRate || opt.dataset.minrate || opt.getAttribute('data-min-rate') || opt.getAttribute('data-minrate')) || 2.50;
        const pieceR = parseFloat(opt.dataset.pieceRate || opt.dataset.piecerate || opt.getAttribute('data-piece-rate') || opt.getAttribute('data-piecerate')) || 0.00;
        const dailyR = parseFloat(opt.dataset.dailyRate || opt.dataset.dailyrate || opt.getAttribute('data-daily-rate') || opt.getAttribute('data-dailyrate')) || pieceR || 0.00;
        const rBasis = opt.dataset.rateBasis || opt.dataset.ratebasis || opt.getAttribute('data-rate-basis') || opt.getAttribute('data-ratebasis') || 'whole_sweater';
        const effR = parseFloat(opt.dataset.rate || opt.getAttribute('data-rate')) || 0.00;

        const lower = rawType.toLowerCase();
        const isDaily = lower.includes('daily') || lower.includes('یومیہ') || lower.includes('day');
        const isMonthly = lower.includes('month') || lower.includes('ماہانہ') || lower.includes('salary');
        const isMinute = lower.includes('minute') || lower.includes('منٹ');
        const isPiece = !isDaily && !isMonthly && !isMinute;

        return {
            workerType: rawType,
            minRate: minR,
            pieceRate: pieceR,
            dailyRate: dailyR,
            rateBasis: rBasis,
            rate: effR,
            isDaily,
            isMonthly,
            isMinute,
            isPiece
        };
    }

    function getMaxWholeReadyToCut() {
        if (!currentOpLotData || !currentOpLotData.elements || currentOpLotData.elements.length === 0) {
            return (currentOpLotData && currentOpLotData.max_whole_ready_to_cut !== undefined)
                ? parseInt(currentOpLotData.max_whole_ready_to_cut, 10)
                : 0;
        }
        let minWhole = null;
        currentOpLotData.elements.forEach(el => {
            const mult = parseFloat(el.quantity || el.multiplier || 1) || 1;
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
            updateCuttingRate(false);

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
            const mult = parseFloat(el.quantity || el.multiplier || 1) || 1;
            const req = parseInt(el.required_pieces) || 0;
            const done = parseInt(el.operator_total || el.operator1_pieces || 0);
            const pending = Math.max(0, req - done);
            const sam = parseFloat(el.worker_custom_sam !== null && el.worker_custom_sam !== undefined && el.worker_custom_sam !== '' ? el.worker_custom_sam : el.default_sam_minutes) || 0;
            const ppp = parseFloat(el.pieces_per_palla || 1) || 1;
            const rpp = parseFloat(el.rate_per_palla || 0) || 0;
            let pieceRate = opDefRate;
            if (el.worker_custom_rate !== null && el.worker_custom_rate !== undefined && el.worker_custom_rate !== '') {
                pieceRate = parseFloat(el.worker_custom_rate) || 0;
            } else if (rpp > 0 && ppp > 0) {
                pieceRate = rpp / ppp;
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
                        ${ppp > 1 ? `<span class="badge bg-light text-primary border ms-1" style="font-size: 0.70rem;">1 پلہ = ${ppp} پیس</span>` : ''}
                    </td>
                    <td class="fw-bold text-muted">
                        ${req}
                        ${ppp > 1 ? `<div class="text-muted" style="font-size: 0.70rem;">${(req / ppp).toFixed(1)} پلے</div>` : ''}
                    </td>
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
                            <input type="number" name="elements[${el.id}][sam_minutes]" class="form-control form-control-sm text-center fw-bold text-primary op-el-sam" value="${sam.toFixed(1)}" step="any" min="0">
                            <span class="input-group-text px-1 small text-muted">m</span>
                        </div>
                    </td>
                    <td class="text-muted small op-el-minrate">
                        Rs. ${workerMinRate.toFixed(2)}/m
                    </td>
                    <td style="width: 115px;">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text px-1 small text-muted">Rs</span>
                            <input type="number" name="elements[${el.id}][rate]" class="form-control form-control-sm text-center fw-bold text-dark op-el-rate" value="${pieceRate.toFixed(2)}" step="any" min="0">
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
            const mult = parseFloat(el.quantity || el.multiplier || 1) || 1;
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
        const opt = (cutWorker && cutWorker.selectedIndex >= 0) ? cutWorker.options[cutWorker.selectedIndex] : null;
        const schemeBox = document.getElementById('cuttingRateSchemeBox');
        const wInfo = getWorkerWageInfo(opt);

        const grpMinutes = document.getElementById('grpCutMinutes');
        const grpMinRate = document.getElementById('grpCutMinRate');
        const grpFinalRate = document.getElementById('grpCutFinalRate');
        const lblCutRate = document.getElementById('lblCutRate');
        const unitCutRate = document.getElementById('unitCutRate');
        const hintCutRate = document.getElementById('hintCutRate');
        const lblFormula = document.getElementById('lblCutFormula');

        // Always show scheme box so user can view & edit rates in real-time
        if (schemeBox) schemeBox.classList.remove('d-none');

        const whole = parseInt(cutWholePcs ? cutWholePcs.value : 0) || 0;

        if (wInfo.isDaily) {
            if (grpMinutes) grpMinutes.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpFinalRate) grpFinalRate.className = 'col-12 col-md-6';
            if (lblCutRate) lblCutRate.textContent = 'Daily Wage Rate (یومیہ اجرت فی دن / شفٹ) *';
            if (unitCutRate) unitCutRate.textContent = '/day';
            if (hintCutRate) hintCutRate.textContent = 'ورکر پروفائل کی طے شدہ یومیہ اجرت، آپ ریل ٹائم میں تبدیل کر سکتے ہیں۔';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-calendar-check me-1"></i>Daily Wage Scheme (یومیہ اجرت - فکسڈ فی شفٹ)';

            if (!fromDirectRate && cutRateInput) {
                cutRateInput.value = (wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00).toFixed(2);
            }
            const currentRate = parseFloat(cutRateInput ? cutRateInput.value : 0) || (wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00);
            if (cutRateBadge) {
                cutRateBadge.className = 'badge bg-info-subtle text-info-emphasis border';
                cutRateBadge.innerHTML = `<i class="bi bi-calendar-check me-1"></i>Rate: Rs. ${currentRate.toFixed(2)}/day (Daily Wage — یومیہ اجرت)`;
            }
            if (lblCutLabour) {
                lblCutLabour.textContent = 'Rs. ' + currentRate.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        } else if (wInfo.isPiece) {
            if (grpMinutes) grpMinutes.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpFinalRate) grpFinalRate.className = 'col-12 col-md-6';
            if (lblCutRate) lblCutRate.textContent = 'Piece Rate (ریٹ فی پیس) *';
            if (unitCutRate) unitCutRate.textContent = '/pc';
            if (hintCutRate) hintCutRate.textContent = 'ورکر پروفائل سے طے شدہ ریٹ، آپ ریل ٹائم میں تبدیل کر سکتے ہیں۔';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-tag-fill me-1"></i>Piece Rate Scheme (طے شدہ فی پیس ریٹ)';

            if (!fromDirectRate && cutRateInput) {
                cutRateInput.value = (wInfo.pieceRate > 0 ? wInfo.pieceRate : (wInfo.rate > 0 ? wInfo.rate : 5.00)).toFixed(2);
            }
            const currentRate = parseFloat(cutRateInput ? cutRateInput.value : 0) || (wInfo.pieceRate > 0 ? wInfo.pieceRate : 5.00);
            if (cutRateBadge) {
                cutRateBadge.className = 'badge bg-primary-subtle text-primary border';
                cutRateBadge.innerHTML = `<i class="bi bi-tag-fill me-1"></i>Rate: Rs. ${currentRate.toFixed(2)}/pc (Piece Rate — پر پیس ریٹ)`;
            }
            if (lblCutLabour) {
                lblCutLabour.textContent = 'Rs. ' + (whole * currentRate).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        } else if (wInfo.isMonthly) {
            if (grpMinutes) grpMinutes.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpFinalRate) grpFinalRate.className = 'col-12 col-md-6';
            if (lblCutRate) lblCutRate.textContent = 'Monthly Fixed Salary (ماہانہ تنخواہ) *';
            if (unitCutRate) unitCutRate.textContent = '/mo';
            if (hintCutRate) hintCutRate.textContent = 'ماہانہ فکسڈ بنیادی تنخواہ۔';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-briefcase me-1"></i>Monthly Salary (ماہانہ تنخواہ)';

            if (!fromDirectRate && cutRateInput) {
                cutRateInput.value = (wInfo.pieceRate > 0 ? wInfo.pieceRate : 0.00).toFixed(2);
            }
            const currentRate = parseFloat(cutRateInput ? cutRateInput.value : 0);
            if (cutRateBadge) {
                cutRateBadge.className = 'badge bg-secondary-subtle text-secondary border';
                cutRateBadge.innerHTML = `<i class="bi bi-briefcase me-1"></i>Salary: Rs. ${currentRate.toFixed(2)}/mo (Monthly Salary)`;
            }
            if (lblCutLabour) {
                lblCutLabour.textContent = 'Rs. 0.00 (Fixed Monthly Salary)';
            }
        } else {
            // Minute Rate
            if (grpMinutes) grpMinutes.classList.remove('d-none');
            if (grpMinRate) grpMinRate.classList.remove('d-none');
            if (grpFinalRate) grpFinalRate.className = 'col-12 col-md-4';
            if (lblCutRate) lblCutRate.textContent = 'فائنل پیس ریٹ (Piece Rate) *';
            if (unitCutRate) unitCutRate.textContent = '/pc';
            if (hintCutRate) hintCutRate.textContent = 'ریئل ٹائم خودکار حساب (SAM × Rate/min)';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-calculator me-1"></i>Minutes × Rate/min = Piece Rate';

            if (cutMinRateInput && !cutMinRateInput.dataset.userEdited) {
                cutMinRateInput.value = wInfo.minRate.toFixed(2);
            }
            const mins = parseFloat(cutMinutesInput ? cutMinutesInput.value : 0) || 2.0;
            const minRate = parseFloat(cutMinRateInput ? cutMinRateInput.value : 0) || wInfo.minRate;

            if (!fromDirectRate && cutRateInput) {
                cutRateInput.value = (mins * minRate).toFixed(2);
            }
            const currentRate = parseFloat(cutRateInput ? cutRateInput.value : 0) || (mins * minRate);
            if (cutRateBadge) {
                cutRateBadge.className = 'badge bg-success-subtle text-success border';
                cutRateBadge.innerHTML = `<i class="bi bi-stopwatch me-1"></i>Rate: Rs. ${currentRate.toFixed(2)}/pc (${mins.toFixed(1)}m @ Rs. ${minRate.toFixed(2)}/m — منٹ ریٹ)`;
            }
            if (lblCutLabour) {
                lblCutLabour.textContent = 'Rs. ' + (whole * currentRate).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        }
    }

    if (cutWorker) {
        cutWorker.addEventListener('change', function() {
            if (cutMinRateInput) delete cutMinRateInput.dataset.userEdited;
            updateCuttingRate(false);
        });
        updateCuttingRate(false);
    }

    if (cutMinutesInput) cutMinutesInput.addEventListener('input', () => {
        cutMinutesInput.dataset.userEdited = '1';
        updateCuttingRate(false);
    });
    if (cutMinRateInput) cutMinRateInput.addEventListener('input', () => {
        cutMinRateInput.dataset.userEdited = '1';
        updateCuttingRate(false);
    });
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
                    <td class="p-2 border" data-planned="${planned}">
                        <div class="small fw-semibold text-muted mb-1">Plan: <strong>${planned}</strong> pcs</div>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text px-1 bg-success-subtle text-success fw-bold small" title="Good Cut Pieces">🟢</span>
                            <input type="number" name="matrix[${c.id}][${sz.id}][good]" class="form-control form-control-sm text-center fw-bold cut-cell-good text-success" data-planned="${planned}" value="${planned}" min="0" placeholder="0">
                        </div>
                        <div class="d-flex gap-1 mb-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1 bg-danger-subtle text-danger small" title="Rejected / Defect Cut Pieces">🔴</span>
                                <input type="number" name="matrix[${c.id}][${sz.id}][rejected]" class="form-control form-control-sm text-center cut-cell-rej text-danger fw-semibold" value="0" min="0" placeholder="0">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1 bg-warning-subtle text-warning-emphasis small" title="Rework Pieces">🟡</span>
                                <input type="number" name="matrix[${c.id}][${sz.id}][rework]" class="form-control form-control-sm text-center cut-cell-rew text-warning fw-semibold" value="0" min="0" placeholder="0">
                            </div>
                        </div>
                        <div class="cut-status-line small text-muted text-center" style="font-size: 10px;"></div>
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

        // Attach real-time interdependent adjustment listeners
        cutMatrixTbody.querySelectorAll('td[data-planned]').forEach(td => {
            const goodInp = td.querySelector('.cut-cell-good');
            const rejInp = td.querySelector('.cut-cell-rej');
            const rewInp = td.querySelector('.cut-cell-rew');
            const plan = parseInt(td.dataset.planned) || 0;

            function updateStatusLine() {
                const g = parseInt(goodInp ? goodInp.value : 0) || 0;
                const r = parseInt(rejInp ? rejInp.value : 0) || 0;
                const w = parseInt(rewInp ? rewInp.value : 0) || 0;
                const unmade = Math.max(0, plan - (g + r + w));
                const line = td.querySelector('.cut-status-line');
                if (line) {
                    if (r > 0 || w > 0 || unmade > 0) {
                        line.innerHTML = `
                            ${r > 0 ? `<span class="badge bg-danger-subtle text-danger px-1">🔴 ${r} خراب</span> ` : ''}
                            ${w > 0 ? `<span class="badge bg-warning-subtle text-warning-emphasis px-1">🟡 ${w} ری ورک</span> ` : ''}
                            ${unmade > 0 ? `<span class="badge bg-secondary-subtle text-secondary px-1">⏳ ${unmade} غیر کٹ</span>` : ''}
                        `;
                    } else {
                        line.innerHTML = `<span class="text-success small">✓ مکمل کٹنگ</span>`;
                    }
                }
            }

            function onCuttingDefectChanged() {
                const rej = parseInt(rejInp ? rejInp.value : 0) || 0;
                const rew = parseInt(rewInp ? rewInp.value : 0) || 0;
                if (goodInp) {
                    goodInp.value = Math.max(0, plan - rej - rew);
                }
                updateStatusLine();
                recalculateCuttingMatrixTotals();
            }

            if (rejInp) rejInp.addEventListener('input', onCuttingDefectChanged);
            if (rewInp) rewInp.addEventListener('input', onCuttingDefectChanged);
            if (goodInp) goodInp.addEventListener('input', () => {
                updateStatusLine();
                recalculateCuttingMatrixTotals();
            });

            updateStatusLine();
        });

        // Run initial calculation to sync all totals immediately
        recalculateCuttingMatrixTotals();

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
            updateCuttingRate(true);
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
        const opt = (workerSelect && workerSelect.selectedIndex >= 0) ? workerSelect.options[workerSelect.selectedIndex] : null;
        const wInfo = getWorkerWageInfo(opt);
        const mins = parseFloat(minInput ? minInput.value : 0) || 0;
        const minRate = parseFloat(minRateInput ? minRateInput.value : 0) || wInfo.minRate;

        const grpMin = document.getElementById('grpDownstreamMinutes');
        const grpMinRate = document.getElementById('grpDownstreamMinRate');
        const grpRate = document.getElementById('grpDownstreamRate');
        const lblFormula = document.getElementById('lblFormulaBadge');
        const unitRate = document.getElementById('unitDownstreamRate');
        const lblRateType = document.getElementById('lblDownstreamRateType');
        const rateBreakdownContainer = document.getElementById('rateBreakdownText');

        // Always allow editing rate in real time!
        if (rateInput) rateInput.readOnly = false;

        if (wInfo.isDaily) {
            if (grpMin) grpMin.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpRate) grpRate.className = 'col-12';
            if (unitRate) unitRate.textContent = '/day';
            if (lblRateType) lblRateType.textContent = 'یومیہ اجرت (Daily Wage)';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-calendar-check me-1"></i>Daily Wage Scheme (یومیہ اجرت فی دن)';

            if (!fromDirectRate && rateInput) {
                rateInput.value = (wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00).toFixed(2);
            }
            const currentRate = parseFloat(rateInput ? rateInput.value : 0) || (wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00);

            if (rateBreakdownContainer) {
                rateBreakdownContainer.innerHTML = `<span class="badge bg-info-subtle text-info-emphasis px-2 py-1"><i class="bi bi-calendar-check me-1"></i>یومیہ اجرت: Rs. ${currentRate.toFixed(2)} / دن (Daily Wage) — آپ ریل ٹائم میں تبدیل کر سکتے ہیں</span>`;
            }
        } else if (wInfo.isPiece) {
            if (grpMin) grpMin.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpRate) grpRate.className = 'col-12';
            if (unitRate) unitRate.textContent = '/pc';
            if (lblRateType) lblRateType.textContent = 'پیس ریٹ (Piece Rate)';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-tag-fill me-1"></i>Piece Rate Scheme (طے شدہ پیس ریٹ)';

            if (!fromDirectRate && rateInput) {
                rateInput.value = (wInfo.pieceRate > 0 ? wInfo.pieceRate : (wInfo.rate > 0 ? wInfo.rate : 5.00)).toFixed(2);
            }
            const currentRate = parseFloat(rateInput ? rateInput.value : 0) || (wInfo.pieceRate > 0 ? wInfo.pieceRate : 5.00);

            if (rateBreakdownContainer) {
                rateBreakdownContainer.innerHTML = `<span class="badge bg-primary px-2 py-1"><i class="bi bi-tag-fill me-1"></i>طے شدہ پیس ریٹ: Rs. ${currentRate.toFixed(2)} / پیس (Piece Rate) — آپ ریل ٹائم میں تبدیل کر سکتے ہیں</span>`;
            }
        } else if (wInfo.isMonthly) {
            if (grpMin) grpMin.classList.add('d-none');
            if (grpMinRate) grpMinRate.classList.add('d-none');
            if (grpRate) grpRate.className = 'col-12';
            if (unitRate) unitRate.textContent = '/mo';
            if (lblRateType) lblRateType.textContent = 'ماہانہ تنخواہ (Fixed Salary)';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-briefcase me-1"></i>Monthly Salary (ماہانہ تنخواہ)';

            if (!fromDirectRate && rateInput) {
                rateInput.value = (wInfo.pieceRate > 0 ? wInfo.pieceRate : 0.00).toFixed(2);
            }
            const currentRate = parseFloat(rateInput ? rateInput.value : 0);
            if (rateBreakdownContainer) {
                rateBreakdownContainer.innerHTML = `<span class="badge bg-secondary-subtle text-secondary px-2 py-1"><i class="bi bi-briefcase me-1"></i>ماہانہ تنخواہ: Rs. ${currentRate.toFixed(2)} / ماہ (Monthly Fixed Salary)</span>`;
            }
        } else {
            // Minute Rate
            if (grpMin) grpMin.classList.remove('d-none');
            if (grpMinRate) grpMinRate.classList.remove('d-none');
            if (grpRate) grpRate.className = 'col-4';
            if (unitRate) unitRate.textContent = '/pc';
            if (lblRateType) lblRateType.textContent = 'پیس ریٹ (Piece Rate)';
            if (lblFormula) lblFormula.innerHTML = '<i class="bi bi-calculator me-1"></i>Minutes × Rate/min = Piece Rate';

            if (!fromDirectRate && rateInput) {
                if (mins > 0 && minRate > 0) {
                    rateInput.value = (mins * minRate).toFixed(2);
                }
            }
            const currentRate = parseFloat(rateInput ? rateInput.value : 0) || (mins * minRate);
            if (rateBreakdownContainer) {
                if (mins > 0) {
                    rateBreakdownContainer.innerHTML = `<span class="fw-bold text-primary">${mins.toFixed(1)}</span> منٹ فی پیس &times; <span class="fw-bold text-success">Rs. ${minRate.toFixed(2)}</span> فی منٹ = <strong class="text-dark fs-6">Rs. ${currentRate.toFixed(2)}</strong> فی پیس (Minute Rate)`;
                } else {
                    rateBreakdownContainer.innerHTML = `<span class="badge bg-primary px-2 py-1"><i class="bi bi-tag-fill me-1"></i>طے شدہ ریٹ: Rs. ${currentRate.toFixed(2)} / پیس</span>`;
                }
            }
        }

        const currentRate = parseFloat(rateInput ? rateInput.value : 0) || 0;
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
        const rej = parseInt(rejInput.value) || 0;
        const rew = parseInt(rewInput.value) || 0;
        const rec = parseInt(recInput.value) || (parseInt(document.getElementById('lblPending')?.textContent) || 0);

        // If user changed rej or rew, auto-adjust good produced: rec - rej - rew
        if (document.activeElement === rejInput || document.activeElement === rewInput || document.activeElement === recInput) {
            goodInput.value = Math.max(0, rec - rej - rew);
        }

        const opt = (workerSelect && workerSelect.selectedIndex >= 0) ? workerSelect.options[workerSelect.selectedIndex] : null;
        const wInfo = getWorkerWageInfo(opt);
        const good = parseInt(goodInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;

        let total = good * rate;
        if (wInfo.isDaily) {
            total = rate;
        } else if (wInfo.isMonthly) {
            total = 0.00;
        }
        labourDisplay.textContent = 'Rs. ' + total.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        if (!recInput.value || parseInt(recInput.value) === 0) {
            recInput.placeholder = good + rej + rew;
        }
    }

    goodInput.addEventListener('input', calculateSingleLabour);
    rejInput.addEventListener('input', calculateSingleLabour);
    rewInput.addEventListener('input', calculateSingleLabour);
    if (recInput) recInput.addEventListener('input', calculateSingleLabour);
    rateInput.addEventListener('input', function() {
        updateRateCalculation(true);
    });

    // Function to dynamically update Recent Submissions panel for the selected lot
    function updateRecentSubmissionsForLot(lotId, lotNumber) {
        const lblLotNum = document.getElementById('lblRecentLotNum');
        const fullLogBtn = document.getElementById('btnRecentFullLog');
        const tbody = document.getElementById('recentSubmissionsTbody');
        if (lblLotNum) lblLotNum.textContent = lotNumber ? '#' + lotNumber : '---';
        if (fullLogBtn) fullLogBtn.href = '<?= BASE_URL ?>modules/production/history.php?lot_id=' + lotId;
        if (!tbody || !lotId) return;

        fetch('<?= BASE_URL ?>modules/production/ajax.php?action=get_lot_recent_entries&lot_id=' + lotId)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success' && res.entries) {
                    if (res.entries.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-inbox d-block fs-5 text-secondary opacity-50 mb-1"></i>No entries yet for Lot #${res.lot.lot_number}.</td></tr>`;
                    } else {
                        let html = '';
                        res.entries.forEach(e => {
                            let defectsHtml = '';
                            if (e.rejected_qty > 0) {
                                defectsHtml += `<div class="text-danger small" style="font-size: 0.68rem;">${e.rejected_qty} rej</div>`;
                            }
                            if (e.rework_qty > 0) {
                                defectsHtml += `<div class="text-warning small" style="font-size: 0.68rem;">${e.rework_qty} rew</div>`;
                            }
                            const varText = e.color_name ? `${e.color_name} (${e.size_name || ''})` : `<span class="text-secondary opacity-75">Batch / All</span>`;
                            html += `
                                <tr>
                                    <td>
                                        <span class="fw-bold text-primary">#${e.lot_number}</span>
                                        <div>${e.op_name}</div>
                                        <div class="text-muted" style="font-size: 0.7rem;">${varText}</div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark">${e.worker_name}</span>
                                        <div class="text-muted" style="font-size: 0.7rem;">${e.entry_date}</div>
                                    </td>
                                    <td class="text-center fw-bold text-success">
                                        ${e.good_qty.toLocaleString()}
                                        ${defectsHtml}
                                    </td>
                                    <td class="text-end fw-bold text-dark">
                                        ${e.labour_amount}
                                        <div>
                                            <a href="<?= BASE_URL ?>modules/production/edit.php?id=${e.id}" class="btn btn-xs btn-outline-primary py-0 px-2 mt-1" style="font-size: 0.7rem;" title="Edit Entry">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }
                }
            })
            .catch(err => console.error('Failed to load lot recent entries:', err));
    }

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

                    // Auto-update Recent Submissions panel for this specific lot
                    updateRecentSubmissionsForLot(lotId, lot.lot_number);

                    // Auto-select assigned operators on this lot
                    if (lot.operator_id) {
                        if (opSingleWorker && (!opSingleWorker.value || opSingleWorker.value === '')) {
                            opSingleWorker.value = lot.operator_id;
                        }
                        if (opDoubleDayWorker && (!opDoubleDayWorker.value || opDoubleDayWorker.value === '')) {
                            opDoubleDayWorker.value = lot.operator_id;
                        }
                    }
                    if (lot.night_operator_id && opDoubleNightWorker && (!opDoubleNightWorker.value || opDoubleNightWorker.value === '')) {
                        opDoubleNightWorker.value = lot.night_operator_id;
                    }

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
                        opt.dataset.rateUnit = op.rate_unit || 'Per piece';
                        opt.dataset.description = op.description || '';
                        opt.dataset.minutes = op.estimated_minutes || 0;
                        opt.dataset.mins = op.estimated_minutes || 0;
                        opt.dataset.goodDone = op.good_done || 0;
                        const goodDone = parseInt(op.good_done) || 0;
                        const lotTotal = parseInt(data.lot.total_pieces) || 0;
                        const isCompleted = (goodDone >= lotTotal && lotTotal > 0);
                        const isSub = op.parent_id ? true : false;
                        const prefix = isSub ? '  ↳ ' : '';
                        const minInfo = (parseFloat(op.estimated_minutes) > 0 ? ' (' + parseFloat(op.estimated_minutes) + ' min)' : '');
                        const descInfo = op.description ? ' (' + op.description + ')' : '';
                        const unitInfo = op.rate_unit ? ' / ' + op.rate_unit : '';
                        opt.textContent = prefix + op.sequence + '. ' + op.op_name + descInfo + minInfo + ' [' + (op.dept_name || 'General') + '] - Rs. ' + parseFloat(op.default_rate).toFixed(2) + unitInfo + 
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
                        updateCuttingRate(false);
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
        const selectedOpt = this.options[this.selectedIndex];
        const match = selectedOpt ? selectedOpt.textContent.match(/LOT #([^—\s]+)/) : null;
        const lotNum = match ? match[1] : '';
        updateRecentSubmissionsForLot(this.value, lotNum);
        loadLotData(this.value);
        matrixPlaceholder.classList.remove('d-none');
        matrixLoading.classList.add('d-none');
        matrixGridWrapper.classList.add('d-none');
    });

    if (lotSelect.value) {
        const selectedOpt = lotSelect.options[lotSelect.selectedIndex];
        const match = selectedOpt ? selectedOpt.textContent.match(/LOT #([^—\s]+)/) : null;
        const lotNum = match ? match[1] : '';
        updateRecentSubmissionsForLot(lotSelect.value, lotNum);
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
                        opt.dataset.minrate = w.rate_per_minute || 2.50;
                        opt.dataset.pieceRate = w.default_rate || w.piece_rate || 0;
                        opt.dataset.piecerate = w.default_rate || w.piece_rate || 0;
                        opt.dataset.dailyRate = w.default_rate || 0;
                        opt.dataset.dailyrate = w.default_rate || 0;
                        opt.dataset.workerType = w.worker_type || 'Piece Rate';
                        opt.dataset.workertype = w.worker_type || 'Piece Rate';
                        opt.dataset.rateBasis = w.rate_basis || 'whole_sweater';
                        opt.dataset.ratebasis = w.rate_basis || 'whole_sweater';

                        const minRateTxt = parseFloat(w.rate_per_minute || 2.50).toFixed(2);
                        const pieceRate = parseFloat(w.default_rate || w.piece_rate || 0);
                        const basisBadge = w.rate_basis === 'element_wise' ? ' [اعضاء]' : ' [سویٹر]';
                        const isMinuteRate = (w.worker_type === 'Minute Rate');

                        let rateLabel = '';
                        if (w.worker_type === 'Daily Wage') {
                            rateLabel = ' — Rs. ' + pieceRate.toFixed(2) + '/day (Daily Wage)';
                        } else if (isMinuteRate) {
                            rateLabel = ' — Rs. ' + minRateTxt + '/m (Minute Rate)';
                        } else if (w.worker_type === 'Monthly Salary') {
                            rateLabel = ' — Rs. ' + pieceRate.toFixed(2) + '/mo (Monthly Salary)';
                        } else if (w.worker_type === 'Contractor' || w.worker_type === 'Contract') {
                            rateLabel = ' — Rs. ' + pieceRate.toFixed(2) + '/pc (Contractor)';
                        } else if (pieceRate > 0) {
                            rateLabel = ' — Rs. ' + pieceRate.toFixed(2) + '/pc (Piece Rate)';
                        } else if (parseFloat(w.effective_rate) > 0) {
                            rateLabel = ' — Rs. ' + parseFloat(w.effective_rate).toFixed(2) + '/pc';
                        } else {
                            rateLabel = ' — Rs. ' + minRateTxt + '/min';
                        }

                        opt.textContent = w.name + ' (' + w.code + ')' + basisBadge + rateLabel;
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
            const wInfo = getWorkerWageInfo(opt);

            if (workerBadge) {
                if (wInfo.isDaily) {
                    workerBadge.innerHTML = `<i class="bi bi-calendar-check me-1"></i>Daily Wage (Rs. ${wInfo.dailyRate.toFixed(2)}/day)`;
                    workerBadge.className = 'badge bg-info-subtle text-info-emphasis border small';
                } else if (wInfo.isMonthly) {
                    workerBadge.innerHTML = `<i class="bi bi-briefcase me-1"></i>Monthly Salary (Rs. ${wInfo.pieceRate.toFixed(2)}/mo)`;
                    workerBadge.className = 'badge bg-secondary-subtle text-secondary border small';
                } else if (wInfo.isPiece) {
                    const basisBadge = (wInfo.rateBasis === 'element_wise') ? ' [اعضاء]' : ' [سویٹر]';
                    workerBadge.innerHTML = `<i class="bi bi-tag-fill me-1"></i>Piece Rate ${basisBadge} (Rs. ${wInfo.pieceRate.toFixed(2)}/pc)`;
                    workerBadge.className = 'badge bg-primary-subtle text-primary border small';
                } else {
                    workerBadge.innerHTML = `<i class="bi bi-stopwatch me-1"></i>Minute Rate (Rs. ${wInfo.minRate.toFixed(2)}/m)`;
                    workerBadge.className = 'badge bg-success-subtle text-success border small';
                }
                workerBadge.classList.remove('d-none');
            }
            if (minRateInput) {
                minRateInput.value = wInfo.minRate.toFixed(2);
            }
            
            if (wInfo.isDaily) {
                rateInput.value = (wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00).toFixed(2);
                updateRateCalculation(true);
            } else if (wInfo.isPiece) {
                if (wInfo.pieceRate > 0) {
                    rateInput.value = wInfo.pieceRate.toFixed(2);
                } else if (wInfo.rate > 0) {
                    rateInput.value = wInfo.rate.toFixed(2);
                }
                updateRateCalculation(true);
            } else if (wInfo.isMonthly) {
                rateInput.value = wInfo.pieceRate.toFixed(2);
                updateRateCalculation(true);
            } else {
                updateRateCalculation(false);
            }
        }
    });

    // When operation changes, update SAM minutes, load workers, and load matrix grid!
    opSelect.addEventListener('change', function() {
        const selectedText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].textContent.toLowerCase() : '';
        const grpPackingWh = document.getElementById('grpPackingWarehouse');
        const grpWorkerCol = document.getElementById('grpDownstreamWorkerCol');
        const grpSchemeCol = document.getElementById('grpDownstreamRateSchemeCol');
        if (selectedText.includes('pack')) {
            if (grpPackingWh) grpPackingWh.classList.remove('d-none');
            if (grpWorkerCol) grpWorkerCol.className = 'col-12 col-md-4';
            if (grpSchemeCol) grpSchemeCol.className = 'col-12 col-md-5';
        } else {
            if (grpPackingWh) grpPackingWh.classList.add('d-none');
            if (grpWorkerCol) grpWorkerCol.className = 'col-12 col-md-5';
            if (grpSchemeCol) grpSchemeCol.className = 'col-12 col-md-7';
        }

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
                    if (document.getElementById('lblPlanned')) document.getElementById('lblPlanned').textContent = data.planned + ' pcs';
                    if (document.getElementById('lblAllowable')) document.getElementById('lblAllowable').textContent = data.allowable + ' pcs';
                    if (document.getElementById('lblDone')) document.getElementById('lblDone').textContent = data.good_done + ' pcs';
                    if (document.getElementById('lblPending')) document.getElementById('lblPending').textContent = data.pending + ' pcs';

                    const bRej = document.getElementById('badgePrevRej');
                    const bRew = document.getElementById('badgePrevRew');
                    const bUnmade = document.getElementById('badgePrevUnproduced');
                    if (bRej) {
                        bRej.textContent = '🔴 ' + (data.prev_rejected || 0) + ' خراب';
                        bRej.classList.toggle('d-none', (data.prev_rejected || 0) <= 0);
                    }
                    if (bRew) {
                        bRew.textContent = '🟡 ' + (data.prev_rework || 0) + ' ری ورک';
                        bRew.classList.toggle('d-none', (data.prev_rework || 0) <= 0);
                    }
                    if (bUnmade) {
                        bUnmade.textContent = '⏳ ' + (data.prev_unproduced || 0) + ' بنے ہی نہیں';
                        bUnmade.classList.toggle('d-none', (data.prev_unproduced || 0) <= 0);
                    }

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
                        const rejInput = document.getElementById('pe_rejected_qty');
                        const rewInput = document.getElementById('pe_rework_qty');
                        const recInput = document.getElementById('pe_qty_received');
                        const rateInp = document.getElementById('pe_rate');

                        goodInput.disabled = false;
                        goodInput.max = data.pending;
                        goodInput.value = data.pending; // Pre-fill with pending planned pieces!

                        if (recInput) recInput.value = data.pending;
                        if (rejInput) rejInput.value = 0;
                        if (rewInput) rewInput.value = 0;
                        if (rateInp && (!rateInp.value || parseFloat(rateInp.value) === 0)) {
                            rateInp.value = parseFloat(data.default_rate).toFixed(2);
                        }

                        if (btnSaveSingle) {
                            btnSaveSingle.disabled = false;
                            btnSaveSingle.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Single Production Entry';
                            btnSaveSingle.classList.remove('btn-secondary');
                            btnSaveSingle.classList.add('btn-primary');
                        }

                        calculateSingleLabour();
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
        } else if (data.prev_operation_name && (totals.allowable < totals.planned || (data.total_prev_rejected || 0) > 0 || (data.total_prev_rework || 0) > 0 || (data.total_prev_unproduced || 0) > 0)) {
            const prevRej = data.total_prev_rejected || 0;
            const prevRew = data.total_prev_rework || 0;
            const prevUnmade = data.total_prev_unproduced || Math.max(0, totals.planned - (totals.allowable + prevRej + prevRew));

            stageNoticeEl.className = 'alert alert-info d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border border-info rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-arrow-right-circle text-info fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Preceding Stage Reconciliation: ${data.prev_operation_name} (${totals.allowable} Pcs Available)</div>
                        <div class="small text-muted mb-2">
                            سابقہ مرحلہ (<strong>${data.prev_operation_name}</strong>) سے ${totals.allowable} پیس موصول ہوئے۔ پچھلے خراب یا زیرِ اصلاح پیسز اگلے مرحلے میں شامل نہیں ہو سکتے۔
                        </div>
                        <div class="d-flex flex-wrap gap-2 small">
                            <span class="badge bg-white text-dark border">🎯 کل ہدف: <strong>${totals.planned} pcs</strong></span>
                            <span class="badge bg-primary text-white">✂️ موصولہ تیار: <strong>${totals.allowable} pcs</strong></span>
                            ${prevRej > 0 ? `<span class="badge bg-danger text-white">🔴 خراب: <strong>${prevRej} pcs</strong></span>` : ''}
                            ${prevRew > 0 ? `<span class="badge bg-warning text-dark">🟡 ری ورک: <strong>${prevRew} pcs</strong></span>` : ''}
                            ${prevUnmade > 0 ? `<span class="badge bg-secondary text-white">⏳ بنے ہی نہیں: <strong>${prevUnmade} pcs</strong></span>` : ''}
                            <span class="badge bg-success text-white">✅ اس مرحلے میں مکمل: <strong>${totals.good_done} pcs</strong></span>
                            <span class="badge bg-warning-subtle text-warning-emphasis border">⚡ بقایا کام: <strong>${totals.pending} pcs</strong></span>
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
                                           value="${cell.pending}">
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
                                <span class="badge bg-light text-secondary border">Pending: <strong>${cell.pending}</strong>/${effectiveAllowable}</span>
                                ${(cell.prev_rejected > 0 || cell.prev_rework > 0 || cell.prev_unproduced > 0) ? `
                                    <div class="d-flex flex-wrap gap-1 mt-1 justify-content-center" style="font-size: 9px;">
                                        ${cell.prev_rejected > 0 ? `<span class="badge bg-danger text-white px-1" title="پچھلے مرحلے میں خراب پیسز">🔴 ${cell.prev_rejected} Rej</span>` : ''}
                                        ${cell.prev_rework > 0 ? `<span class="badge bg-warning text-dark px-1" title="ری ورک میں موجود">🟡 ${cell.prev_rework} Rew</span>` : ''}
                                        ${cell.prev_unproduced > 0 ? `<span class="badge bg-secondary text-white px-1" title="پچھلے مرحلے میں بنے ہی نہیں">⏳ ${cell.prev_unproduced} Unmade</span>` : ''}
                                    </div>
                                ` : ''}
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

        // Bind interactive events with interdependent adjustment
        document.querySelectorAll('.cell-variant').forEach(cell => {
            const goodInp = cell.querySelector('.cell-good');
            const rejInp = cell.querySelector('.cell-rejected');
            const rewInp = cell.querySelector('.cell-rework');
            const pending = parseInt(cell.dataset.pending) || 0;

            function adjustFastMatrixGood() {
                const rej = parseInt(rejInp ? rejInp.value : 0) || 0;
                const rew = parseInt(rewInp ? rewInp.value : 0) || 0;
                if (goodInp) {
                    goodInp.value = Math.max(0, pending - rej - rew);
                }
                recalculateMatrixTotals();
            }

            if (rejInp) rejInp.addEventListener('input', adjustFastMatrixGood);
            if (rewInp) rewInp.addEventListener('input', adjustFastMatrixGood);
            if (goodInp) goodInp.addEventListener('input', recalculateMatrixTotals);

            [goodInp, rejInp, rewInp].forEach(inp => {
                if (inp) inp.addEventListener('focus', function() { this.select(); });
            });
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

        // Worker Labour is calculated based on wage type
        const mOpt = (workerSelect && workerSelect.selectedIndex >= 0) ? workerSelect.options[workerSelect.selectedIndex] : null;
        const mWInfo = getWorkerWageInfo(mOpt);
        let batchLabour = grandGood * rate;
        if (mWInfo.isDaily) {
            batchLabour = rate;
        } else if (mWInfo.isMonthly) {
            batchLabour = 0.00;
        }
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
                const mult = parseFloat(el.quantity || el.multiplier || 1) || 1;
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
            const wInfo = getWorkerWageInfo(workerOpt);
            let pieceRate = opDefRate;
            if (wInfo.isDaily) {
                pieceRate = wInfo.dailyRate > 0 ? wInfo.dailyRate : 300.00;
            } else if (wInfo.isPiece && wInfo.pieceRate > 0) {
                pieceRate = wInfo.pieceRate;
            } else if (finalMins > 0) {
                pieceRate = finalMins * wInfo.minRate;
            }

            if (opSingleMinutes && !opSingleMinutes.dataset.userEdited) opSingleMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opSingleMinRate && !opSingleMinRate.dataset.userEdited) opSingleMinRate.value = wInfo.minRate.toFixed(2);
            if (opSingleRate && !opSingleRate.dataset.userEdited) opSingleRate.value = pieceRate.toFixed(2);

            if (opSingleRateBadge) {
                const curR = parseFloat(opSingleRate ? opSingleRate.value : pieceRate) || 0;
                const curM = parseFloat(opSingleMinutes ? opSingleMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opSingleMinRate ? opSingleMinRate.value : wInfo.minRate) || 0;
                if (wInfo.isDaily) {
                    opSingleRateBadge.className = 'badge bg-info-subtle text-info-emphasis border';
                    opSingleRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/day (Daily Wage — یومیہ اجرت)`;
                } else if (wInfo.isPiece) {
                    opSingleRateBadge.className = 'badge bg-primary-subtle text-primary border';
                    opSingleRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/pc (Piece Rate — پر پیس ریٹ)`;
                } else {
                    opSingleRateBadge.className = 'badge bg-success-subtle text-success border';
                    opSingleRateBadge.textContent = curM > 0 ? `Rate: Rs. ${curR.toFixed(2)}/pc (${curM}m × Rs. ${curMR.toFixed(2)}/m — منٹ ریٹ)` : `Rate: Rs. ${curR.toFixed(2)}/pc (Direct Rate)`;
                }
            }

            if (document.getElementById('lblSingleMinRateBadge')) {
                if (wInfo.isDaily) {
                    document.getElementById('lblSingleMinRateBadge').textContent = `Rs. ${pieceRate.toFixed(2)}/day (Daily Wage)`;
                } else if (wInfo.isPiece) {
                    document.getElementById('lblSingleMinRateBadge').textContent = `Rs. ${pieceRate.toFixed(2)}/pc (Piece Rate)`;
                } else {
                    document.getElementById('lblSingleMinRateBadge').textContent = `Rs. ${wInfo.minRate.toFixed(2)}/m`;
                }
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
            const dayInfo = getWorkerWageInfo(dayWorkerOpt);
            let dayPieceRate = opDefRate;
            if (dayInfo.isDaily) {
                dayPieceRate = dayInfo.dailyRate > 0 ? dayInfo.dailyRate : 300.00;
            } else if (dayInfo.isPiece && dayInfo.pieceRate > 0) {
                dayPieceRate = dayInfo.pieceRate;
            } else if (finalMins > 0) {
                dayPieceRate = finalMins * dayInfo.minRate;
            }

            const nightWorkerOpt = opDoubleNightWorker ? opDoubleNightWorker.selectedOptions[0] : null;
            const nightInfo = getWorkerWageInfo(nightWorkerOpt);
            let nightPieceRate = opDefRate;
            if (nightInfo.isDaily) {
                nightPieceRate = nightInfo.dailyRate > 0 ? nightInfo.dailyRate : 300.00;
            } else if (nightInfo.isPiece && nightInfo.pieceRate > 0) {
                nightPieceRate = nightInfo.pieceRate;
            } else if (finalMins > 0) {
                nightPieceRate = finalMins * nightInfo.minRate;
            }

            if (document.getElementById('lblDoubleDayMinRateBadge')) {
                if (dayInfo.isDaily) {
                    document.getElementById('lblDoubleDayMinRateBadge').textContent = `Rs. ${dayPieceRate.toFixed(2)}/day (Daily Wage)`;
                } else if (dayInfo.isPiece) {
                    document.getElementById('lblDoubleDayMinRateBadge').textContent = `Rs. ${dayPieceRate.toFixed(2)}/pc (Piece Rate)`;
                } else {
                    document.getElementById('lblDoubleDayMinRateBadge').textContent = `Rs. ${dayInfo.minRate.toFixed(2)}/m`;
                }
            }
            if (document.getElementById('lblDoubleNightMinRateBadge')) {
                if (nightInfo.isDaily) {
                    document.getElementById('lblDoubleNightMinRateBadge').textContent = `Rs. ${nightPieceRate.toFixed(2)}/day (Daily Wage)`;
                } else if (nightInfo.isPiece) {
                    document.getElementById('lblDoubleNightMinRateBadge').textContent = `Rs. ${nightPieceRate.toFixed(2)}/pc (Piece Rate)`;
                } else {
                    document.getElementById('lblDoubleNightMinRateBadge').textContent = `Rs. ${nightInfo.minRate.toFixed(2)}/m`;
                }
            }

            if (opDoubleDayMinutes && !opDoubleDayMinutes.dataset.userEdited) opDoubleDayMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opDoubleDayMinRate && !opDoubleDayMinRate.dataset.userEdited) opDoubleDayMinRate.value = dayInfo.minRate.toFixed(2);
            if (opDoubleDayRate && !opDoubleDayRate.dataset.userEdited) opDoubleDayRate.value = dayPieceRate.toFixed(2);

            if (opDoubleNightMinutes && !opDoubleNightMinutes.dataset.userEdited) opDoubleNightMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opDoubleNightMinRate && !opDoubleNightMinRate.dataset.userEdited) opDoubleNightMinRate.value = nightInfo.minRate.toFixed(2);
            if (opDoubleNightRate && !opDoubleNightRate.dataset.userEdited) opDoubleNightRate.value = nightPieceRate.toFixed(2);

            if (opDoubleDayRateBadge) {
                const curR = parseFloat(opDoubleDayRate ? opDoubleDayRate.value : dayPieceRate) || 0;
                const curM = parseFloat(opDoubleDayMinutes ? opDoubleDayMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opDoubleDayMinRate ? opDoubleDayMinRate.value : dayInfo.minRate) || 0;
                if (dayInfo.isDaily) {
                    opDoubleDayRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/day (Daily Wage)`;
                } else if (dayInfo.isPiece) {
                    opDoubleDayRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/pc (Piece Rate)`;
                } else {
                    opDoubleDayRateBadge.textContent = curM > 0 ? `Rate: Rs. ${curR.toFixed(2)}/pc (${curM}m @ ${curMR.toFixed(2)}/m)` : `Rs. ${curR.toFixed(2)}/pc`;
                }
            }
            if (opDoubleNightRateBadge) {
                const curR = parseFloat(opDoubleNightRate ? opDoubleNightRate.value : nightPieceRate) || 0;
                const curM = parseFloat(opDoubleNightMinutes ? opDoubleNightMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opDoubleNightMinRate ? opDoubleNightMinRate.value : nightInfo.minRate) || 0;
                if (nightInfo.isDaily) {
                    opDoubleNightRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/day (Daily Wage)`;
                } else if (nightInfo.isPiece) {
                    opDoubleNightRateBadge.textContent = `Rate: Rs. ${curR.toFixed(2)}/pc (Piece Rate)`;
                } else {
                    opDoubleNightRateBadge.textContent = curM > 0 ? `Rate: Rs. ${curR.toFixed(2)}/pc (${curM}m @ ${curMR.toFixed(2)}/m)` : `Rs. ${curR.toFixed(2)}/pc`;
                }
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
            if (opSingleRate) delete opSingleRate.dataset.userEdited;
            if (opSingleMinRate) delete opSingleMinRate.dataset.userEdited;
            if (opSingleMinutes) delete opSingleMinutes.dataset.userEdited;
            updateOperatorRatesAndQuantities();
            syncWorkerCustomRates(this.value);
        });
    }
    if (opDoubleDayWorker) {
        opDoubleDayWorker.addEventListener('change', function() {
            if (opDoubleDayRate) delete opDoubleDayRate.dataset.userEdited;
            if (opDoubleDayMinRate) delete opDoubleDayMinRate.dataset.userEdited;
            if (opDoubleDayMinutes) delete opDoubleDayMinutes.dataset.userEdited;
            updateOperatorRatesAndQuantities();
        });
    }
    if (opDoubleNightWorker) {
        opDoubleNightWorker.addEventListener('change', function() {
            if (opDoubleNightRate) delete opDoubleNightRate.dataset.userEdited;
            if (opDoubleNightMinRate) delete opDoubleNightMinRate.dataset.userEdited;
            if (opDoubleNightMinutes) delete opDoubleNightMinutes.dataset.userEdited;
            updateOperatorRatesAndQuantities();
        });
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
            const wOpt = opSingleWorker ? opSingleWorker.selectedOptions[0] : null;
            const wInfo = getWorkerWageInfo(wOpt);
            const dayLabour = wInfo.isDaily ? rate : (wInfo.isMonthly ? 0.00 : (dayPcs * rate));
            if (lblSingleDayLabour) lblSingleDayLabour.textContent = `Rs. ${dayLabour.toFixed(2)}`;
            totalEntered = dayPcs;
        } else {
            const dayPcs = parseInt(opDoubleDayPieces.value, 10) || 0;
            const dayRate = parseFloat(opDoubleDayRate ? opDoubleDayRate.value : 0) || 0;
            const dOpt = opDoubleDayWorker ? opDoubleDayWorker.selectedOptions[0] : null;
            const dInfo = getWorkerWageInfo(dOpt);
            const dayLabour = dInfo.isDaily ? dayRate : (dInfo.isMonthly ? 0.00 : (dayPcs * dayRate));
            if (lblDoubleDayLabour) lblDoubleDayLabour.textContent = `Rs. ${dayLabour.toFixed(2)}`;

            const nightPcs = parseInt(opDoubleNightPieces.value, 10) || 0;
            const nightRate = parseFloat(opDoubleNightRate ? opDoubleNightRate.value : 0) || 0;
            const nOpt = opDoubleNightWorker ? opDoubleNightWorker.selectedOptions[0] : null;
            const nInfo = getWorkerWageInfo(nOpt);
            const nightLabour = nInfo.isDaily ? nightRate : (nInfo.isMonthly ? 0.00 : (nightPcs * nightRate));
            if (lblDoubleNightLabour) lblDoubleNightLabour.textContent = `Rs. ${nightLabour.toFixed(2)}`;

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

            // Calculate pieces entered across elements
            let totalDayPcs = 0;
            let totalNightPcs = 0;
            if (opElementsTbody) {
                opElementsTbody.querySelectorAll('tr[data-elid]').forEach(tr => {
                    const dInp = tr.querySelector('.op-el-day');
                    const nInp = tr.querySelector('.op-el-night');
                    if (dInp) totalDayPcs += parseInt(dInp.value, 10) || 0;
                    if (nInp) totalNightPcs += parseInt(nInp.value, 10) || 0;
                });
            }

            if (shiftType === 'Double Shift') {
                const dayW = opDoubleDayWorker ? opDoubleDayWorker.value : '';
                const nightW = opDoubleNightWorker ? opDoubleNightWorker.value : '';
                const dPcs = parseInt(opDoubleDayPieces ? opDoubleDayPieces.value : 0, 10) || 0;
                const nPcs = parseInt(opDoubleNightPieces ? opDoubleNightPieces.value : 0, 10) || 0;
                const dayHasPcs = (totalDayPcs > 0 || dPcs > 0);
                const nightHasPcs = (totalNightPcs > 0 || nPcs > 0);

                if (!dayW && !nightW) {
                    e.preventDefault();
                    alert('Please select at least one Operator (Day or Night). کم از کم ایک آپریٹر منتخب کریں۔');
                    if (opDoubleDayWorker) opDoubleDayWorker.focus();
                    return;
                }
                if (dayHasPcs && !dayW) {
                    e.preventDefault();
                    alert('Please select Day Operator for the day pieces entered.');
                    if (opDoubleDayWorker) opDoubleDayWorker.focus();
                    return;
                }
                if (nightHasPcs && !nightW) {
                    e.preventDefault();
                    alert('Please select Night Operator for the night pieces entered.');
                    if (opDoubleNightWorker) opDoubleNightWorker.focus();
                    return;
                }
                if (!dayHasPcs && !nightHasPcs) {
                    e.preventDefault();
                    alert('Please enter produced pieces for at least one element or shift. کم از کم ایک حصے کے پیسز درج کریں۔');
                    return;
                }
            } else {
                const dayW = opSingleWorker ? opSingleWorker.value : '';
                if (!dayW) {
                    e.preventDefault();
                    alert('Please select Day Operator.');
                    if (opSingleWorker) opSingleWorker.focus();
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
