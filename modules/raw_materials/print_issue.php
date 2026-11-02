<?php
/**
 * Garment Manufacturing ERP - Printable Material Issue Requisition Slip
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$issueId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT mi.*, l.lot_number, l.lot_date, p.name AS product_name, p.product_type, d.design_number, u.full_name AS issuer_name 
                      FROM material_issues mi 
                      JOIN lots l ON mi.lot_id = l.id 
                      JOIN products p ON l.product_id = p.id 
                      LEFT JOIN designs d ON l.design_id = d.id 
                      LEFT JOIN users u ON mi.created_by = u.id 
                      WHERE mi.id = ?");
$stmt->execute([$issueId]);
$issue = $stmt->fetch();

if (!$issue) {
    die("Issue slip not found.");
}

$itemsStmt = $db->prepare("SELECT mii.*, m.name AS material_name, m.code AS material_code, m.storage_location, u.code AS unit_code 
                           FROM material_issue_items mii 
                           JOIN materials m ON mii.material_id = m.id 
                           JOIN units u ON m.unit_id = u.id 
                           WHERE mii.issue_id = ?");
$itemsStmt->execute([$issueId]);
$items = $itemsStmt->fetchAll();

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Issue Slip - <?= clean($issue['issue_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        body { background: #fff !important; color: #000; font-size: 13px; }
        .slip-box { border: 2px solid #000; padding: 20px; max-width: 780px; margin: 0 auto; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
        @media print {
            .no-print { display: none !important; }
            .slip-box { border: 2px solid #000; }
        }
    </style>
</head>
<body class="p-4">
    <div class="no-print d-flex justify-content-between align-items-center mb-4 p-3 bg-light border rounded" style="max-width: 780px; margin: 0 auto;">
        <div>
            <h5 class="mb-0 fw-bold">Material Issue Slip</h5>
            <small class="text-muted">Factory store room dispatch authorization</small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary fw-bold"><i class="bi bi-printer me-1"></i> Print Slip</button>
            <button onclick="window.close()" class="btn btn-outline-secondary">Close</button>
        </div>
    </div>

    <div class="slip-box">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-uppercase"><?= clean($companyName) ?></h3>
                <div class="small text-muted"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark text-white fs-6 px-3 py-2">MATERIAL ISSUE SLIP</div>
                <div class="fw-bold mt-1 text-primary"><?= clean($issue['issue_number']) ?></div>
                <div class="small text-muted">Date: <?= format_date($issue['issue_date']) ?></div>
            </div>
        </div>

        <table class="table table-bordered mb-3">
            <tbody>
                <tr>
                    <th class="bg-light" style="width: 20%;">Allocated To:</th>
                    <td style="width: 30%;" class="fw-bold text-primary">LOT #<?= clean($issue['lot_number']) ?></td>
                    <th class="bg-light" style="width: 20%;">Garment / Design:</th>
                    <td style="width: 30%;"><?= clean($issue['product_name']) ?> (<?= $issue['design_number'] ? '#' . clean($issue['design_number']) : 'No Design' ?>)</td>
                </tr>
                <?php if ($issue['notes']): ?>
                <tr>
                    <th class="bg-light">Requisition Notes:</th>
                    <td colspan="3"><?= clean($issue['notes']) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">Issued Materials List</h6>
        <table class="table table-bordered align-middle text-center mb-4">
            <thead>
                <tr class="bg-light">
                    <th>#</th>
                    <th class="text-start">Material Name & Code</th>
                    <th>Storage Location</th>
                    <th class="text-end">Quantity Issued</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total Valuation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td class="text-start fw-semibold text-dark">
                            <?= clean($it['material_name']) ?>
                            <div class="small text-muted"><?= clean($it['material_code']) ?></div>
                        </td>
                        <td><?= clean($it['storage_location'] ?: 'Store') ?></td>
                        <td class="text-end fw-bold fs-6 text-danger"><?= number_format($it['quantity'], 2) ?> <?= clean($it['unit_code']) ?></td>
                        <td class="text-end">Rs. <?= number_format($it['unit_price'], 2) ?></td>
                        <td class="text-end fw-bold">Rs. <?= number_format($it['total_cost'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td colspan="5" class="text-end">Total Raw Material Cost:</td>
                    <td class="text-end fs-6">Rs. <?= number_format($issue['total_cost'], 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="row pt-5 mt-5 border-top text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Store Keeper / Issued By</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Supervisor / Received By</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Factory Manager Approval</div>
            </div>
        </div>
    </div>
</body>
</html>
