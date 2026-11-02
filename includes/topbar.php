<?php
/**
 * Garment Manufacturing ERP - Top Navbar Partial
 */
$currentUser = current_user();

// Check for critical pending or low stock notifications
$db = get_db();
$critCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND is_active = 1");
    $lowStockCount = (int)$stmt->fetchColumn();
    $critCount += $lowStockCount;
} catch (Exception $e) {
    $lowStockCount = 0;
}
?>
<header class="app-topbar">
    <div class="d-flex align-items-center gap-3">
        <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary d-lg-none" type="button">
            <i class="bi bi-list fs-5"></i>
        </button>
        <div class="d-none d-md-flex align-items-center text-muted small">
            <i class="bi bi-building me-1"></i>
            <span class="fw-semibold text-dark"><?= clean(get_setting('company_name', 'Garment ERP')) ?></span>
            <span class="mx-2 text-secondary">•</span>
            <span>Production Season 2026-2027</span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <!-- Fast production action pill -->
        <a href="<?= BASE_URL ?>modules/production/index.php" class="btn btn-sm btn-primary d-none d-sm-inline-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-lightning-charge-fill"></i>
            <span>Fast Entry</span>
        </a>

        <!-- Low stock / alert pill -->
        <?php if ($lowStockCount > 0): ?>
            <a href="<?= BASE_URL ?>modules/raw_materials/index.php?filter=low_stock" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1" title="Low stock materials">
                <span class="pulse-indicator"></span>
                <span class="d-none d-sm-inline"><?= $lowStockCount ?> Low Stock</span>
            </a>
        <?php endif; ?>

        <!-- User profile dropdown -->
        <div class="dropdown">
            <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 26px; height: 26px; font-size: 0.75rem; font-weight: 700;">
                    <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <span class="d-none d-md-inline fw-semibold small"><?= clean($currentUser['full_name'] ?? 'User') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><h6 class="dropdown-header"><?= clean($currentUser['role_display'] ?? 'Role') ?></h6></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/settings/index.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
            </ul>
        </div>
    </div>
</header>
