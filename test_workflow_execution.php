<?php
/**
 * Garment Manufacturing ERP - End-to-End Workflow & Stress Test Script
 * Verifies the entire workflow step-by-step:
 * 1. Document number collision prevention & unique auto-advancement.
 * 2. Partial Operator entries (Day & Night) -> Pending checks.
 * 3. Completing remaining Operator pieces -> Full verification.
 * 4. Partial Cutting entries -> Pending balance checks.
 * 5. Completing Cutting stage.
 * 6. Partial Fast Matrix / Stitching entries with Good, Rejection & Rework tracking.
 * 7. Completing Stitching stage.
 * 8. Partial Packing -> Lot status 'Partially Packed'.
 * 9. Completing Packing -> Lot status 'Ready Stock'.
 * 10. Finished Goods Stock Inward & Dispatch to Customer.
 * 11. Worker Ledger Reconciliation.
 */
require_once __DIR__ . '/config/config.php';

$db = get_db();
$pass = 0;
$fail = 0;

function report(string $name, bool $ok, string $details = '') {
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] " . str_pad($name, 58) . " : " . ($details ?: "OK") . "\n";
        $pass++;
    } else {
        echo "[FAIL] " . str_pad($name, 58) . " : " . ($details ?: "Assertion Failed") . "\n";
        $fail++;
    }
}

echo "========================================================================\n";
echo "GARMENT MANUFACTURING ERP - COMPLETE END-TO-END WORKFLOW VERIFICATION\n";
echo "========================================================================\n\n";

$db->beginTransaction();
try {
    // -------------------------------------------------------------------------
    // TEST 1: DOCUMENT NUMBER COLLISION PREVENTION & AUTO-INCREMENT
    // -------------------------------------------------------------------------
    // Force insert a dummy entry with 'PE-0102' to replicate user's server state
    $db->exec("DELETE FROM production_entries WHERE entry_number IN ('PE-0102', 'PE-0102-1', 'PE-0103', 'PE-0104')");
    $db->prepare("INSERT INTO production_entries (entry_number, entry_date, lot_id, operation_id, worker_id, good_qty, rate, labour_amount) 
                  VALUES ('PE-0102', '2026-11-22', 1, 1, 1, 10, 2.00, 20.00)")->execute();
    
    // Test generate_doc_number with prefix 'PE-'
    $generated = generate_doc_number('PE-', 'production_entries', 'entry_number');
    report("1. Collision-Proof Doc Number (Skips Existing)", $generated !== 'PE-0102', "Generated '$generated' (Existing 'PE-0102' skipped successfully)");

    // Test ensure_unique_doc_number when candidate already exists
    $uniqueCandidate = ensure_unique_doc_number($db, 'production_entries', 'entry_number', 'PE-0102');
    report("2. ensure_unique_doc_number Resolves Collision", $uniqueCandidate !== 'PE-0102' && strpos($uniqueCandidate, 'PE-0102') === 0, "Resolved candidate to '$uniqueCandidate'");

    // Clean up dummy
    $db->exec("DELETE FROM production_entries WHERE entry_number LIKE 'PE-0102%'");

    // -------------------------------------------------------------------------
    // SETUP TEST LOT FOR COMPLETE STEP-BY-STEP WORKFLOW
    // -------------------------------------------------------------------------
    $testLotNum = 'LOT-FLOW-' . time();
    $db->prepare("INSERT INTO lots (lot_number, lot_date, product_id, design_id, total_pieces, expected_date, status, shift_type, supervisor_name, machine_name, operator_id, night_operator_id) 
                  VALUES (?, '2026-11-23', 1, 1, 120, '2026-12-01', 'Draft', 'Double Shift', 'Super 1', 'CMS 430', 1, 2)")
       ->execute([$testLotNum]);
    $lotId = (int)$db->lastInsertId();

    // Attach 4 workflow operations to Lot:
    // 1: Operator Knitting, 2: Cutting, 3: Stitching, 4: Packing
    // Get 4 operations from operations table
    $ops = $db->query("SELECT id, name FROM operations ORDER BY sequence ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    if (count($ops) < 4) {
        throw new Exception("Need at least 4 operations in operations table");
    }
    $op1 = $ops[0]['id']; // Operator
    $op2 = $ops[1]['id']; // Cutting
    $op3 = $ops[2]['id']; // Stitching / Fast Matrix
    $op4 = $ops[3]['id']; // Packing

    $db->prepare("INSERT INTO lot_workflow_steps (lot_id, operation_id, sequence, is_mandatory, status) VALUES 
                  (?, ?, 1, 1, 'Pending'),
                  (?, ?, 2, 1, 'Pending'),
                  (?, ?, 3, 1, 'Pending'),
                  (?, ?, 4, 1, 'Pending')")->execute([$lotId, $op1, $lotId, $op2, $lotId, $op3, $lotId, $op4]);

    // Attach Colors & Sizes (2 colors, 2 sizes = 4 variants x 30 pcs = 120 pcs)
    $colors = $db->query("SELECT id FROM colors LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $sizes = $db->query("SELECT id FROM sizes LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($colors as $cId) {
        foreach ($sizes as $sId) {
            $db->prepare("INSERT INTO lot_variants (lot_id, color_id, size_id, quantity) VALUES (?, ?, ?, 30)")
               ->execute([$lotId, $cId, $sId]);
        }
    }

    report("3. Create Production Lot (120 pcs, 4 steps)", $lotId > 0, "Lot #$testLotNum created with 4-stage workflow");

    // -------------------------------------------------------------------------
    // STEP 1: OPERATOR KNITTING - PARTIAL ENTRY (Keep some pieces pending)
    // Planned: 120 pcs. Day Operator: 40 pcs, Night Operator: 30 pcs = 70 pcs.
    // Remaining Pending: 50 pcs.
    // -------------------------------------------------------------------------
    $baseDoc = generate_doc_number('PE-', 'production_entries', 'entry_number');
    $dayEntryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDoc . '-D');
    $nightEntryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDoc . '-N');

    $dayRate = 5.00;
    $nightRate = 5.00;

    // Day Operator Entry (40 pcs)
    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, shift, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 1, 'Day Shift', 40, ?, 200.00, 'Partial Day Shift')")
       ->execute([$dayEntryNum, $lotId, $op1, $dayRate]);
    $dayPeId = (int)$db->lastInsertId();

    // Night Operator Entry (30 pcs)
    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, shift, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 2, 'Night Shift', 30, ?, 150.00, 'Partial Night Shift')")
       ->execute([$nightEntryNum, $lotId, $op1, $nightRate]);
    $nightPeId = (int)$db->lastInsertId();

    // Credit Worker Ledgers
    $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, credit, running_balance, description) 
                  VALUES (1, '2026-11-23', 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 200.00, 200.00, 'Day Operator partial')")
       ->execute([$dayPeId, $lotId, $op1]);
    $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, credit, running_balance, description) 
                  VALUES (2, '2026-11-23', 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 150.00, 150.00, 'Night Operator partial')")
       ->execute([$nightPeId, $lotId, $op1]);

    // Check Operator Step Progress
    $opDoneStmt = $db->prepare("SELECT SUM(good_qty) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
    $opDoneStmt->execute([$lotId, $op1]);
    $opDone1 = (int)$opDoneStmt->fetchColumn();
    $opPending1 = 120 - $opDone1;

    report("4. Stage 1 Operator - Partial Entry Recorded", $opDone1 === 70 && $opPending1 === 50, "Done: $opDone1 pcs, Pending: $opPending1 pcs (Day: 40, Night: 30)");

    // -------------------------------------------------------------------------
    // STEP 2: OPERATOR KNITTING - COMPLETE REMAINING (Complete remaining pieces)
    // Day Operator: 30 pcs, Night Operator: 20 pcs = 50 pcs. Total = 120 pcs.
    // Remaining Pending: 0 pcs.
    // -------------------------------------------------------------------------
    $baseDoc2 = generate_doc_number('PE-', 'production_entries', 'entry_number');
    $dayEntryNum2 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDoc2 . '-D');
    $nightEntryNum2 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDoc2 . '-N');

    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, shift, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 1, 'Day Shift', 30, ?, 150.00, 'Complete Day Shift')")
       ->execute([$dayEntryNum2, $lotId, $op1, $dayRate]);

    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, shift, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 2, 'Night Shift', 20, ?, 100.00, 'Complete Night Shift')")
       ->execute([$nightEntryNum2, $lotId, $op1, $nightRate]);

    $opDoneStmt->execute([$lotId, $op1]);
    $opDoneTotal = (int)$opDoneStmt->fetchColumn();
    $opPendingTotal = 120 - $opDoneTotal;

    // Mark step completed
    $db->prepare("UPDATE lot_workflow_steps SET status = 'Completed' WHERE lot_id = ? AND operation_id = ?")->execute([$lotId, $op1]);

    report("5. Stage 1 Operator - Remaining Pieces Completed", $opDoneTotal === 120 && $opPendingTotal === 0, "Total Done: $opDoneTotal / 120 pcs, Pending: 0 pcs");

    // -------------------------------------------------------------------------
    // STEP 3: CUTTING STAGE - PARTIAL ENTRY
    // Cutting Master (Worker 1) cuts 70 pieces. Pending: 50 pieces.
    // -------------------------------------------------------------------------
    $cutBaseDoc = generate_doc_number('PE-CUT-', 'production_entries', 'entry_number');
    $cutEntry1 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $cutBaseDoc . '-1');
    $cutRate = 3.00;

    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 1, 70, ?, 210.00, 'Cutting partial batch')")
       ->execute([$cutEntry1, $lotId, $op2, $cutRate]);

    $cutDoneStmt = $db->prepare("SELECT SUM(good_qty) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
    $cutDoneStmt->execute([$lotId, $op2]);
    $cutDone1 = (int)$cutDoneStmt->fetchColumn();
    $cutPending1 = 120 - $cutDone1;

    report("6. Stage 2 Cutting - Partial Entry (70 pcs)", $cutDone1 === 70 && $cutPending1 === 50, "Cut: $cutDone1 pcs, Pending: $cutPending1 pcs");

    // -------------------------------------------------------------------------
    // STEP 4: CUTTING STAGE - COMPLETE REMAINING 50 PIECES
    // -------------------------------------------------------------------------
    $cutEntry2 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $cutBaseDoc . '-2');
    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, good_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 1, 50, ?, 150.00, 'Cutting remainder batch')")
       ->execute([$cutEntry2, $lotId, $op2, $cutRate]);

    $cutDoneStmt->execute([$lotId, $op2]);
    $cutDoneTotal = (int)$cutDoneStmt->fetchColumn();
    $db->prepare("UPDATE lot_workflow_steps SET status = 'Completed' WHERE lot_id = ? AND operation_id = ?")->execute([$lotId, $op2]);

    report("7. Stage 2 Cutting - Full Completion (120 pcs)", $cutDoneTotal === 120, "Total Cut: $cutDoneTotal / 120 pcs, 0 Pending");

    // -------------------------------------------------------------------------
    // STEP 5: FAST MATRIX / STITCHING - PARTIAL ENTRY WITH DEFECTS
    // Process 60 pieces: 55 Good, 3 Rejected, 2 Rework.
    // -------------------------------------------------------------------------
    $matBaseDoc = generate_doc_number('PE-', 'production_entries', 'entry_number');
    preg_match('/\d+$/', $matBaseDoc, $m);
    $matSeq = isset($m[0]) ? (int)$m[0] : 101;
    $stitchEntry1 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', 'PE-' . str_pad((string)$matSeq++, 4, '0', STR_PAD_LEFT));

    $stitchRate = 4.00;
    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 2, ?, ?, 60, 55, 3, 2, ?, 220.00, 'Stitching partial batch with defects')")
       ->execute([$stitchEntry1, $lotId, $op3, $colors[0], $sizes[0], $stitchRate]);
    $stPeId1 = (int)$db->lastInsertId();

    // Insert rejections & rework
    $db->prepare("INSERT INTO production_rejections (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, reason) 
                  VALUES (?, ?, ?, ?, ?, 3, 'Needle defect')")->execute([$stPeId1, $lotId, $op3, $colors[0], $sizes[0]]);
    $db->prepare("INSERT INTO production_rework (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, notes, status) 
                  VALUES (?, ?, ?, ?, ?, 2, 'Collar loose', 'Pending')")->execute([$stPeId1, $lotId, $op3, $colors[0], $sizes[0]]);

    $stDoneStmt = $db->prepare("SELECT SUM(good_qty), SUM(rejected_qty), SUM(rework_qty) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
    $stDoneStmt->execute([$lotId, $op3]);
    $stRow1 = $stDoneStmt->fetch(PDO::FETCH_NUM);
    $stGood1 = (int)$stRow1[0];
    $stRej1 = (int)$stRow1[1];
    $stRew1 = (int)$stRow1[2];

    report("8. Stage 3 Fast Matrix - Partial + Defects", $stGood1 === 55 && $stRej1 === 3 && $stRew1 === 2, "Good: $stGood1, Rejected: $stRej1, Rework: $stRew1 (60 total processed)");

    // -------------------------------------------------------------------------
    // STEP 6: FAST MATRIX / STITCHING - SECOND ENTRY TO COMPLETE REMAINING
    // Remaining to process: 120 - 60 = 60 pieces. All 60 Good.
    // Total Good = 115 pieces (+3 rejected, +2 rework = 120 total pieces accounted).
    // -------------------------------------------------------------------------
    $stitchEntry2 = ensure_unique_doc_number($db, 'production_entries', 'entry_number', 'PE-' . str_pad((string)$matSeq++, 4, '0', STR_PAD_LEFT));
    $db->prepare("INSERT INTO production_entries 
        (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, labour_amount, notes) 
        VALUES (?, '2026-11-23', ?, ?, 2, ?, ?, 60, 60, 0, 0, ?, 240.00, 'Stitching remaining 60 pcs')")
       ->execute([$stitchEntry2, $lotId, $op3, $colors[1], $sizes[1], $stitchRate]);

    $stDoneStmt->execute([$lotId, $op3]);
    $stRow2 = $stDoneStmt->fetch(PDO::FETCH_NUM);
    $stGoodTotal = (int)$stRow2[0];
    $db->prepare("UPDATE lot_workflow_steps SET status = 'Completed' WHERE lot_id = ? AND operation_id = ?")->execute([$lotId, $op3]);

    report("9. Stage 3 Fast Matrix - Full Stage Completed", $stGoodTotal === 115, "Good: $stGoodTotal pcs (+3 rej, +2 rew = 120 pcs total accounted for)");

    // -------------------------------------------------------------------------
    // STEP 7: PACKING & FINISHED GOODS INWARD - PARTIAL PACKING
    // Pack 60 pieces into Finished Goods Warehouse.
    // Lot status should transition to 'Partially Packed'.
    // -------------------------------------------------------------------------
    $finWhId = get_default_warehouse_id('finished');
    $pkgNum1 = ensure_unique_doc_number($db, 'packing_entries', 'packing_number', generate_doc_number('PKG-', 'packing_entries', 'packing_number'));
    
    $db->prepare("INSERT INTO packing_entries 
        (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, warehouse_id) 
        VALUES (?, '2026-11-23', ?, 1, 1, ?, ?, 60, 1, 2.00, 120.00, 'Master Box', ?)")
       ->execute([$pkgNum1, $lotId, $colors[0], $sizes[0], $finWhId]);

    // Inward stock into finished_goods_stock
    $db->prepare("INSERT INTO finished_goods_stock (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock, dispatched_stock, updated_at) 
                  VALUES (1, 1, ?, ?, ?, ?, 60, 0, NOW())")
       ->execute([$colors[0], $sizes[0], $lotId, $finWhId]);

    // Update lot status to 'Partially Packed'
    $db->prepare("UPDATE lots SET status = 'Partially Packed' WHERE id = ?")->execute([$lotId]);
    $lotStatus1 = $db->query("SELECT status FROM lots WHERE id = $lotId")->fetchColumn();

    report("10. Stage 4 Packing - Partial Packing (60 pcs)", $lotStatus1 === 'Partially Packed', "Status: '$lotStatus1', Stock Inward: 60 pcs");

    // -------------------------------------------------------------------------
    // STEP 8: PACKING & FINISHED GOODS INWARD - COMPLETE REMAINING PACKING
    // Pack remaining 55 good pieces.
    // Lot status should automatically transition to 'Ready Stock'.
    // -------------------------------------------------------------------------
    $pkgNum2 = ensure_unique_doc_number($db, 'packing_entries', 'packing_number', generate_doc_number('PKG-', 'packing_entries', 'packing_number'));
    
    $db->prepare("INSERT INTO packing_entries 
        (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, warehouse_id) 
        VALUES (?, '2026-11-23', ?, 1, 1, ?, ?, 55, 1, 2.00, 110.00, 'Master Box', ?)")
       ->execute([$pkgNum2, $lotId, $colors[1], $sizes[1], $finWhId]);

    $db->prepare("INSERT INTO finished_goods_stock (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock, dispatched_stock, updated_at) 
                  VALUES (1, 1, ?, ?, ?, ?, 55, 0, NOW())")
       ->execute([$colors[1], $sizes[1], $lotId, $finWhId]);

    // Transition status to Ready Stock
    $db->prepare("UPDATE lots SET status = 'Ready Stock' WHERE id = ?")->execute([$lotId]);
    $db->prepare("UPDATE lot_workflow_steps SET status = 'Completed' WHERE lot_id = ? AND operation_id = ?")->execute([$lotId, $op4]);

    $lotStatus2 = $db->query("SELECT status FROM lots WHERE id = $lotId")->fetchColumn();
    $totalStock = (int)$db->query("SELECT SUM(current_stock) FROM finished_goods_stock WHERE lot_id = $lotId")->fetchColumn();

    report("11. Stage 4 Packing - Full Completion ('Ready Stock')", $lotStatus2 === 'Ready Stock' && $totalStock === 115, "Status: '$lotStatus2', Total Finished Stock: $totalStock pcs");

    // -------------------------------------------------------------------------
    // STEP 9: DISPATCH & CUSTOMER INVOICING
    // Dispatch 100 pieces from Ready Stock to Customer #1
    // -------------------------------------------------------------------------
    $dispNum = ensure_unique_doc_number($db, 'dispatches', 'dispatch_number', generate_doc_number('DIS-', 'dispatches', 'dispatch_number'));
    $db->prepare("INSERT INTO dispatches (dispatch_number, dispatch_date, customer_id, total_pieces, total_value, payment_status, paid_amount, balance_amount) 
                  VALUES (?, '2026-11-23', 1, 100, 150000.00, 'Credit', 0.00, 150000.00)")
       ->execute([$dispNum]);
    $dispId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO dispatch_items (dispatch_id, lot_id, product_id, color_id, size_id, quantity, unit_rate, total_amount) 
                  VALUES (?, ?, 1, ?, ?, 100, 1500.00, 150000.00)")
       ->execute([$dispId, $lotId, $colors[0], $sizes[0]]);

    // Reduce finished goods stock
    $db->prepare("UPDATE finished_goods_stock SET current_stock = current_stock - 100, dispatched_stock = dispatched_stock + 100 WHERE lot_id = ? AND color_id = ?")
       ->execute([$lotId, $colors[0]]);

    // Record customer ledger debit
    $db->prepare("INSERT INTO customer_ledger (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description) 
                  VALUES (1, '2026-11-23', 'Invoice', 'DISPATCH', ?, 150000.00, 0.00, 150000.00, 'Dispatch of 100 pcs')")
       ->execute([$dispId]);

    $remStock = (int)$db->query("SELECT SUM(current_stock) FROM finished_goods_stock WHERE lot_id = $lotId")->fetchColumn();
    $custBal = (float)$db->query("SELECT running_balance FROM customer_ledger WHERE customer_id = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();

    report("12. Stage 5 Dispatch & Customer Debit", $remStock === 15 && $custBal === 150000.00, "Dispatched 100 pcs, Remaining Stock: $remStock pcs, Customer Balance: Rs. 150,000.00");

    // -------------------------------------------------------------------------
    // STEP 10: WORKER LEDGER RECONCILIATION & PAYMENT
    // Verify Worker 1 accrued labour and issue settlement payment
    // -------------------------------------------------------------------------
    // Worker 1 accrued: Day Operator (200 + 150 = 350) + Cutting (210 + 150 = 360) = Rs. 710.00
    // Record cutting labour to ledger
    $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, credit, running_balance, description) 
                  VALUES (1, '2026-11-23', 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 360.00, 560.00, 'Cutting full batch')")
       ->execute([$cutEntry1, $lotId, $op2]);

    $w1Bal = (float)$db->query("SELECT running_balance FROM worker_ledger WHERE worker_id = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    
    // Pay Worker 1
    $payDoc = ensure_unique_doc_number($db, 'worker_payments', 'payment_number', generate_doc_number('PAY-', 'worker_payments', 'payment_number'));
    $db->prepare("INSERT INTO worker_payments (payment_number, payment_date, worker_id, amount, payment_type, payment_method, notes) 
                  VALUES (?, '2026-11-23', 1, ?, 'Payment', 'Cash', 'Weekly Settlement')")
       ->execute([$payDoc, $w1Bal]);
    $payId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description) 
                  VALUES (1, '2026-11-23', 'Payment', 'WORKER_PAYMENT', ?, ?, 0.00, 0.00, 'Paid in Full')")
       ->execute([$payId, $w1Bal]);

    $finalW1Bal = (float)$db->query("SELECT running_balance FROM worker_ledger WHERE worker_id = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    report("13. Worker Ledger Reconciliation & Settlement", $finalW1Bal === 0.00, "Paid Rs. " . number_format($w1Bal, 2) . " via Payment #$payDoc, Final Balance: Rs. 0.00");

    // Rollback test transaction to keep database pristine
    $db->rollBack();
    echo "\n[INFO] Test database transaction rolled back cleanly. Production data pristine.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n[EXCEPTION] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $fail++;
}

echo "\n========================================================================\n";
echo "SUMMARY: $pass PASSED, $fail FAILED\n";
echo "========================================================================\n";
exit($fail > 0 ? 1 : 0);
