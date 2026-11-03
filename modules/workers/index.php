<?php
/**
 * Garment Manufacturing ERP - Workers List & 1-Click Balance
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Workers & Artisans';
$activeNav = 'workers';
$db = get_db();

// Fetch workers with calculated earnings, payments and running balances
$sql = "SELECT w.*, dep.name AS department_name,
               COALESCE((SELECT SUM(credit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_credit,
               COALESCE((SELECT SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_debit,
               (SELECT COUNT(DISTINCT lot_id) FROM production_entries WHERE worker_id = w.id) AS lots_worked
        FROM workers w
        LEFT JOIN departments dep ON w.department_id = dep.id
        WHERE w.is_active = 1
        ORDER BY w.name ASC";

$workers = $db->query($sql)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Artisans & Worker Ledgers</h4>
                <p class="text-muted small mb-0">Track total earnings, payments, advance loans and real-time net payables</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/payments/create.php" class="btn btn-outline-success btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-cash"></i>
                    <span>Disburse Payment</span>
                </a>
                <a href="<?= BASE_URL ?>modules/workers/create.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Register Worker</span>
                </a>
            </div>
        </div>

        <!-- Workers Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Registered Workers (<?= count($workers) ?>)</h6>
                <span class="text-muted small">Click any balance to open individual worker ledger</span>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Worker Name</th>
                                <th>Type & Dept</th>
                                <th>Phone</th>
                                <th class="text-center">Lots Worked</th>
                                <th class="text-end">Total Earnings</th>
                                <th class="text-end">Paid to Date</th>
                                <th class="text-end">Current Balance</th>
                                <th>Balance Nature</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($workers)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No workers registered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($workers as $w): 
                                    $earnings = (float)$w['total_credit'];
                                    $paid = (float)$w['total_debit'];
                                    $net = $earnings - $paid;

                                    $isPayable = ($net > 0);
                                    $isAdvance = ($net < 0);
                                    $isZero = ($net == 0);
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $w['id'] ?>" class="fw-bold text-primary text-decoration-none fs-6">
                                                <?= clean($w['name']) ?>
                                            </a>
                                            <div class="text-muted small">Code: <?= clean($w['code']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= clean($w['worker_type']) ?></div>
                                            <div class="text-muted small">
                                                <?= clean($w['department_name'] ?? 'General') ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size: 10px;">
                                                    Rs. <?= number_format($w['rate_per_minute'] ?? 2.50, 2) ?>/min
                                                </span>
                                            </div>
                                        </td>
                                        <td><?= clean($w['phone'] ?: '-') ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border"><?= $w['lots_worked'] ?> lots</span>
                                        </td>
                                        <td class="text-end fw-semibold text-dark">
                                            <?= format_currency($earnings) ?>
                                        </td>
                                        <td class="text-end fw-semibold text-secondary">
                                            <?= format_currency($paid) ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $w['id'] ?>#ledger" class="fw-bold fs-6 text-decoration-none <?= $isPayable ? 'text-danger' : ($isAdvance ? 'text-success' : 'text-muted') ?>">
                                                <?= format_currency(abs($net)) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($isPayable): ?>
                                                <span class="badge bg-danger-subtle text-danger fw-bold">
                                                    <i class="bi bi-arrow-up-right me-1"></i> Payable to Worker
                                                </span>
                                            <?php elseif ($isAdvance): ?>
                                                <span class="badge bg-success-subtle text-success fw-bold">
                                                    <i class="bi bi-arrow-down-left me-1"></i> Advance / Receivable
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">Settled (Rs. 0)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $w['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Worker Profile & Ledger">
                                                    <i class="bi bi-journal-text"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/payments/create.php?worker_id=<?= $w['id'] ?>" class="btn btn-sm btn-outline-success py-1 px-2" title="Record Payment">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/workers/statement.php?id=<?= $w['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Print Worker Statement">
                                                    <i class="bi bi-printer"></i>
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
