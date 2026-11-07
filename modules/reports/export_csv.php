<?php
/**
 * Garment Manufacturing ERP - Export Reports to CSV / Excel
 * Supports all Financial & Non-Financial / Operational Management Reports
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$reportType = trim($_GET['type'] ?? 'batch_costing');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterWorker = (int)($_GET['worker_id'] ?? 0);
$filterLot = (int)($_GET['lot_id'] ?? 0);

$db = get_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $reportType . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
// Output UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 1. FINANCIAL: BATCH COSTING & PROFITABILITY
if ($reportType === 'batch_costing') {
    fputcsv($output, ['Lot Number', 'Lot Date', 'Product', 'Design Number', 'Total Pieces', 'Raw Material Cost', 'Labour Cost', 'Total Batch Cost', 'Unit Cost', 'Billed Revenue', 'Gross Profit', 'Margin %']);
    $rows = $db->query("SELECT l.id, l.lot_number, l.lot_date, p.name AS product_name, COALESCE(l.design_number, d.design_number) AS design_number, l.total_pieces,
                               COALESCE((SELECT SUM(mii.quantity * m.cost_price) FROM material_issues mi JOIN material_issue_items mii ON mi.id = mii.issue_id JOIN materials m ON mii.material_id = m.id WHERE mi.lot_id = l.id), 0) AS material_cost,
                               COALESCE((SELECT SUM(labour_amount) FROM production_entries WHERE lot_id = l.id), 0) AS labour_cost,
                               COALESCE((SELECT SUM(total_amount) FROM dispatch_items WHERE lot_id = l.id), 0) AS dispatch_revenue
                        FROM lots l
                        JOIN products p ON l.product_id = p.id
                        LEFT JOIN designs d ON l.design_id = d.id
                        ORDER BY l.id DESC")->fetchAll();
    foreach ($rows as $r) {
        $pcs = (int)$r['total_pieces'];
        $mat = (float)$r['material_cost'];
        $lab = (float)$r['labour_cost'];
        $cost = $mat + $lab;
        $unit = $pcs > 0 ? ($cost / $pcs) : 0;
        $rev = (float)$r['dispatch_revenue'];
        $profit = $rev - $cost;
        $margin = $rev > 0 ? (($profit / $rev) * 100) : 0;

        fputcsv($output, [
            $r['lot_number'],
            $r['lot_date'],
            $r['product_name'],
            $r['design_number'] ?: '-',
            $pcs,
            $mat,
            $lab,
            $cost,
            round($unit, 2),
            $rev,
            $profit,
            round($margin, 2) . '%'
        ]);
    }

// 2. FINANCIAL: CASH FLOW
} elseif ($reportType === 'cash_flow') {
    fputcsv($output, ['Account Name', 'Bank / Institution', 'Account Number', 'Opening Balance', 'Total Receipts (Inflows)', 'Total Payments (Outflows)', 'Current Liquid Balance']);
    $rows = $db->query("SELECT cba.account_name, cba.bank_name, cba.account_number, cba.current_balance,
                               COALESCE((SELECT amount FROM account_transactions WHERE account_id = cba.id AND transaction_type = 'Opening Balance' LIMIT 1), 0) AS opening_balance,
                               COALESCE(SUM(at.credit), 0) AS total_inflow,
                               COALESCE(SUM(at.debit), 0) AS total_outflow
                        FROM cash_bank_accounts cba
                        LEFT JOIN account_transactions at ON cba.id = at.account_id
                        GROUP BY cba.id")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['account_name'],
            $r['bank_name'] ?: 'Cash Drawer',
            $r['account_number'] ?: '-',
            $r['opening_balance'],
            $r['total_inflow'],
            $r['total_outflow'],
            $r['current_balance']
        ]);
    }

// 3. FINANCIAL: CUSTOMER RECEIVABLES
} elseif ($reportType === 'customer_receivables') {
    fputcsv($output, ['Customer Code', 'Customer Name', 'Contact Person', 'Phone', 'City', 'Opening Balance', 'Total Invoiced', 'Total Paid', 'Net Receivable']);
    $rows = $db->query("SELECT c.code, c.name, c.contact_person, c.phone, c.city, c.opening_balance,
                               COALESCE((SELECT SUM(debit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_invoiced,
                               COALESCE((SELECT SUM(credit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_paid,
                               (c.opening_balance + COALESCE((SELECT SUM(debit) - SUM(credit) FROM customer_ledger WHERE customer_id = c.id), 0)) AS balance_due
                        FROM customers c
                        WHERE c.is_active = 1
                        ORDER BY balance_due DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['code'],
            $r['name'],
            $r['contact_person'] ?: '-',
            $r['phone'] ?: '-',
            $r['city'] ?: '-',
            $r['opening_balance'],
            $r['total_invoiced'],
            $r['total_paid'],
            $r['balance_due']
        ]);
    }

// 4. FINANCIAL: SUPPLIER PAYABLES
} elseif ($reportType === 'supplier_payables') {
    fputcsv($output, ['Supplier Code', 'Supplier Name', 'Phone', 'Opening Balance', 'Total Purchased', 'Total Paid', 'Balance Payable']);
    $rows = $db->query("SELECT s.code, s.name, s.phone, s.opening_balance,
                               COALESCE((SELECT SUM(credit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_billed,
                               COALESCE((SELECT SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_paid,
                               (s.opening_balance + COALESCE((SELECT SUM(credit) - SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0)) AS balance_payable
                        FROM suppliers s
                        WHERE s.is_active = 1
                        ORDER BY balance_payable DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['code'],
            $r['name'],
            $r['phone'] ?: '-',
            $r['opening_balance'],
            $r['total_billed'],
            $r['total_paid'],
            $r['balance_payable']
        ]);
    }

// 5. FINANCIAL: EXPENSE BREAKDOWN
} elseif ($reportType === 'expense_breakdown') {
    fputcsv($output, ['Expense Category', 'Vouchers Issued', 'Total Amount']);
    $rows = $db->query("SELECT ec.name AS category_name, COUNT(e.id) AS voucher_count, COALESCE(SUM(e.amount), 0) AS total_amount
                        FROM expense_categories ec
                        LEFT JOIN expenses e ON ec.id = e.category_id
                        GROUP BY ec.id
                        ORDER BY total_amount DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['category_name'],
            $r['voucher_count'],
            $r['total_amount']
        ]);
    }

// 6. FINANCIAL: WORKER PERFORMANCE & LABOUR
} elseif ($reportType === 'worker_performance') {
    fputcsv($output, ['Worker Name', 'Code', 'Type', 'Department', 'Submissions', 'Total Good Output', 'Total Rejected', 'Total Labour Earned', 'Total Paid', 'Net Balance']);
    $rows = $db->query("SELECT w.name, w.code, w.worker_type, dep.name AS dept_name,
                        COUNT(pe.id) AS total_entries,
                        COALESCE(SUM(pe.good_qty), 0) AS total_good,
                        COALESCE(SUM(pe.rejected_qty), 0) AS total_rejected,
                        COALESCE((SELECT SUM(credit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_labour,
                        COALESCE((SELECT SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_paid
                        FROM workers w 
                        LEFT JOIN departments dep ON w.department_id = dep.id 
                        LEFT JOIN production_entries pe ON w.id = pe.worker_id 
                        WHERE w.is_active = 1
                        GROUP BY w.id
                        ORDER BY total_labour DESC")->fetchAll();
    foreach ($rows as $r) {
        $net = (float)$r['total_labour'] - (float)$r['total_paid'];
        fputcsv($output, [
            $r['name'],
            $r['code'],
            $r['worker_type'],
            $r['dept_name'] ?? 'General',
            $r['total_entries'],
            $r['total_good'],
            $r['total_rejected'],
            $r['total_labour'],
            $r['total_paid'],
            $net
        ]);
    }

// 7. OPERATIONAL: OPERATOR KNITTING OUTPUT
} elseif ($reportType === 'operator_output') {
    fputcsv($output, ['Entry Date', 'Operator Name', 'Operator Code', 'Shift', 'Lot Number', 'Product', 'Knitted Pieces', 'Rate Per Piece', 'Labour Earned']);
    $rows = $db->query("SELECT pe.entry_date, pe.shift, pe.good_qty, pe.rate, pe.labour_amount,
                               l.lot_number, p.name AS product_name,
                               w.name AS operator_name, w.code AS operator_code
                        FROM production_entries pe
                        JOIN lots l ON pe.lot_id = l.id
                        JOIN products p ON l.product_id = p.id
                        JOIN workers w ON pe.worker_id = w.id
                        JOIN operations o ON pe.operation_id = o.id
                        WHERE o.code = 'OP-OPERATOR' OR o.name LIKE '%Operator%' OR o.name LIKE '%Knitt%'
                        ORDER BY pe.id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['entry_date'],
            $r['operator_name'],
            $r['operator_code'],
            $r['shift'] ?: 'Day Shift',
            $r['lot_number'],
            $r['product_name'],
            $r['good_qty'],
            $r['rate'],
            $r['labour_amount']
        ]);
    }

// 8. OPERATIONAL: CUTTING REPORT
} elseif ($reportType === 'cutting_report') {
    fputcsv($output, ['Entry #', 'Date', 'Lot #', 'Cutter Name', 'Color', 'Size', 'Pieces Cut', 'Rejected', 'Labour Cost']);
    $rows = $db->query("SELECT pe.*, l.lot_number, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                        FROM production_entries pe 
                        JOIN lots l ON pe.lot_id = l.id 
                        JOIN operations o ON pe.operation_id = o.id 
                        JOIN workers w ON pe.worker_id = w.id 
                        LEFT JOIN colors c ON pe.color_id = c.id 
                        LEFT JOIN sizes s ON pe.size_id = s.id 
                        WHERE o.code = 'OP-CUT' OR o.name LIKE '%Cut%' 
                        ORDER BY pe.id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['entry_number'],
            $r['entry_date'],
            $r['lot_number'],
            $r['worker_name'],
            $r['color_name'] ?: 'All',
            $r['size_name'] ?: 'All',
            $r['good_qty'],
            $r['rejected_qty'],
            $r['labour_amount']
        ]);
    }

// 9. OPERATIONAL: QUALITY REJECTIONS
} elseif ($reportType === 'quality_rejections') {
    fputcsv($output, ['Log Date', 'Lot Number', 'Product', 'Stage / Operation', 'Color', 'Size', 'Worker', 'Rejected Pieces', 'Reason']);
    $rows = $db->query("SELECT pr.created_at, pr.quantity AS rejected_qty, pr.reason,
                               l.lot_number, p.name AS product_name, o.name AS op_name,
                               c.name AS color_name, s.name AS size_name, w.name AS worker_name
                        FROM production_rejections pr
                        JOIN lots l ON pr.lot_id = l.id
                        JOIN products p ON l.product_id = p.id
                        JOIN operations o ON pr.operation_id = o.id
                        LEFT JOIN colors c ON pr.color_id = c.id
                        LEFT JOIN sizes s ON pr.size_id = s.id
                        LEFT JOIN production_entries pe ON pr.production_entry_id = pe.id
                        LEFT JOIN workers w ON pe.worker_id = w.id
                        ORDER BY pr.id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['created_at'],
            $r['lot_number'],
            $r['product_name'],
            $r['op_name'],
            $r['color_name'] ?: '-',
            $r['size_name'] ?: '-',
            $r['worker_name'] ?: '-',
            $r['rejected_qty'],
            $r['reason'] ?: 'Defect'
        ]);
    }

// 10. OPERATIONAL: RAW MATERIAL STOCK
} elseif ($reportType === 'material_stock') {
    fputcsv($output, ['Material Code', 'Material Name', 'Category', 'Current Stock', 'Unit', 'Min Stock Level', 'Unit Cost', 'Stock Valuation', 'Status']);
    $rows = $db->query("SELECT m.*, u.code AS unit_code, (m.current_stock * m.cost_price) AS stock_value
                        FROM materials m
                        JOIN units u ON m.unit_id = u.id
                        WHERE m.is_active = 1
                        ORDER BY m.name ASC")->fetchAll();
    foreach ($rows as $r) {
        $isLow = (float)$r['current_stock'] <= (float)$r['min_stock'];
        fputcsv($output, [
            $r['code'],
            $r['name'],
            $r['category'],
            $r['current_stock'],
            $r['unit_code'],
            $r['min_stock'],
            $r['cost_price'],
            $r['stock_value'],
            $isLow ? 'LOW STOCK' : 'Healthy'
        ]);
    }

// 11. OPERATIONAL: FINISHED GOODS
} elseif ($reportType === 'finished_goods') {
    fputcsv($output, ['Product', 'Design', 'Color', 'Size', 'Lot Number', 'Warehouse', 'Current Stock', 'Dispatched Stock']);
    $rows = $db->query("SELECT p.name AS prod, d.design_number, c.name AS color, s.name AS size, l.lot_number, wh.name AS wh_name, fg.current_stock, fg.dispatched_stock 
                        FROM finished_goods_stock fg 
                        JOIN products p ON fg.product_id = p.id 
                        LEFT JOIN designs d ON fg.design_id = d.id 
                        JOIN colors c ON fg.color_id = c.id 
                        JOIN sizes s ON fg.size_id = s.id 
                        JOIN lots l ON fg.lot_id = l.id 
                        LEFT JOIN warehouses wh ON fg.warehouse_id = wh.id")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['prod'],
            $r['design_number'] ?: '-',
            $r['color'],
            $r['size'],
            $r['lot_number'],
            $r['wh_name'] ?: 'Main Warehouse',
            $r['current_stock'],
            $r['dispatched_stock']
        ]);
    }

// 12. OPERATIONAL: DISPATCHES
} elseif ($reportType === 'dispatch_report') {
    fputcsv($output, ['Challan #', 'Dispatch Date', 'Customer', 'Vehicle', 'Driver', 'Total Items', 'Total Pieces', 'Invoice Value', 'Status']);
    $rows = $db->query("SELECT d.*, c.name AS customer_name,
                               (SELECT COUNT(*) FROM dispatch_items WHERE dispatch_id = d.id) AS total_items,
                               (SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE dispatch_id = d.id) AS total_pcs
                        FROM dispatches d
                        JOIN customers c ON d.customer_id = c.id
                        ORDER BY d.id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['dispatch_number'],
            $r['dispatch_date'],
            $r['customer_name'],
            $r['vehicle_number'] ?: '-',
            $r['driver_name'] ?: '-',
            $r['total_items'],
            $r['total_pcs'],
            $r['total_amount'] ?? 0,
            $r['status']
        ]);
    }

// 13. OPERATIONAL: PENDING WORK
} elseif ($reportType === 'pending_work') {
    fputcsv($output, ['Lot Number', 'Product', 'Operation', 'Sequence', 'Required Pieces', 'Good Done', 'Pending Pieces']);
    $rows = $db->query("SELECT l.lot_number, p.name AS product_name, o.name AS op_name, lws.sequence, l.total_pieces,
                               (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS good_done
                        FROM lots l 
                        JOIN products p ON l.product_id = p.id 
                        JOIN lot_workflow_steps lws ON l.id = lws.lot_id 
                        JOIN operations o ON lws.operation_id = o.id 
                        WHERE l.status NOT IN ('Closed', 'Cancelled', 'Ready Stock', 'Dispatched') 
                        ORDER BY l.id DESC, lws.sequence ASC")->fetchAll();
    foreach ($rows as $r) {
        $pending = max(0, (int)$r['total_pieces'] - (int)$r['good_done']);
        if ($pending === 0) continue;
        fputcsv($output, [
            $r['lot_number'],
            $r['product_name'],
            $r['op_name'],
            $r['sequence'],
            $r['total_pieces'],
            $r['good_done'],
            $pending
        ]);
    }

// 14. DEFAULT: LOT REGISTER
} else {
    fputcsv($output, ['Lot Number', 'Lot Date', 'Product', 'Design Number', 'Total Pieces', 'Status', 'Supervisor']);
    $rows = $db->query("SELECT l.lot_number, l.lot_date, p.name AS prod, d.design_number, l.total_pieces, l.status, l.supervisor_name 
                        FROM lots l 
                        JOIN products p ON l.product_id = p.id 
                        LEFT JOIN designs d ON l.design_id = d.id 
                        ORDER BY l.id DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['lot_number'],
            $r['lot_date'],
            $r['prod'],
            $r['design_number'] ?: '-',
            $r['total_pieces'],
            $r['status'],
            $r['supervisor_name'] ?: '-'
        ]);
    }
}

fclose($output);
exit;
