<?php
/**
 * Garment Manufacturing ERP - Printable Lot Assignment Sheet / Job Sheet (Traveller)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             d.design_number, d.name AS design_name 
                      FROM lots l
                      JOIN products p ON l.product_id = p.id
                      LEFT JOIN designs d ON l.design_id = d.id
                      WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    die("Lot not found.");
}

// Variants
$vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, s.name AS size_name, s.code AS size_code 
                       FROM lot_variants lv 
                       JOIN colors c ON lv.color_id = c.id 
                       JOIN sizes s ON lv.size_id = s.id 
                       WHERE lv.lot_id = ? 
                       ORDER BY c.name ASC, s.sort_order ASC");
$vStmt->execute([$lotId]);
$variants = $vStmt->fetchAll();

// Distinct colors & sizes for matrix display
$colorList = [];
$sizeList = [];
$matrix = [];
foreach ($variants as $v) {
    $colorList[$v['color_id']] = $v['color_name'];
    $sizeList[$v['size_id']] = $v['size_code'];
    $matrix[$v['color_id']][$v['size_id']] = $v['quantity'];
}

// Workflow steps
$wStmt = $db->prepare("SELECT lws.*, o.name AS op_name, o.code AS op_code, o.default_rate, dep.name AS dept_name,
                       (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS good_done,
                       (SELECT COALESCE(SUM(rejected_qty), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS rej_done,
                       (SELECT MAX(entry_date) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS last_entry_date,
                       (SELECT GROUP_CONCAT(DISTINCT w.name ORDER BY w.name ASC SEPARATOR ', ') 
                        FROM production_entries pe 
                        JOIN workers w ON pe.worker_id = w.id 
                        WHERE pe.lot_id = lws.lot_id AND pe.operation_id = lws.operation_id) AS worker_names
                       FROM lot_workflow_steps lws
                       JOIN operations o ON lws.operation_id = o.id
                       LEFT JOIN departments dep ON o.department_id = dep.id
                       WHERE lws.lot_id = ?
                       ORDER BY lws.sequence ASC");
$wStmt->execute([$lotId]);
$steps = $wStmt->fetchAll();

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Job Sheet - Lot #<?= clean($lot['lot_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
    <style>
        body { background: #fff !important; color: #000; font-size: 13px; }
        .sheet-box { border: 2px solid #000; padding: 15px; margin-bottom: 20px; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
        @media print {
            .no-print { display: none !important; }
            .sheet-box { border: 2px solid #000; }
        }
    </style>
</head>
<body class="p-4">
    <!-- Controls (Hidden in Print) -->
    <div class="no-print d-flex justify-content-between align-items-center mb-4 p-3 bg-light border rounded">
        <div>
            <h5 class="mb-0 fw-bold">Lot Production Job Sheet / Assignment Traveller</h5>
            <small class="text-muted">Use this sheet on the factory floor for tracking stages</small>
        </div>
        <div class="d-flex gap-2">
            <button type="button" onclick="window.print()" class="btn btn-primary fw-bold">
                <i class="bi bi-printer me-1"></i> Print Sheet
            </button>
            <button type="button" onclick="closePrintView('<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?? '' ?>')" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Printable Job Sheet Container -->
    <div class="sheet-box">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-uppercase"><?= clean($companyName) ?></h3>
                <div class="small text-muted"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark text-white fs-5 px-3 py-2">LOT #<?= clean($lot['lot_number']) ?></div>
                <div class="small fw-semibold mt-1">Date: <?= format_date($lot['lot_date']) ?></div>
            </div>
        </div>

        <!-- Meta Info Grid -->
        <table class="table table-bordered mb-3">
            <tbody>
                <tr>
                    <td class="fw-bold bg-light" style="width: 15%;">Product:</td>
                    <td style="width: 35%;"><?= clean($lot['product_name']) ?> (<?= clean($lot['product_type']) ?>)</td>
                    <td class="fw-bold bg-light" style="width: 15%;">Design Number:</td>
                    <td style="width: 35%;"><?= $lot['design_number'] ? '#' . clean($lot['design_number']) : 'Pending' ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Total Pieces:</td>
                    <td class="fw-bold fs-6"><?= number_format($lot['total_pieces']) ?> Pcs</td>
                    <td class="fw-bold bg-light">Variants:</td>
                    <td><?= $lot['total_colors'] ?> Colors / <?= $lot['total_sizes'] ?> Sizes</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Supervisor:</td>
                    <td><?= clean($lot['supervisor_name'] ?: 'General Production') ?></td>
                    <td class="fw-bold bg-light">Expected Date:</td>
                    <td><?= format_date($lot['expected_date']) ?></td>
                </tr>
                <?php if ($lot['instructions']): ?>
                <tr>
                    <td class="fw-bold bg-light">Instructions:</td>
                    <td colspan="3"><?= clean($lot['instructions']) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Color & Size Matrix -->
        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">1. Color & Size Distribution</h6>
        <table class="table table-bordered text-center mb-4">
            <thead>
                <tr class="bg-light">
                    <th class="text-start">Color</th>
                    <?php foreach ($sizeList as $sId => $sCode): ?>
                        <th><?= clean($sCode) ?></th>
                    <?php endforeach; ?>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($colorList as $cId => $cName): 
                    $cTot = 0;
                ?>
                    <tr>
                        <td class="text-start fw-semibold"><?= clean($cName) ?></td>
                        <?php foreach ($sizeList as $sId => $sCode): 
                            $q = $matrix[$cId][$sId] ?? 0;
                            $cTot += $q;
                        ?>
                            <td><?= $q > 0 ? $q : '-' ?></td>
                        <?php endforeach; ?>
                        <td class="fw-bold bg-light"><?= $cTot ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td class="text-start">Total</td>
                    <?php foreach ($sizeList as $sId => $sCode): 
                        $sTot = 0;
                        foreach ($colorList as $cId => $cName) {
                            $sTot += ($matrix[$cId][$sId] ?? 0);
                        }
                    ?>
                        <td><?= $sTot ?></td>
                    <?php endforeach; ?>
                    <td class="fs-6"><?= number_format($lot['total_pieces']) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Floor Traveller Operations Table -->
        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">2. Sequential Production Stages & Artisan Work Log</h6>
        <table class="table table-bordered align-middle mb-4">
            <thead>
                <tr class="bg-light text-center">
                    <th style="width: 5%;">Seq</th>
                    <th style="width: 20%;" class="text-start">Operation</th>
                    <th style="width: 10%;">Rate</th>
                    <th style="width: 20%;">Assigned Artisan / Worker</th>
                    <th style="width: 10%;">Qty Done</th>
                    <th style="width: 10%;">Rej.</th>
                    <th style="width: 10%;">Date</th>
                    <th style="width: 15%;">Artisan / Sup Sign</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($steps as $st): ?>
                    <tr>
                        <td class="text-center fw-bold"><?= $st['sequence'] ?></td>
                        <td class="fw-semibold">
                            <?= clean($st['op_name']) ?>
                            <div class="small text-muted" style="font-size: 10px;"><?= clean($st['dept_name']) ?></div>
                        </td>
                        <td class="text-center">Rs. <?= number_format($st['default_rate'], 2) ?></td>
                        <td>
                            <?php if (!empty($st['worker_names'])): ?>
                                <span class="fw-bold text-dark"><?= clean($st['worker_names']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">____________________</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?= (int)$st['good_done'] > 0 ? number_format($st['good_done']) : '' ?></td>
                        <td class="text-center text-danger fw-semibold"><?= (int)($st['rej_done'] ?? 0) > 0 ? number_format($st['rej_done']) : '' ?></td>
                        <td class="text-center small"><?= !empty($st['last_entry_date']) ? format_date($st['last_entry_date']) : '' ?></td>
                        <td class="text-center"><span class="text-muted">________________</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Sign-off section -->
        <div class="row pt-4 mt-4 border-top text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Cutting Master Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Floor Supervisor Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Factory Manager Approval</div>
            </div>
        </div>
    </div>

    <script>
    function closePrintView(fallbackUrl) {
        window.close();
        setTimeout(function() {
            if (!window.closed) {
                window.location.href = fallbackUrl;
            }
        }, 150);
    }
    </script>
</body>
</html>
