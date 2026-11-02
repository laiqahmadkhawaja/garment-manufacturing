<?php
/**
 * Garment Manufacturing ERP - Printable Customer Delivery Challan
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$dispatchId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.code AS customer_code, c.contact_person, c.phone, c.address, c.city,
                             wh.name AS warehouse_name, u.full_name AS issuer_name 
                      FROM dispatches d 
                      JOIN customers c ON d.customer_id = c.id 
                      JOIN warehouses wh ON d.warehouse_id = wh.id 
                      LEFT JOIN users u ON d.created_by = u.id 
                      WHERE d.id = ?");
$stmt->execute([$dispatchId]);
$dispatch = $stmt->fetch();

if (!$dispatch) {
    die("Dispatch not found.");
}

$itemsStmt = $db->prepare("SELECT di.*, p.name AS product_name, p.code AS product_code, d.design_number,
                                  c.name AS color_name, s.name AS size_name, s.code AS size_code, l.lot_number 
                           FROM dispatch_items di 
                           JOIN products p ON di.product_id = p.id 
                           LEFT JOIN designs d ON di.design_id = d.id 
                           JOIN colors c ON di.color_id = c.id 
                           JOIN sizes s ON di.size_id = s.id 
                           JOIN lots l ON di.lot_id = l.id 
                           WHERE di.dispatch_id = ?");
$itemsStmt->execute([$dispatchId]);
$items = $itemsStmt->fetchAll();

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Challan - <?= clean($dispatch['challan_no']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        body { background: #fff !important; color: #000; font-size: 13px; }
        .challan-box { border: 2px solid #000; padding: 25px; max-width: 800px; margin: 0 auto; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
        @media print {
            .no-print { display: none !important; }
            .challan-box { border: 2px solid #000; }
        }
    </style>
</head>
<body class="p-4">
    <div class="no-print d-flex justify-content-between align-items-center mb-4 p-3 bg-light border rounded" style="max-width: 800px; margin: 0 auto;">
        <div>
            <h5 class="mb-0 fw-bold">Customer Delivery Challan (Bilty / Gate Pass)</h5>
            <small class="text-muted">Outward stock movement documentation</small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary fw-bold"><i class="bi bi-printer me-1"></i> Print Challan</button>
            <button onclick="window.close()" class="btn btn-outline-secondary">Close</button>
        </div>
    </div>

    <div class="challan-box">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-uppercase"><?= clean($companyName) ?></h3>
                <div class="small text-muted"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark text-white fs-6 px-3 py-2">DELIVERY CHALLAN</div>
                <div class="fw-bold fs-6 mt-1 text-primary"><?= clean($dispatch['challan_no']) ?></div>
                <div class="small text-muted">Dispatch #: <?= clean($dispatch['dispatch_number']) ?></div>
                <div class="small text-dark fw-semibold">Date: <?= format_date($dispatch['dispatch_date']) ?></div>
            </div>
        </div>

        <!-- Customer & Dispatch Info Table -->
        <table class="table table-bordered mb-4">
            <tbody>
                <tr>
                    <th class="bg-light" style="width: 20%;">Delivered To:</th>
                    <td style="width: 40%;" class="fw-bold fs-6">
                        <?= clean($dispatch['customer_name']) ?>
                        <div class="small text-muted fw-normal"><?= clean($dispatch['address'] ?: 'City Market') ?>, <?= clean($dispatch['city']) ?></div>
                        <div class="small text-muted fw-normal">Attn: <?= clean($dispatch['contact_person'] ?: 'Store Manager') ?> · Tel: <?= clean($dispatch['phone']) ?></div>
                    </td>
                    <th class="bg-light" style="width: 15%;">Transport:</th>
                    <td style="width: 25%;">
                        <div class="fw-semibold"><?= clean($dispatch['vehicle_details'] ?: 'Direct Factory Pickup') ?></div>
                        <div class="small text-muted">From: <?= clean($dispatch['warehouse_name']) ?></div>
                    </td>
                </tr>
                <?php if ($dispatch['notes']): ?>
                <tr>
                    <th class="bg-light">Special Notes:</th>
                    <td colspan="3"><?= clean($dispatch['notes']) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Itemized Products Table -->
        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">Dispatched Garment Quantities</h6>
        <table class="table table-bordered align-middle text-center mb-4">
            <thead>
                <tr class="bg-light">
                    <th>#</th>
                    <th class="text-start">Product & Design Description</th>
                    <th>Batch / Lot #</th>
                    <th>Color & Size</th>
                    <th class="text-end">Quantity (Pcs)</th>
                    <th class="text-end">Unit Rate</th>
                    <th class="text-end">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td class="text-start fw-semibold text-dark">
                            <?= clean($it['product_name']) ?>
                            <div class="small text-muted"><?= $it['design_number'] ? 'Design #' . clean($it['design_number']) : 'Standard Design' ?></div>
                        </td>
                        <td class="fw-bold text-primary">LOT #<?= clean($it['lot_number']) ?></td>
                        <td><?= clean($it['color_name']) ?> / <?= clean($it['size_name']) ?> (<?= clean($it['size_code']) ?>)</td>
                        <td class="text-end fw-bold fs-6 text-dark"><?= number_format($it['quantity']) ?> pcs</td>
                        <td class="text-end">Rs. <?= number_format($it['unit_rate'], 2) ?></td>
                        <td class="text-end fw-bold">Rs. <?= number_format($it['total_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td colspan="4" class="text-end">Total Dispatched Pieces:</td>
                    <td class="text-end fs-6 text-success"><?= number_format($dispatch['total_pieces']) ?> pcs</td>
                    <td class="text-end">Total Value:</td>
                    <td class="text-end fs-6">Rs. <?= number_format($dispatch['total_value'], 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="p-2 border bg-light small mb-4">
            <strong>Goods Condition Warranty:</strong> Goods checked, packed and dispatched in prime export condition. Any discrepancy or damage must be notified in writing within 48 hours of delivery.
        </div>

        <!-- Signature Section -->
        <div class="row pt-5 mt-4 border-top text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Prepared / Dispatched By</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Driver / Transport Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Receiver / Party Stamp & Sign</div>
            </div>
        </div>
    </div>
</body>
</html>
