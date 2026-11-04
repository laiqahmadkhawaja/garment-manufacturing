<?php
/**
 * Garment Manufacturing ERP - Sidebar Navigation
 */
$activeNav = $activeNav ?? 'dashboard';
?>
<!-- Sidebar Backdrop for Mobile -->
<div id="sidebarBackdrop" class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 d-none" style="z-index: 1035;"></div>

<aside class="app-sidebar shadow">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-scissors"></i>
        </div>
        <div>
            <div class="brand-text">GARMENT ERP</div>
            <div class="brand-sub">Production Suite</div>
        </div>
        <button id="sidebarMobileClose" class="btn btn-sm btn-link text-white ms-auto d-lg-none p-0">
            <i class="bi bi-x-lg fs-5"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <a href="<?= BASE_URL ?>modules/dashboard/index.php" class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <!-- Production -->
        <div class="nav-section-title">Production</div>
        <a href="<?= BASE_URL ?>modules/lots/index.php" class="nav-link <?= $activeNav === 'lots' ? 'active' : '' ?>">
            <i class="bi bi-layers-fill"></i>
            <span>All Lots</span>
        </a>
        <a href="<?= BASE_URL ?>modules/lots/create.php" class="nav-link <?= $activeNav === 'create_lot' ? 'active' : '' ?>">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create New Lot</span>
        </a>
        <a href="<?= BASE_URL ?>modules/production/index.php" class="nav-link <?= $activeNav === 'production_entry' ? 'active' : '' ?>">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            <span class="fw-semibold">Production Entry</span>
        </a>
        <a href="<?= BASE_URL ?>modules/production/pending.php" class="nav-link <?= $activeNav === 'pending_work' ? 'active' : '' ?>">
            <i class="bi bi-exclamation-octagon-fill text-danger"></i>
            <span>Pending Operations</span>
        </a>
        <a href="<?= BASE_URL ?>modules/production/history.php" class="nav-link <?= $activeNav === 'production_history' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i>
            <span>Production History</span>
        </a>

        <!-- Workers & Labour -->
        <div class="nav-section-title">Workers & Labour</div>
        <a href="<?= BASE_URL ?>modules/workers/index.php" class="nav-link <?= $activeNav === 'workers' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i>
            <span>Workers & Ledgers</span>
        </a>
        <a href="<?= BASE_URL ?>modules/payments/index.php" class="nav-link <?= $activeNav === 'payments' ? 'active' : '' ?>">
            <i class="bi bi-cash-stack"></i>
            <span>Labour Payments</span>
        </a>

        <!-- Inventory -->
        <div class="nav-section-title">Inventory & Packing</div>
        <a href="<?= BASE_URL ?>modules/raw_materials/index.php" class="nav-link <?= $activeNav === 'raw_materials' ? 'active' : '' ?>">
            <i class="bi bi-boxes"></i>
            <span>Raw Materials</span>
        </a>
        <a href="<?= BASE_URL ?>modules/raw_materials/issues.php" class="nav-link <?= $activeNav === 'material_issues' ? 'active' : '' ?>">
            <i class="bi bi-box-arrow-right"></i>
            <span>Issue to Production</span>
        </a>
        <a href="<?= BASE_URL ?>modules/raw_materials/returns.php" class="nav-link <?= $activeNav === 'material_returns' ? 'active' : '' ?>">
            <i class="bi bi-box-arrow-in-left"></i>
            <span>Return from Lot</span>
        </a>
        <a href="<?= BASE_URL ?>modules/packing/index.php" class="nav-link <?= $activeNav === 'packing' ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill text-info"></i>
            <span>Finished Goods & Packing</span>
        </a>
        <a href="<?= BASE_URL ?>modules/inventory/index.php" class="nav-link <?= $activeNav === 'finished_goods' ? 'active' : '' ?>">
            <i class="bi bi-tags-fill"></i>
            <span>Finished Goods Stock</span>
        </a>

        <!-- Dispatch & Sales -->
        <div class="nav-section-title">Dispatch & Customers</div>
        <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="nav-link <?= $activeNav === 'dispatch' ? 'active' : '' ?>">
            <i class="bi bi-truck"></i>
            <span>Customer Dispatch</span>
        </a>
        <a href="<?= BASE_URL ?>modules/customers/index.php" class="nav-link <?= $activeNav === 'customers' ? 'active' : '' ?>">
            <i class="bi bi-person-badge-fill"></i>
            <span>Customer Ledgers</span>
        </a>

        <!-- Costing & Reports -->
        <div class="nav-section-title">Costing & Analytics</div>
        <a href="<?= BASE_URL ?>modules/costing/index.php" class="nav-link <?= $activeNav === 'costing' ? 'active' : '' ?>">
            <i class="bi bi-calculator-fill"></i>
            <span>Lot Costing & Profit</span>
        </a>
        <a href="<?= BASE_URL ?>modules/reports/index.php" class="nav-link <?= $activeNav === 'reports' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line-fill"></i>
            <span>Reports Center</span>
        </a>

        <!-- Settings -->
        <div class="nav-section-title">Administration</div>
        <a href="<?= BASE_URL ?>modules/settings/operations.php" class="nav-link <?= $activeNav === 'settings_ops' ? 'active' : '' ?>">
            <i class="bi bi-gear-wide-connected"></i>
            <span>Operations & Rates</span>
        </a>
        <a href="<?= BASE_URL ?>modules/settings/workflows.php" class="nav-link <?= $activeNav === 'settings_wf' ? 'active' : '' ?>">
            <i class="bi bi-diagram-3-fill"></i>
            <span>Workflow Templates</span>
        </a>
        <a href="<?= BASE_URL ?>modules/settings/products.php" class="nav-link <?= $activeNav === 'settings_prod' ? 'active' : '' ?>">
            <i class="bi bi-palette-fill"></i>
            <span>Products & Variants</span>
        </a>
        <a href="<?= BASE_URL ?>modules/settings/garment_types.php" class="nav-link <?= $activeNav === 'settings_garment_types' ? 'active' : '' ?>">
            <i class="bi bi-scissors"></i>
            <span>Garment Types (کٹائی اقسام)</span>
        </a>
        <a href="<?= BASE_URL ?>modules/settings/index.php" class="nav-link <?= $activeNav === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-sliders"></i>
            <span>Factory Settings & Users</span>
        </a>
    </nav>
</aside>
