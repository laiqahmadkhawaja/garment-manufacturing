<?php
/**
 * Garment Manufacturing ERP - Edit Production Work Entry
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$entryId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT pe.*, l.lot_number, l.status AS lot_status, l.total_pieces AS lot_total_pieces,
                             p.name AS product_name, o.name AS op_name, o.sequence AS op_seq,
                             w.name AS worker_name, w.code AS worker_code, w.rate_per_minute,
                             c.name AS color_name, s.name AS size_name
                      FROM production_entries pe
                      JOIN lots l ON pe.lot_id = l.id
                      JOIN products p ON l.product_id = p.id
                      JOIN operations o ON pe.operation_id = o.id
                      JOIN workers w ON pe.worker_id = w.id
                      LEFT JOIN colors c ON pe.color_id = c.id
                      LEFT JOIN sizes s ON pe.size_id = s.id
                      WHERE pe.id = ?");
$stmt->execute([$entryId]);
$entry = $stmt->fetch();

if (!$entry) {
    redirect('modules/production/history.php', 'Production work entry not found.', 'danger');
}

$pageTitle = 'Edit Work Entry #' . clean($entry['entry_number']);
$activeNav = 'production_history';

// Check if lot is closed or dispatched
$lockedStatuses = ['Closed', 'Dispatched', 'Completed', 'Archived'];
$isLocked = in_array($entry['lot_status'], $lockedStatuses);

$workers = $db->query("SELECT id, name, code, rate_per_minute FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ($isLocked) {
        $error = 'This production entry is locked and cannot be edited because Lot #' . clean($entry['lot_number']) . ' is already closed/dispatched to store.';
    } else {
        $oldWorkerId = (int)$entry['worker_id'];
        $oldGoodQty = (int)$entry['good_qty'];
        $oldLabour = (float)$entry['labour_amount'];

        $newWorkerId = (int)($_POST['worker_id'] ?? $oldWorkerId);
        $newDate = trim($_POST['entry_date'] ?? $entry['entry_date']);
        $newGoodQty = max(0, (int)($_POST['good_qty'] ?? 0));
        $newRejQty = max(0, (int)($_POST['rejected_qty'] ?? 0));
        $newRewQty = max(0, (int)($_POST['rework_qty'] ?? 0));
        $newRate = max(0, (float)($_POST['rate'] ?? $entry['rate']));
        $newMins = max(0, (float)($_POST['minutes_per_piece'] ?? 0));
        $newNotes = trim($_POST['notes'] ?? '');

        $newLabour = round($newGoodQty * $newRate, 2);
        $newTotalMins = round($newGoodQty * $newMins, 2);

        if ($newGoodQty <= 0 && $newRejQty <= 0 && $newRewQty <= 0) {
            $error = 'Please enter a valid completed quantity.';
        } elseif ($newWorkerId <= 0) {
            $error = 'Please select a valid artisan/worker.';
        } else {
            $db->beginTransaction();
            try {
                // 1. Update production_entries
                $up = $db->prepare("UPDATE production_entries SET 
                    entry_date = ?,
                    worker_id = ?,
                    good_qty = ?,
                    qty_received = ?,
                    rejected_qty = ?,
                    rework_qty = ?,
                    rate = ?,
                    minutes_per_piece = ?,
                    total_minutes = ?,
                    labour_amount = ?,
                    notes = ?
                    WHERE id = ?");
                $up->execute([
                    $newDate,
                    $newWorkerId,
                    $newGoodQty,
                    $newGoodQty + $newRejQty + $newRewQty,
                    $newRejQty,
                    $newRewQty,
                    $newRate,
                    $newMins,
                    $newTotalMins,
                    $newLabour,
                    $newNotes,
                    $entryId
                ]);

                // 2. Update worker_ledger
                $wlStmt = $db->prepare("SELECT id FROM worker_ledger WHERE reference_type = 'PRODUCTION_ENTRY' AND reference_id = ?");
                $wlStmt->execute([$entryId]);
                $ledgerEntryId = $wlStmt->fetchColumn();

                $desc = "Production entry on Lot #" . $entry['lot_number'] . " (" . $newGoodQty . " pcs @ Rs. " . number_format($newRate, 2) . ")";

                if ($ledgerEntryId) {
                    $db->prepare("UPDATE worker_ledger SET 
                        worker_id = ?, 
                        transaction_date = ?, 
                        credit = ?, 
                        description = ? 
                        WHERE id = ?")
                       ->execute([
                           $newWorkerId, 
                           $newDate, 
                           $newLabour, 
                           $desc, 
                           $ledgerEntryId
                       ]);
                } else {
                    // Create if missing
                    $db->prepare("INSERT INTO worker_ledger 
                        (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                        VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, 0.00, ?, ?)")
                       ->execute([
                           $newWorkerId,
                           $newDate,
                           $entryId,
                           $entry['lot_id'],
                           $entry['operation_id'],
                           $newLabour,
                           $desc,
                           $_SESSION['user_id'] ?? null
                       ]);
                }

                // 3. Recompute running balance for affected workers
                recompute_worker_running_balance($db, $newWorkerId);
                if ($newWorkerId !== $oldWorkerId) {
                    recompute_worker_running_balance($db, $oldWorkerId);
                }

                // 4. Update element entries delta if operator entry
                $deltaQty = $newGoodQty - $oldGoodQty;
                if ($deltaQty != 0) {
                    $db->prepare("UPDATE lot_operator_element_entries 
                                  SET total_pieces = GREATEST(0, LEAST(required_pieces, total_pieces + ?)),
                                      operator1_pieces = GREATEST(0, LEAST(required_pieces, operator1_pieces + ?))
                                  WHERE lot_id = ?")
                       ->execute([$deltaQty, $deltaQty, $entry['lot_id']]);
                }

                log_audit('EDIT_PRODUCTION_ENTRY', 'production_entries', $entryId, $entry, [
                    'good_qty' => $newGoodQty,
                    'labour_amount' => $newLabour,
                    'worker_id' => $newWorkerId
                ]);

                $db->commit();
                redirect("modules/production/history.php?lot_id=" . $entry['lot_id'], "Production entry #" . $entry['entry_number'] . " updated successfully! Ledger & balances reconciled.");
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to update production entry: ' . $e->getMessage();
            }
        }
    }
}

/**
 * Recompute running balance for worker ledger chronologically
 */
function recompute_worker_running_balance(PDO $db, int $wId): void {
    $stmt = $db->prepare("SELECT opening_balance FROM workers WHERE id = ?");
    $stmt->execute([$wId]);
    $balance = (float)$stmt->fetchColumn();

    $entriesStmt = $db->prepare("SELECT id, debit, credit FROM worker_ledger WHERE worker_id = ? ORDER BY transaction_date ASC, id ASC");
    $entriesStmt->execute([$wId]);
    $entries = $entriesStmt->fetchAll();

    $upStmt = $db->prepare("UPDATE worker_ledger SET running_balance = ? WHERE id = ?");
    foreach ($entries as $row) {
        $balance += ((float)$row['credit'] - (float)$row['debit']);
        $upStmt->execute([$balance, $row['id']]);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/production/history.php">Production History</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $entry['lot_id'] ?>">LOT #<?= clean($entry['lot_number']) ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= clean($entry['entry_number']) ?></li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0 text-dark">
                    Edit Production Work Entry: <span class="text-primary font-monospace"><?= clean($entry['entry_number']) ?></span>
                </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to History
                </a>
            </div>
        </div>

        <?php if ($isLocked): ?>
            <div class="alert alert-warning border shadow-sm p-3 mb-4 rounded-3">
                <div class="d-flex align-items-center gap-2 text-warning-emphasis fw-bold mb-1">
                    <i class="bi bi-lock-fill fs-5"></i>
                    <span>Entry Locked (لاٹ مکمل یا اسٹور پر ڈسپیچ ہو چکی ہے)</span>
                </div>
                <div class="text-muted small">
                    اس لاٹ کا موجودہ سٹیٹس <strong><?= clean($entry['lot_status']) ?></strong> ہے۔ تکمیل اور ڈسپیچ کے بعد ریکارڈز کو تبدیل نہیں کیا جا سکتا تاکہ فیکٹری انوینٹری اور فنانشل لیجر کا حساب محفوظ رہے۔
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= clean($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left: Edit Form -->
            <div class="col-12 col-lg-8">
                <div class="card-modern shadow-sm border-0">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-pencil-square text-primary me-2"></i> Work & Labour Details
                        </h6>
                        <span class="badge bg-light text-dark border"><?= clean($entry['op_name']) ?></span>
                    </div>
                    <div class="card-modern-body p-4">
                        <form method="POST" action="" id="editEntryForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold text-muted">Entry Date *</label>
                                    <input type="date" name="entry_date" class="form-control form-control-sm fw-bold" required value="<?= clean($_POST['entry_date'] ?? $entry['entry_date']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold text-muted">Assigned Artisan / Worker *</label>
                                    <select name="worker_id" id="workerSelect" class="form-select form-select-sm fw-bold" required <?= $isLocked ? 'disabled' : '' ?>>
                                        <?php 
                                        $curWId = (int)($_POST['worker_id'] ?? $entry['worker_id']);
                                        foreach ($workers as $w): ?>
                                            <option value="<?= $w['id'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>" <?= $curWId === (int)$w['id'] ? 'selected' : '' ?>>
                                                <?= clean($w['name']) ?> (<?= clean($w['code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold text-muted">Good Produced Qty (تیار شدہ) *</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="good_qty" id="goodQty" class="form-control form-control-sm text-center fw-bold text-success fs-5" min="0" required value="<?= clean($_POST['good_qty'] ?? $entry['good_qty']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                        <span class="input-group-text">pcs</span>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label small fw-semibold text-muted">Rejected Qty (رد شدہ)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="rejected_qty" id="rejQty" class="form-control form-control-sm text-center fw-bold text-danger" min="0" value="<?= clean($_POST['rejected_qty'] ?? $entry['rejected_qty']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                        <span class="input-group-text">pcs</span>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label small fw-semibold text-muted">Rework Qty (دوبارہ ٹھیک)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="rework_qty" id="rewQty" class="form-control form-control-sm text-center fw-bold text-warning" min="0" value="<?= clean($_POST['rework_qty'] ?? $entry['rework_qty']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                        <span class="input-group-text">pcs</span>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded-3 border mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small fw-bold text-dark"><i class="bi bi-calculator text-primary me-1"></i> Rate & SAM Calculation Scheme</span>
                                    <span class="text-muted small">Labour = Good Qty × Rate</span>
                                </div>
                                <div class="row g-2 align-items-center">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 11px;">SAM Minutes / Piece</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="minutes_per_piece" id="samMinutes" class="form-control form-control-sm text-center fw-bold text-primary" step="0.1" min="0" value="<?= clean($_POST['minutes_per_piece'] ?? $entry['minutes_per_piece']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                            <span class="input-group-text px-1 small text-muted">min</span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 11px;">Piece Rate (Rs./pc) *</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text px-1 small text-muted">Rs</span>
                                            <input type="number" name="rate" id="pieceRate" class="form-control form-control-sm text-center fw-bold text-dark" step="0.25" min="0" required value="<?= clean($_POST['rate'] ?? $entry['rate']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                            <span class="input-group-text px-1 small text-muted">/pc</span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4 text-md-end">
                                        <div class="text-muted small" style="font-size: 11px;">Total Calculated Labour:</div>
                                        <div class="fs-4 fw-bold text-success" id="lblLiveLabour">
                                            Rs. <?= number_format($entry['labour_amount'], 2) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-muted">Notes / Element Breakdown Log</label>
                                <textarea name="notes" rows="3" class="form-control form-control-sm" placeholder="Details of parts produced or reason for modification..." <?= $isLocked ? 'disabled' : '' ?>><?= clean($_POST['notes'] ?? $entry['notes']) ?></textarea>
                            </div>

                            <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                                <a href="<?= BASE_URL ?>modules/production/history.php?lot_id=<?= $entry['lot_id'] ?>" class="btn btn-sm btn-outline-secondary px-3">
                                    <i class="bi bi-x-circle me-1"></i> Cancel
                                </a>
                                <?php if (!$isLocked): ?>
                                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold shadow-sm">
                                        <i class="bi bi-save me-1"></i> Update Production Entry & Reconcile
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-secondary px-4 fw-bold" disabled>
                                        <i class="bi bi-lock-fill me-1"></i> Locked
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right: Lot & Context Summary -->
            <div class="col-12 col-lg-4">
                <div class="card-modern shadow-sm border-0 mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-info-circle text-primary me-2"></i> Batch Context
                        </h6>
                    </div>
                    <div class="card-modern-body p-3">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted small">Lot Number:</td>
                                    <td class="text-end fw-bold">
                                        <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $entry['lot_id'] ?>" class="text-decoration-none">
                                            LOT #<?= clean($entry['lot_number']) ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Lot Status:</td>
                                    <td class="text-end">
                                        <span class="badge bg-light text-dark border"><?= clean($entry['lot_status']) ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Product:</td>
                                    <td class="text-end fw-semibold"><?= clean($entry['product_name']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Operation:</td>
                                    <td class="text-end fw-bold text-dark"><?= clean($entry['op_name']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Color / Size:</td>
                                    <td class="text-end text-muted">
                                        <?= clean($entry['color_name'] ?: 'Batch / Parts') ?> <?= clean($entry['size_name'] ? "({$entry['size_name']})" : '') ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted small">Shift:</td>
                                    <td class="text-end fw-semibold"><?= clean($entry['shift']) ?></td>
                                </tr>
                                <tr class="border-top">
                                    <td class="text-muted small">Original Labour:</td>
                                    <td class="text-end fw-bold text-primary">Rs. <?= number_format($entry['labour_amount'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-modern shadow-sm border-0 bg-light">
                    <div class="card-modern-body p-3 small text-muted">
                        <div class="fw-bold text-dark mb-1">
                            <i class="bi bi-shield-check text-success me-1"></i> Balance Guarantee
                        </div>
                        جب آپ انٹری کو اپڈیٹ کرتے ہیں تو ورکر لیجر کی متعلقہ ٹرانزیکشن، کمائی اور رننگ بیلنس تاریخ کے لحاظ سے خودکار دوبارہ کیلکولیٹ ہو جاتا ہے تاکہ کوئی حسابی فرق نہ آئے۔
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const goodInp = document.getElementById('goodQty');
    const rateInp = document.getElementById('pieceRate');
    const samInp = document.getElementById('samMinutes');
    const workerSel = document.getElementById('workerSelect');
    const lblLabour = document.getElementById('lblLiveLabour');

    function recalcLiveLabour() {
        const qty = parseInt(goodInp?.value) || 0;
        const rate = parseFloat(rateInp?.value) || 0;
        const total = qty * rate;
        if (lblLabour) {
            lblLabour.textContent = 'Rs. ' + total.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    if (goodInp) goodInp.addEventListener('input', recalcLiveLabour);
    if (rateInp) rateInp.addEventListener('input', recalcLiveLabour);

    if (samInp) {
        samInp.addEventListener('input', function() {
            const workerOpt = workerSel ? workerSel.selectedOptions[0] : null;
            const minRate = workerOpt ? (parseFloat(workerOpt.dataset.minrate) || 2.50) : 2.50;
            const sam = parseFloat(this.value) || 0;
            if (sam > 0 && rateInp) {
                rateInp.value = (sam * minRate).toFixed(2);
                recalcLiveLabour();
            }
        });
    }

    if (workerSel) {
        workerSel.addEventListener('change', function() {
            const workerOpt = this.selectedOptions[0];
            const minRate = workerOpt ? (parseFloat(workerOpt.dataset.minrate) || 2.50) : 2.50;
            const sam = parseFloat(samInp ? samInp.value : 0) || 0;
            if (sam > 0 && rateInp) {
                rateInp.value = (sam * minRate).toFixed(2);
                recalcLiveLabour();
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
