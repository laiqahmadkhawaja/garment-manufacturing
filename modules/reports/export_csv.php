<?php
/**
 * Garment Manufacturing ERP - Export Reports to CSV
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$reportType = trim($_GET['type'] ?? 'lot_register');
$db = get_db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $reportType . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

if ($reportType === 'worker_performance') {
    fputcsv($output, ['Worker Name', 'Code', 'Type', 'Department', 'Submissions', 'Total Good Output', 'Total Rejected', 'Total Labour Earned', 'Total Paid', 'Net Balance']);
    $rows = $db->query("SELECT w.name, w.code, w.worker_type, dep.name AS dept_name,
                        COUNT(pe.id) AS total_entries,
                        COALESCE(SUM(pe.good_qty), 0) AS total_good,
                        COALESCE(SUM(pe.rejected_qty), 0) AS total_rejected,
                        COALESCE(SUM(pe.labour_amount), 0) AS total_labour,
                        COALESCE((SELECT SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_paid
                        FROM workers w 
                        LEFT JOIN departments dep ON w.department_id = dep.id 
                        LEFT JOIN production_entries pe ON w.id = pe.worker_id 
                        GROUP BY w.id")->fetchAll();
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
} elseif ($reportType === 'finished_goods') {
    fputcsv($output, ['Product', 'Design', 'Color', 'Size', 'Lot Number', 'Warehouse', 'Current Stock', 'Dispatched Stock']);
    $rows = $db->query("SELECT p.name AS prod, d.design_number, c.name AS color, s.name AS size, l.lot_number, wh.name AS wh_name, fg.current_stock, fg.dispatched_stock 
                        FROM finished_goods_stock fg 
                        JOIN products p ON fg.product_id = p.id 
                        LEFT JOIN designs d ON fg.design_id = d.id 
                        JOIN colors c ON fg.color_id = c.id 
                        JOIN sizes s ON fg.size_id = s.id 
                        JOIN lots l ON fg.lot_id = l.id 
                        JOIN warehouses wh ON fg.warehouse_id = wh.id")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['prod'],
            $r['design_number'] ?: '-',
            $r['color'],
            $r['size'],
            $r['lot_number'],
            $r['wh_name'],
            $r['current_stock'],
            $r['dispatched_stock']
        ]);
    }
} else {
    // Default: lot_register
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
