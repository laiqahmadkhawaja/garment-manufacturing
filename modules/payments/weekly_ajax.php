<?php
/**
 * Garment Manufacturing ERP - Weekly Payments AJAX Handler
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login again.']);
    exit;
}

$db = get_db();
$action = trim($_REQUEST['action'] ?? '');

try {
    if ($action === 'pay_single') {
        $workerId = (int)($_POST['worker_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        if (empty($paymentDate)) $paymentDate = date('Y-m-d');
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $refNo = trim($_POST['reference_no'] ?? '');
        $periodFrom = trim($_POST['period_from'] ?? '');
        $periodTo = trim($_POST['period_to'] ?? '');
        $userNotes = trim($_POST['notes'] ?? '');

        if ($workerId <= 0) {
            throw new Exception('Invalid worker ID specified.');
        }
        if ($amount <= 0) {
            throw new Exception('Payment amount must be greater than zero.');
        }

        // Verify worker exists
        $wStmt = $db->prepare("SELECT id, code, name, opening_balance FROM workers WHERE id = ?");
        $wStmt->execute([$workerId]);
        $worker = $wStmt->fetch();
        if (!$worker) {
            throw new Exception('Worker record not found in system.');
        }

        $db->beginTransaction();

        $payNum = generate_doc_number('PAY-', 'worker_payments', 'payment_number');
        $periodNote = ($periodFrom && $periodTo) ? " [Week: $periodFrom to $periodTo]" : "";
        $fullNotes = trim("Weekly Payment" . $periodNote . ($userNotes ? " - " . $userNotes : ""));

        // 1. Insert into worker_payments
        $pStmt = $db->prepare("INSERT INTO worker_payments 
            (payment_number, worker_id, payment_date, amount, payment_type, payment_method, account_id, reference_no, notes, created_by) 
            VALUES (?, ?, ?, ?, 'Payment', ?, ?, ?, ?, ?)");
        $pStmt->execute([
            $payNum,
            $workerId,
            $paymentDate,
            $amount,
            $paymentMethod,
            $accountId,
            $refNo,
            $fullNotes,
            $_SESSION['user_id'] ?? null
        ]);
        $paymentId = (int)$db->lastInsertId();

        // 2. Fetch last running balance
        $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
        $balStmt->execute([$workerId]);
        $lastBal = $balStmt->fetchColumn();
        if ($lastBal === false) {
            $lastBal = (float)$worker['opening_balance'];
        } else {
            $lastBal = (float)$lastBal;
        }

        // 3. New running balance (Payment debit reduces factory liability)
        $newBal = $lastBal - $amount;

        $ledgerDesc = "Weekly payment disbursed via $paymentMethod ($payNum)" . ($refNo ? " Ref: $refNo" : "") . $periodNote;
        $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
            (worker_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
            VALUES (?, ?, 'Payment', 'WORKER_PAYMENT', ?, ?, 0.00, ?, ?, ?)");
        $ledgStmt->execute([
            $workerId,
            $paymentDate,
            $paymentId,
            $amount,
            $newBal,
            $ledgerDesc,
            $_SESSION['user_id'] ?? null
        ]);

        // 4. Deduct from Cash/Bank Account if specified
        if ($accountId) {
            record_account_transaction(
                $accountId,
                $paymentDate,
                'Labour Payment',
                $amount,
                'outflow',
                "Weekly payment to {$worker['name']} ({$worker['code']}) - Voucher $payNum" . $periodNote,
                'WORKER_PAYMENT',
                $paymentId,
                $payNum,
                null,
                $_SESSION['user_id'] ?? null
            );
        }

        log_audit('DISBURSE_WEEKLY_PAYMENT', 'worker_payments', $paymentId, null, [
            'worker_id' => $workerId,
            'worker_name' => $worker['name'],
            'amount' => $amount,
            'payment_number' => $payNum,
            'new_balance' => $newBal
        ]);

        $db->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Weekly payment of Rs. ' . number_format($amount, 2) . ' successfully disbursed to ' . $worker['name'] . '!',
            'payment_id' => $paymentId,
            'payment_number' => $payNum,
            'worker_id' => $workerId,
            'worker_name' => $worker['name'],
            'amount' => $amount,
            'amount_formatted' => number_format($amount, 2),
            'new_balance' => $newBal,
            'new_balance_formatted' => number_format($newBal, 2),
            'receipt_url' => BASE_URL . 'modules/payments/receipt.php?id=' . $paymentId
        ]);
        exit;

    } elseif ($action === 'pay_bulk') {
        $payments = $_POST['payments'] ?? [];
        if (!is_array($payments) || empty($payments)) {
            throw new Exception('No worker payments selected for bulk disbursement.');
        }

        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        if (empty($paymentDate)) $paymentDate = date('Y-m-d');
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $periodFrom = trim($_POST['period_from'] ?? '');
        $periodTo = trim($_POST['period_to'] ?? '');

        $db->beginTransaction();

        $processedCount = 0;
        $totalDisbursed = 0.00;
        $results = [];

        foreach ($payments as $p) {
            $wId = (int)($p['worker_id'] ?? 0);
            $amt = (float)($p['amount'] ?? 0);
            $uNote = trim($p['notes'] ?? '');

            if ($wId <= 0 || $amt <= 0) continue;

            $wStmt = $db->prepare("SELECT id, code, name, opening_balance FROM workers WHERE id = ?");
            $wStmt->execute([$wId]);
            $wRow = $wStmt->fetch();
            if (!$wRow) continue;

            $payNum = generate_doc_number('PAY-', 'worker_payments', 'payment_number');
            $periodNote = ($periodFrom && $periodTo) ? " [Week: $periodFrom to $periodTo]" : "";
            $fullNotes = trim("Bulk Weekly Payment" . $periodNote . ($uNote ? " - " . $uNote : ""));

            $pStmt = $db->prepare("INSERT INTO worker_payments 
                (payment_number, worker_id, payment_date, amount, payment_type, payment_method, account_id, reference_no, notes, created_by) 
                VALUES (?, ?, ?, ?, 'Payment', ?, ?, '', ?, ?)");
            $pStmt->execute([
                $payNum,
                $wId,
                $paymentDate,
                $amt,
                $paymentMethod,
                $accountId,
                $fullNotes,
                $_SESSION['user_id'] ?? null
            ]);
            $paymentId = (int)$db->lastInsertId();

            // Last balance
            $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$wId]);
            $lastBal = $balStmt->fetchColumn();
            if ($lastBal === false) {
                $lastBal = (float)$wRow['opening_balance'];
            } else {
                $lastBal = (float)$lastBal;
            }

            $newBal = $lastBal - $amt;

            $ledgerDesc = "Bulk weekly payment disbursed via $paymentMethod ($payNum)" . $periodNote;
            $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                (worker_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, 'Payment', 'WORKER_PAYMENT', ?, ?, 0.00, ?, ?, ?)");
            $ledgStmt->execute([
                $wId,
                $paymentDate,
                $paymentId,
                $amt,
                $newBal,
                $ledgerDesc,
                $_SESSION['user_id'] ?? null
            ]);

            if ($accountId) {
                record_account_transaction(
                    $accountId,
                    $paymentDate,
                    'Labour Payment',
                    $amt,
                    'outflow',
                    "Weekly payment to {$wRow['name']} ({$wRow['code']}) - Voucher $payNum" . $periodNote,
                    'WORKER_PAYMENT',
                    $paymentId,
                    $payNum,
                    null,
                    $_SESSION['user_id'] ?? null
                );
            }

            $processedCount++;
            $totalDisbursed += $amt;
            $results[] = [
                'worker_id' => $wId,
                'worker_name' => $wRow['name'],
                'amount' => $amt,
                'payment_number' => $payNum,
                'payment_id' => $paymentId,
                'new_balance' => $newBal
            ];
        }

        if ($processedCount === 0) {
            $db->rollBack();
            throw new Exception('No valid payments were processed.');
        }

        $db->commit();

        echo json_encode([
            'status' => 'success',
            'message' => "Successfully disbursed payments to $processedCount workers totaling Rs. " . number_format($totalDisbursed, 2),
            'count' => $processedCount,
            'total_amount' => $totalDisbursed,
            'total_amount_formatted' => number_format($totalDisbursed, 2),
            'results' => $results
        ]);
        exit;

    } elseif ($action === 'get_worker_lots') {
        $workerId = (int)($_GET['worker_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        if ($workerId <= 0) {
            throw new Exception('Worker ID required.');
        }

        // Query production entries for this worker in the date range
        $sql = "SELECT pe.id, pe.entry_number, pe.entry_date, pe.shift, pe.good_qty, pe.rejected_qty, 
                       pe.rate, pe.minutes_per_piece, pe.worker_minute_rate, pe.labour_amount,
                       l.id AS lot_id, l.lot_number, p.name AS product_name, o.name AS operation_name,
                       c.name AS color_name, s.name AS size_name
                FROM production_entries pe
                JOIN lots l ON pe.lot_id = l.id
                JOIN products p ON l.product_id = p.id
                JOIN operations o ON pe.operation_id = o.id
                LEFT JOIN colors c ON pe.color_id = c.id
                LEFT JOIN sizes s ON pe.size_id = s.id
                WHERE pe.worker_id = ?";
        
        $params = [$workerId];
        if (!empty($dateFrom)) {
            $sql .= " AND pe.entry_date >= ?";
            $params[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $sql .= " AND pe.entry_date <= ?";
            $params[] = $dateTo;
        }

        $sql .= " ORDER BY pe.entry_date DESC, pe.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        // Also query ledger entries that might have lot_id attached directly
        $ledgSql = "SELECT wl.*, l.lot_number, o.name AS operation_name
                    FROM worker_ledger wl
                    LEFT JOIN lots l ON wl.lot_id = l.id
                    LEFT JOIN operations o ON wl.operation_id = o.id
                    WHERE wl.worker_id = ? AND wl.transaction_type = 'Labour'";
        $ledgParams = [$workerId];
        if (!empty($dateFrom)) {
            $ledgSql .= " AND wl.transaction_date >= ?";
            $ledgParams[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $ledgSql .= " AND wl.transaction_date <= ?";
            $ledgParams[] = $dateTo;
        }
        $ledgSql .= " ORDER BY wl.transaction_date DESC";
        $lStmt = $db->prepare($ledgSql);
        $lStmt->execute($ledgParams);
        $ledgerEntries = $lStmt->fetchAll();

        // Calculate totals
        $totPcs = 0;
        $totLabour = 0.00;
        $lotSummary = [];

        foreach ($entries as $e) {
            $totPcs += (int)$e['good_qty'];
            $totLabour += (float)$e['labour_amount'];

            $lId = $e['lot_id'];
            if (!isset($lotSummary[$lId])) {
                $lotSummary[$lId] = [
                    'lot_id' => $lId,
                    'lot_number' => $e['lot_number'],
                    'product_name' => $e['product_name'],
                    'operations' => [],
                    'total_pieces' => 0,
                    'total_labour' => 0.00
                ];
            }
            $lotSummary[$lId]['total_pieces'] += (int)$e['good_qty'];
            $lotSummary[$lId]['total_labour'] += (float)$e['labour_amount'];
            if (!in_array($e['operation_name'], $lotSummary[$lId]['operations'])) {
                $lotSummary[$lId]['operations'][] = $e['operation_name'];
            }
        }

        echo json_encode([
            'status' => 'success',
            'worker_id' => $workerId,
            'entries' => $entries,
            'lots' => array_values($lotSummary),
            'ledger_entries' => $ledgerEntries,
            'total_pieces' => $totPcs,
            'total_labour' => $totLabour,
            'total_labour_formatted' => number_format($totLabour, 2)
        ]);
        exit;

    } else {
        throw new Exception('Unknown or unspecified action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
    exit;
}
