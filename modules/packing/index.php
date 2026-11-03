<?php
/**
 * Garment Manufacturing ERP - Unified Packing & Finished Goods Overview
 * Single Source of Truth: Packing is executed inside the production workflow (Operation: Packing)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$db = get_db();
$preselectedLotId = (int)($_GET['lot_id'] ?? 0);

// If a specific lot is requested for packing, seamlessly route to the lot's Packing stage in production
if ($preselectedLotId > 0) {
    $pOpStmt = $db->prepare("SELECT lws.operation_id FROM lot_workflow_steps lws 
                             JOIN operations o ON lws.operation_id = o.id 
                             WHERE lws.lot_id = ? AND (o.code = 'OP-PACK' OR o.name LIKE '%Pack%') 
                             ORDER BY lws.sequence DESC LIMIT 1");
    $pOpStmt->execute([$preselectedLotId]);
    $pOpId = (int)$pOpStmt->fetchColumn();
    if (!$pOpId) {
        $opFallback = $db->query("SELECT id FROM operations WHERE code = 'OP-PACK' OR name LIKE '%Pack%' LIMIT 1")->fetchColumn();
        $pOpId = (int)($opFallback ?: 9);
    }
    redirect("modules/production/index.php?lot_id=$preselectedLotId&operation_id=$pOpId");
}

$pageTitle = 'Finished Goods & Packing';
$activeNav = 'packing';

// Fetch default packing operation ID
$defPackOpStmt = $db->query("SELECT id FROM operations WHERE code = 'OP-PACK' OR name LIKE '%Pack%' ORDER BY id ASC LIMIT 1");
$defaultPackOpId = (int)($defPackOpStmt->fetchColumn() ?: 9);

// Fetch active lots with their packing progress
$lots = $db->query("SELECT l.id, l.lot_number, l.total_pieces, l.status, p.name AS product_name, d.design_number,
                    (SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = l.id) AS total_packed,
                    (SELECT lws.operation_id FROM lot_workflow_steps lws JOIN operations o ON lws.operation_id = o.id WHERE lws.lot_id = l.id AND (o.code = 'OP-PACK' OR o.name LIKE '%Pack%') LIMIT 1) AS lot_pack_op_id
                    FROM lots l 
                    JOIN products p ON l.product_id = p.id 
                    LEFT JOIN designs d ON l.design_id = d.id 
                    WHERE l.status != 'Closed' 
                    ORDER BY l.id DESC")->fetchAll();

// Warehouse Finished Goods Stock Summary
$totalFinishedStock = (int)$db->query("SELECT COALESCE(SUM(current_stock), 0) FROM finished_goods_stock")->fetchColumn();
$totalDispatched = (int)$db->query("SELECT COALESCE(SUM(dispatched_stock), 0) FROM finished_goods_stock")->fetchColumn();
$totalPackedEntries = (int)$db->query("SELECT COUNT(*) FROM packing_entries")->fetchColumn();

// Recent Workflow Packing Entries
$recentPacking = $db->query("SELECT pe.*, l.lot_number, p.name AS product_name, c.name AS color_name, s.name AS size_name, w.name AS worker_name, wh.name AS warehouse_name 
                             FROM packing_entries pe 
                             JOIN lots l ON pe.lot_id = l.id 
                             JOIN products p ON pe.product_id = p.id 
                             JOIN colors c ON pe.color_id = c.id 
                             JOIN sizes s ON pe.size_id = s.id 
                             LEFT JOIN workers w ON pe.packing_worker_id = w.id 
                             LEFT JOIN warehouses wh ON pe.warehouse_id = wh.id 
                             ORDER BY pe.id DESC LIMIT 15")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Top Action & Navigation Header -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Finished Goods & Packing Overview</h4>
                <p class="text-muted small mb-0">Unified workflow packing, finished inventory status, and ready-to-dispatch stock</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/production/index.php?operation_id=<?= $defaultPackOpId ?>" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>⚡ Record Workflow Packing</span>
                </a>
                <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-tags-fill"></i>
                    <span>Finished Stock</span>
                </a>
            </div>
        </div>

        <!-- Unified Architecture Notification Card -->
        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border-left: 4px solid #2563eb !important;">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="badge bg-primary p-3 rounded-circle shadow-sm">
                            <i class="bi bi-box-seam-fill text-white fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">یکجا ورک فلو پیکنگ سسٹم (Unified Production Packing)</h6>
                            <p class="text-secondary small mb-0" style="line-height: 1.5;">
                                پیکنگ کا پروسیس اب مکمل طور پر پروڈکشن ورک فلو کے ساتھ منسلک ہے۔ تمام لاٹس کی پیکنگ (کلرز کے سیٹ اور سائز وائز پیسز) 
                                براہ راست پروڈکشن انٹری کے فائنل مرحلے میں درج ہوتی ہے جہاں سے کاریگر کی مزدوری خودکار طور پر لیجر میں کریڈٹ ہو جاتی ہے 
                                اور ویئر ہاؤس کا فِنشڈ گڈز سٹاک فوری طور پر تیار مال (Ready Stock) میں جمع ہو جاتا ہے۔
                            </p>
                        </div>
                    </div>
                    <div class="flex-shrink-0">
                        <a href="<?= BASE_URL ?>modules/production/index.php?operation_id=<?= $defaultPackOpId ?>" class="btn btn-primary btn-sm px-3 py-2 fw-semibold shadow-sm">
                            <i class="bi bi-lightning-charge-fill me-1"></i> ورک فلو میں پیکنگ درج کریں
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Summary KPI Counters -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <div class="card-modern p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-semibold text-uppercase">Finished Goods Stock</span>
                            <h4 class="fw-bold text-success mb-0 mt-1"><?= number_format($totalFinishedStock) ?> <small class="text-muted fs-6 fw-normal">pcs</small></h4>
                        </div>
                        <span class="badge bg-success-subtle text-success p-3 rounded-3"><i class="bi bi-tags-fill fs-5"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card-modern p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-semibold text-uppercase">Dispatched to Clients</span>
                            <h4 class="fw-bold text-primary mb-0 mt-1"><?= number_format($totalDispatched) ?> <small class="text-muted fs-6 fw-normal">pcs</small></h4>
                        </div>
                        <span class="badge bg-primary-subtle text-primary p-3 rounded-3"><i class="bi bi-truck fs-5"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card-modern p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-semibold text-uppercase">Total Packing Logs</span>
                            <h4 class="fw-bold text-dark mb-0 mt-1"><?= number_format($totalPackedEntries) ?> <small class="text-muted fs-6 fw-normal">entries</small></h4>
                        </div>
                        <span class="badge bg-info-subtle text-info p-3 rounded-3"><i class="bi bi-card-checklist fs-5"></i></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Active Lots & Packing Progress -->
            <div class="col-12 col-xl-7">
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Active Lots Packing Progress</h6>
                            <span class="text-muted small">Tracks packing completion and stock readiness per production lot</span>
                        </div>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0" style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th>Lot Number</th>
                                        <th>Product / Design</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">Packed</th>
                                        <th class="text-center">Remaining</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lots)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No active production lots found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($lots as $l): ?>
                                            <?php 
                                                $tot = (int)$l['total_pieces'];
                                                $pck = (int)$l['total_packed'];
                                                $rem = max(0, $tot - $pck);
                                                $pct = $tot > 0 ? min(100, round(($pck / $tot) * 100)) : 0;
                                                $targetOpId = (int)($l['lot_pack_op_id'] ?: $defaultPackOpId);
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $l['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                        LOT #<?= clean($l['lot_number']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= clean($l['product_name']) ?></span>
                                                    <?php if ($l['design_number']): ?>
                                                        <span class="badge bg-light text-muted border ms-1">#<?= clean($l['design_number']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fw-bold text-secondary"><?= number_format($tot) ?></td>
                                                <td class="text-center fw-bold text-success">
                                                    <?= number_format($pck) ?>
                                                    <div class="progress mt-1" style="height: 4px; width: 60px; margin: 0 auto;">
                                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pct ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="text-center fw-bold text-warning"><?= number_format($rem) ?></td>
                                                <td class="text-center">
                                                    <?php if ($pck >= $tot && $tot > 0): ?>
                                                        <span class="badge bg-success-subtle text-success">Ready Stock</span>
                                                    <?php elseif ($pck > 0): ?>
                                                        <span class="badge bg-info-subtle text-info">Partially Packed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary">In Production</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $l['id'] ?>&operation_id=<?= $targetOpId ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm">
                                                        <i class="bi bi-box-seam"></i>
                                                        <span>Pack</span>
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

            <!-- Recent Workflow Packing Logs -->
            <div class="col-12 col-xl-5">
                <div class="card-modern h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Recent Workflow Packing Logs</h6>
                        <span class="badge bg-light text-muted border">Latest Entries</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0" style="font-size: 0.82rem;">
                                <thead>
                                    <tr>
                                        <th>Packing #</th>
                                        <th>Lot & Variant</th>
                                        <th class="text-center">Packed</th>
                                        <th class="text-end">Artisan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentPacking)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No packing recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPacking as $rp): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-primary"><?= clean($rp['packing_number']) ?></span>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= format_date($rp['packing_date']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark">LOT #<?= clean($rp['lot_number']) ?></span>
                                                    <div><?= clean($rp['color_name']) ?> (<?= clean($rp['size_name']) ?>)</div>
                                                </td>
                                                <td class="text-center fw-bold text-success fs-6">
                                                    <?= number_format($rp['packed_qty']) ?> pcs
                                                </td>
                                                <td class="text-end">
                                                    <span class="fw-semibold text-dark"><?= clean($rp['worker_name'] ?: 'In-House') ?></span>
                                                    <div class="text-muted small"><?= format_currency($rp['labour_amount']) ?></div>
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
        </div>

    </div> <!-- end app-content -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
