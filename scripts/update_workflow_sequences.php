<?php
require_once __DIR__ . '/../config/config.php';
$db = get_db();

echo "Starting sequence update...\n";

// 1. Update operations sequences
$seqMap = [
    'OP-OPERATOR' => 1,
    'OP-CUT' => 2,
    'OP-TAIL' => 3,
    'OP-PCK' => 4,
    'OP-TEST-PCK' => 4,
    'OP-NECK' => 5,
    'OP-STITCH' => 6,
    'OP-SAFE' => 7,
    'OP-BTN' => 8,
    'OP-KAJ' => 9,
    'OP-PRESS' => 10,
    'OP-PACK' => 11
];

foreach ($seqMap as $code => $seq) {
    $stmt = $db->prepare("UPDATE operations SET sequence = ? WHERE code = ?");
    $stmt->execute([$seq, $code]);
}
echo "[✓] Updated operations table sequences.\n";

// 2. Ensure OP-OPERATOR exists
$opId = (int)$db->query("SELECT id FROM operations WHERE code = 'OP-OPERATOR'")->fetchColumn();
if (!$opId) {
    $deptId = (int)$db->query("SELECT id FROM departments WHERE name LIKE '%Knitt%' OR name LIKE '%Machine%' LIMIT 1")->fetchColumn();
    if (!$deptId) {
        $db->exec("INSERT INTO departments (name, code, description) VALUES ('Machine Knitting', 'DEP-KNIT', 'Flat machine operators and panel knitting')");
        $deptId = (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO operations (sequence, name, code, department_id, default_rate, is_active)
                  VALUES (1, 'Operator Entry (مشین آپریٹر / نٹنگ)', 'OP-OPERATOR', ?, 5.00, 1)")
       ->execute([$deptId]);
    $opId = (int)$db->lastInsertId();
    echo "[✓] Inserted OP-OPERATOR operation with ID $opId.\n";
}

// 3. Fix workflow_template_steps for all templates
// Template 1 (Coat Standard Workflow)
$t1Ops = ['OP-OPERATOR', 'OP-CUT', 'OP-TAIL', 'OP-STITCH', 'OP-SAFE', 'OP-BTN', 'OP-KAJ', 'OP-PRESS', 'OP-PACK'];
// Template 2 (V-Neck Sweater Workflow)
$t2Ops = ['OP-OPERATOR', 'OP-CUT', 'OP-STITCH', 'OP-NECK', 'OP-SAFE', 'OP-PRESS', 'OP-PACK'];
// Template 3 (Jacket Standard Workflow)
$t3Ops = ['OP-OPERATOR', 'OP-CUT', 'OP-TAIL', 'OP-STITCH', 'OP-SAFE', 'OP-PRESS', 'OP-PACK'];

$templates = [1 => $t1Ops, 2 => $t2Ops, 3 => $t3Ops];

foreach ($templates as $tId => $opCodes) {
    // Delete existing steps
    $db->prepare("DELETE FROM workflow_template_steps WHERE template_id = ?")->execute([$tId]);
    $ins = $db->prepare("INSERT INTO workflow_template_steps (template_id, operation_id, sequence, is_mandatory) VALUES (?, ?, ?, 1)");
    $seq = 1;
    foreach ($opCodes as $code) {
        $oId = (int)$db->query("SELECT id FROM operations WHERE code = '$code'")->fetchColumn();
        if ($oId) {
            $ins->execute([$tId, $oId, $seq]);
            $seq++;
        }
    }
    echo "[✓] Updated Template $tId steps with Operator Entry as #1, Cutting as #2.\n";
}

// 4. Update lot_workflow_steps for all lots so OP-OPERATOR is #1, OP-CUT is #2, etc.
$lots = $db->query("SELECT id FROM lots")->fetchAll(PDO::FETCH_COLUMN);
foreach ($lots as $lotId) {
    // Check if lot already has steps
    $existing = $db->query("SELECT lws.id, lws.operation_id, o.code FROM lot_workflow_steps lws JOIN operations o ON lws.operation_id = o.id WHERE lws.lot_id = $lotId ORDER BY lws.sequence, lws.id")->fetchAll();
    
    // Check if OP-OPERATOR is present
    $hasOp = false;
    foreach ($existing as $e) {
        if ($e['code'] === 'OP-OPERATOR') { $hasOp = true; break; }
    }
    if (!$hasOp) {
        $db->prepare("INSERT INTO lot_workflow_steps (lot_id, operation_id, sequence, is_mandatory, status) VALUES (?, ?, 1, 1, 'Pending')")
           ->execute([$lotId, $opId]);
    }
    
    // Now re-sequence all steps for this lot
    $steps = $db->query("SELECT lws.id, lws.operation_id, o.code, o.sequence as base_seq FROM lot_workflow_steps lws JOIN operations o ON lws.operation_id = o.id WHERE lws.lot_id = $lotId ORDER BY o.sequence ASC, lws.id ASC")->fetchAll();
    
    $updSeq = $db->prepare("UPDATE lot_workflow_steps SET sequence = ? WHERE id = ?");
    $seqCounter = 1;
    foreach ($steps as $st) {
        $updSeq->execute([$seqCounter, $st['id']]);
        $seqCounter++;
    }
}
echo "[✓] Re-sequenced lot_workflow_steps for all lots.\n";

// 5. Verify Lot 19 steps
$lot19Steps = $db->query("SELECT s.id, s.lot_id, s.operation_id, o.code, o.name, s.sequence FROM lot_workflow_steps s JOIN operations o ON s.operation_id = o.id WHERE s.lot_id = 19 ORDER BY s.sequence ASC")->fetchAll();
echo "Lot 19 Workflow Steps:\n";
foreach ($lot19Steps as $s) {
    echo "  Seq {$s['sequence']}: {$s['name']} ({$s['code']})\n";
}
echo "Done!\n";
