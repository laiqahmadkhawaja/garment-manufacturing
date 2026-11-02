<?php
/**
 * Garment Manufacturing ERP - Customer Dispatches List
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Customer Dispatches';
$activeNav = 'dispatch';
$db = get_db();

// Filters
$custId = (int)($_GET['customer_id'] ?? 0);

$sql = "SELECT d.*, c.name AS customer_name, c.city, wh.name AS warehouse_name,
               (SELECT COUNT(*) FROM dispatch_items WHERE dispatch_id = d.id) AS items_count 
        FROM dispatches d 
        JOIN customers c ON d.customer_id = c.id 
        JOIN warehouses wh ON d.warehouse_id = wh.id 
        WHERE 1=1";

$params = [];
if ($custId > 0) {
    $sql .= " AND d.customer_id = ?";
    $params[] = $custId;
}

$sql .= " ORDER BY d.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$dispatches = $stmt->fetchAll();

$customers = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Customer Dispatches & Delivery Challans</h4>
                <p class="text-muted small mb-0">Finished stock shipments to parties, delivery notes and vehicle records</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/dispatch/returns.php" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-arrow-return-left"></i>
                    <span>Customer Returns</span>
                </a>
                <a href="<?= BASE_URL ?>modules/dispatch/create.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-truck me-1"></i>
                    <span>New Dispatch</span>
                </a>
            </div>
        </div>

        <!-- Dispatches Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Dispatched Shipments (<?= count($dispatches) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Dispatch #</th>
                                <th>Challan #</th>
                                <th>Date</th>
                                <th>Customer / Party</th>
                                <th>Warehouse Source</th>
                                <th>Transport / Vehicle</th>
                                <th class="text-center">Total Pieces</th>
                                <th class="text-end">Value</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dispatches)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No customer dispatches recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($dispatches as $d): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($d['dispatch_number']) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border fw-bold"><?= clean($d['challan_no']) ?></span>
                                        </td>
                                        <td><?= format_date($d['dispatch_date']) ?></td>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= clean($d['customer_name']) ?></span>
                                            <div class="text-muted small"><?= clean($d['city'] ?: 'Store') ?></div>
                                        </td>
                                        <td><?= clean($d['warehouse_name']) ?></td>
                                        <td><?= clean($d['vehicle_details'] ?: '-') ?></td>
                                        <td class="text-center fw-bold text-success fs-6"><?= number_format($d['total_pieces']) ?> pcs</td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($d['total_value']) ?></td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="<?= BASE_URL ?>modules/dispatch/challan.php?id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-1 px-2" title="Print Delivery Challan">
                                                    <i class="bi bi-printer me-1"></i> Challan
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/dispatch/returns.php?dispatch_id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-warning py-1 px-2" title="Process Return">
                                                    <i class="bi bi-arrow-return-left"></i>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
