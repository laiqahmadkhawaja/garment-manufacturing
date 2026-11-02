<?php
/**
 * Garment Manufacturing ERP - Production Costing & Profitability
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Lot Production Costing';
$activeNav = 'costing';
$db = get_db();

// Fetch all lots with calculated material cost, labour cost, and unit cost
$sql = "SELECT l.*, p.name AS product_name, d.design_number,
               COALESCE((SELECT SUM(total_cost) FROM material_issues WHERE lot_id = l.id), 0) AS mat_issued_cost,
               COALESCE((SELECT SUM(total_cost) FROM material_returns WHERE lot_id = l.id), 0) AS mat_returned_cost,
               COALESCE((SELECT SUM(labour_amount) FROM production_entries WHERE lot_id = l.id), 0) AS prod_labour_cost,
               COALESCE((SELECT SUM(labour_amount) FROM packing_entries WHERE lot_id = l.id), 0) AS pack_labour_cost,
               COALESCE((SELECT SUM(packed_qty) FROM packing_entries WHERE lot_id = l.id), 0) AS total_packed,
               COALESCE((SELECT SUM(total_amount) FROM dispatch_items WHERE lot_id = l.id), 0) AS dispatch_revenue
        FROM lots l
        JOIN products p ON l.product_id = p.id
        LEFT JOIN designs d ON l.design_id = d.id
        ORDER BY l.id DESC";

$costings = $db->query($sql)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Costing & Unit Economics</h4>
                <p class="text-muted small mb-0">Calculates actual raw material consumption + operation labour costs per piece</p>
            </div>
        </div>

        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Batch Cost Analysis</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Lot Number</th>
                                <th>Product & Design</th>
                                <th class="text-center">Batch Pcs</th>
                                <th class="text-end">Material Cost</th>
                                <th class="text-end">Prod. Labour</th>
                                <th class="text-end">Packing Labour</th>
                                <th class="text-end">Total Batch Cost</th>
                                <th class="text-end">Cost / Piece</th>
                                <th class="text-end">Revenue Invoiced</th>
                                <th class="text-end">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($costings)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No production lots found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($costings as $c): 
                                    $netMat = max(0, (float)$c['mat_issued_cost'] - (float)$c['mat_returned_cost']);
                                    $prodLabour = (float)$c['prod_labour_cost'];
                                    $packLabour = (float)$c['pack_labour_cost'];
                                    $totCost = $netMat + $prodLabour + $packLabour;
                                    $pcs = (int)$c['total_pieces'];
                                    $unitCost = $pcs > 0 ? ($totCost / $pcs) : 0;
                                    $revenue = (float)$c['dispatch_revenue'];
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $c['id'] ?>#tab-costing" class="fw-bold text-primary text-decoration-none">
                                                LOT #<?= clean($c['lot_number']) ?>
                                            </a>
                                            <div class="text-muted small"><?= format_date($c['lot_date']) ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= clean($c['product_name']) ?></span>
                                            <div class="text-muted small"><?= $c['design_number'] ? 'Design #' . clean($c['design_number']) : 'No Design' ?></div>
                                        </td>
                                        <td class="text-center fw-bold"><?= number_format($pcs) ?></td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($netMat) ?></td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($prodLabour) ?></td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($packLabour) ?></td>
                                        <td class="text-end fw-bold fs-6 text-primary"><?= format_currency($totCost) ?></td>
                                        <td class="text-end fw-bold fs-6 text-dark bg-light"><?= format_currency($unitCost) ?></td>
                                        <td class="text-end fw-bold text-success"><?= format_currency($revenue) ?></td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $c['id'] ?>#tab-costing" class="btn btn-sm btn-outline-secondary py-1 px-2">
                                                <i class="bi bi-eye"></i> Cost Sheet
                                            </a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
