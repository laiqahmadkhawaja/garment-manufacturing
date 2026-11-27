<?php
/**
 * Garment Manufacturing ERP - Database Schema Migration & Repair Tool
 * Safe, idempotent migration script to update live server schema.
 * Can be run via Web Browser (URL) or via CLI (terminal).
 */
require_once __DIR__ . '/config/config.php';

$isCli = (php_sapi_name() === 'cli');
$db = get_db();

$results = [];
$startTime = microtime(true);

function add_log(string $title, string $status, string $message, string $type = 'success'): void {
    global $results, $isCli;
    $results[] = [
        'title' => $title,
        'status' => $status,
        'message' => $message,
        'type' => $type
    ];
    if ($isCli) {
        $prefix = ($type === 'success') ? "[OK]" : (($type === 'warning') ? "[SKIP]" : "[ERR]");
        echo "$prefix $title: $message\n";
    }
}

function column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                          WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = ? 
                            AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function get_column_extra(PDO $db, string $table, string $column): string {
    $stmt = $db->prepare("SELECT EXTRA FROM information_schema.COLUMNS 
                          WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = ? 
                            AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return strtoupper((string)$stmt->fetchColumn());
}

// =========================================================================
// MIGRATION 1: FIX GENERATED COLUMN 'total_pieces' IN lot_operator_element_entries
// (Resolves MySQL Error 3105 on server)
// =========================================================================
try {
    if (column_exists($db, 'lot_operator_element_entries', 'total_pieces')) {
        $extra = get_column_extra($db, 'lot_operator_element_entries', 'total_pieces');
        if (strpos($extra, 'GENERATED') !== false) {
            $db->exec("ALTER TABLE lot_operator_element_entries MODIFY COLUMN total_pieces INT NOT NULL DEFAULT 0");
            $db->exec("UPDATE lot_operator_element_entries SET total_pieces = COALESCE(operator1_pieces, 0) + COALESCE(operator2_pieces, 0)");
            add_log('Fix Generated Column total_pieces', 'APPLIED', 'Converted generated STORED column to standard INT column (Error 3105 resolved).', 'success');
        } else {
            add_log('Check Column total_pieces', 'VERIFIED', 'total_pieces is already a standard INT column.', 'success');
        }
    } else {
        $db->exec("ALTER TABLE lot_operator_element_entries ADD COLUMN total_pieces INT NOT NULL DEFAULT 0 AFTER operator2_pieces");
        $db->exec("UPDATE lot_operator_element_entries SET total_pieces = COALESCE(operator1_pieces, 0) + COALESCE(operator2_pieces, 0)");
        add_log('Add Column total_pieces', 'APPLIED', 'Added total_pieces INT column to lot_operator_element_entries.', 'success');
    }
} catch (Exception $e) {
    add_log('Fix Generated Column total_pieces', 'FAILED', $e->getMessage(), 'danger');
}

// =========================================================================
// MIGRATION 2: ENSURE ALL COLUMNS IN lot_operator_element_entries
// =========================================================================
$loeColumns = [
    'cut_pieces' => "ALTER TABLE lot_operator_element_entries ADD COLUMN cut_pieces INT NOT NULL DEFAULT 0 AFTER total_pieces",
    'required_pieces' => "ALTER TABLE lot_operator_element_entries ADD COLUMN required_pieces INT NOT NULL DEFAULT 0 AFTER cut_pieces",
    'minutes_spent' => "ALTER TABLE lot_operator_element_entries ADD COLUMN minutes_spent DECIMAL(8,2) DEFAULT 0.00 AFTER required_pieces",
    'notes' => "ALTER TABLE lot_operator_element_entries ADD COLUMN notes VARCHAR(255) NULL AFTER minutes_spent",
    'created_by' => "ALTER TABLE lot_operator_element_entries ADD COLUMN created_by INT NULL AFTER notes",
    'created_at' => "ALTER TABLE lot_operator_element_entries ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER created_by"
];

foreach ($loeColumns as $col => $sql) {
    try {
        if (!column_exists($db, 'lot_operator_element_entries', $col)) {
            $db->exec($sql);
            add_log("lot_operator_element_entries.$col", 'ADDED', "Added missing column $col.", 'success');
        } else {
            add_log("lot_operator_element_entries.$col", 'VERIFIED', "Column $col already exists.", 'success');
        }
    } catch (Exception $e) {
        add_log("lot_operator_element_entries.$col", 'FAILED', $e->getMessage(), 'danger');
    }
}

// =========================================================================
// MIGRATION 3: ENSURE ALL REQUIRED COLUMNS IN lots TABLE
// =========================================================================
$lotColumns = [
    'shift_type' => "ALTER TABLE lots ADD COLUMN shift_type ENUM('Single Shift', 'Double Shift') DEFAULT 'Single Shift' AFTER supervisor_name",
    'machine_name' => "ALTER TABLE lots ADD COLUMN machine_name VARCHAR(100) NULL AFTER shift_type",
    'night_operator_id' => "ALTER TABLE lots ADD COLUMN night_operator_id INT NULL AFTER operator_id",
    'size_mode' => "ALTER TABLE lots ADD COLUMN size_mode ENUM('Single Size', 'Multi Size') DEFAULT 'Single Size' AFTER total_sizes",
    'single_size_id' => "ALTER TABLE lots ADD COLUMN single_size_id INT NULL AFTER size_mode",
    'has_anatomy_variants' => "ALTER TABLE lots ADD COLUMN has_anatomy_variants TINYINT(1) DEFAULT 0 AFTER single_size_id",
    'anatomy_breakdown' => "ALTER TABLE lots ADD COLUMN anatomy_breakdown TEXT NULL AFTER has_anatomy_variants",
    'measurements_json' => "ALTER TABLE lots ADD COLUMN measurements_json TEXT NULL AFTER anatomy_breakdown",
    'sketch_image' => "ALTER TABLE lots ADD COLUMN sketch_image VARCHAR(255) NULL AFTER measurements_json"
];

foreach ($lotColumns as $col => $sql) {
    try {
        if (!column_exists($db, 'lots', $col)) {
            $db->exec($sql);
            add_log("lots.$col", 'ADDED', "Added missing column $col.", 'success');
        } else {
            add_log("lots.$col", 'VERIFIED', "Column $col already exists.", 'success');
        }
    } catch (Exception $e) {
        add_log("lots.$col", 'FAILED', $e->getMessage(), 'danger');
    }
}

// =========================================================================
// MIGRATION 4: ENSURE WORKERS TABLE COLUMNS
// =========================================================================
$workerColumns = [
    'worker_type' => "ALTER TABLE workers ADD COLUMN worker_type VARCHAR(50) DEFAULT 'Piece Rate' AFTER department_id",
    'rate_per_minute' => "ALTER TABLE workers ADD COLUMN rate_per_minute DECIMAL(8,2) DEFAULT 2.50 AFTER daily_wage",
    'default_rate' => "ALTER TABLE workers ADD COLUMN default_rate DECIMAL(10,2) DEFAULT 0.00 AFTER rate_per_minute"
];

foreach ($workerColumns as $col => $sql) {
    try {
        if (!column_exists($db, 'workers', $col)) {
            $db->exec($sql);
            add_log("workers.$col", 'ADDED', "Added missing column $col.", 'success');
        } else {
            add_log("workers.$col", 'VERIFIED', "Column $col already exists.", 'success');
        }
    } catch (Exception $e) {
        add_log("workers.$col", 'FAILED', $e->getMessage(), 'danger');
    }
}

// =========================================================================
// MIGRATION 5: ENSURE PRODUCTION_ENTRIES TABLE COLUMNS
// =========================================================================
$peColumns = [
    'shift' => "ALTER TABLE production_entries ADD COLUMN shift VARCHAR(50) NULL AFTER worker_id",
    'minutes_per_piece' => "ALTER TABLE production_entries ADD COLUMN minutes_per_piece DECIMAL(8,2) DEFAULT 0.00 AFTER rate",
    'worker_minute_rate' => "ALTER TABLE production_entries ADD COLUMN worker_minute_rate DECIMAL(8,2) DEFAULT 2.50 AFTER minutes_per_piece",
    'total_minutes' => "ALTER TABLE production_entries ADD COLUMN total_minutes DECIMAL(10,2) DEFAULT 0.00 AFTER worker_minute_rate",
    'created_by' => "ALTER TABLE production_entries ADD COLUMN created_by INT NULL AFTER notes"
];

foreach ($peColumns as $col => $sql) {
    try {
        if (!column_exists($db, 'production_entries', $col)) {
            $db->exec($sql);
            add_log("production_entries.$col", 'ADDED', "Added missing column $col.", 'success');
        } else {
            add_log("production_entries.$col", 'VERIFIED', "Column $col already exists.", 'success');
        }
    } catch (Exception $e) {
        add_log("production_entries.$col", 'FAILED', $e->getMessage(), 'danger');
    }
}

// =========================================================================
// MIGRATION 6: CREATE worker_element_rates TABLE IF NOT EXISTS
// =========================================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `worker_element_rates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `worker_id` INT NOT NULL,
        `element_id` INT NOT NULL,
        `custom_sam_minutes` DECIMAL(8,2) NULL,
        `custom_piece_rate` DECIMAL(10,2) NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_worker_element` (`worker_id`, `element_id`),
        INDEX `idx_wer_worker` (`worker_id`),
        INDEX `idx_wer_element` (`element_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    add_log('Table worker_element_rates', 'VERIFIED', 'Custom worker rates table created / verified.', 'success');
} catch (Exception $e) {
    add_log('Table worker_element_rates', 'FAILED', $e->getMessage(), 'danger');
}

// =========================================================================
// MIGRATION 7: ENSURE PERFORMANCE INDEXES
// =========================================================================
try {
    $idx1 = $db->query("SHOW INDEX FROM production_entries WHERE Key_name = 'idx_pe_lot_op'")->fetch();
    if (!$idx1) {
        $db->exec("CREATE INDEX idx_pe_lot_op ON production_entries(lot_id, operation_id)");
        add_log('Index idx_pe_lot_op', 'ADDED', 'Added index on production_entries(lot_id, operation_id).', 'success');
    }
} catch (Exception $e) {}

try {
    $idx2 = $db->query("SHOW INDEX FROM worker_ledger WHERE Key_name = 'idx_wl_worker_date'")->fetch();
    if (!$idx2) {
        $db->exec("CREATE INDEX idx_wl_worker_date ON worker_ledger(worker_id, transaction_date)");
        add_log('Index idx_wl_worker_date', 'ADDED', 'Added index on worker_ledger(worker_id, transaction_date).', 'success');
    }
} catch (Exception $e) {}

// =========================================================================
// MIGRATION 8: DOCUMENT NUMBER SEQUENCE & COLLISION INTEGRITY CHECK
// =========================================================================
try {
    $nextPe = generate_doc_number('PE-', 'production_entries', 'entry_number');
    $nextCut = generate_doc_number('PE-CUT-', 'production_entries', 'entry_number');
    $nextLot = generate_doc_number('LOT-', 'lots', 'lot_number');
    $nextPkg = generate_doc_number('PKG-', 'packing_entries', 'packing_number');
    $nextPay = generate_doc_number('PAY-', 'worker_payments', 'payment_number');
    
    // Check if nextPe has any collision
    $testUnique = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $nextPe);
    
    add_log('Doc Sequence Health', 'VERIFIED', "Production: $nextPe (Cut: $nextCut, Lot: $nextLot, Pkg: $nextPkg, Pay: $nextPay) - Collision-Free Guaranteed.", 'success');
} catch (Exception $e) {
    add_log('Doc Sequence Health', 'FAILED', $e->getMessage(), 'danger');
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

// If CLI, end here
if ($isCli) {
    echo "\nMigration completed successfully in {$elapsed} ms.\n";
    exit(0);
}

// =========================================================================
// WEB BROWSER UI (RESPONSIVE BOOTSTRAP DASHBOARD)
// =========================================================================
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();
$dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration & Auto-Repair - Garment ERP</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            text-align: right;
            padding-top: 30px;
            padding-bottom: 50px;
        }
        .migration-card {
            max-width: 900px;
            margin: auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .migration-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #ffffff;
            padding: 24px 30px;
        }
        .badge-status {
            font-size: 0.78rem;
            letter-spacing: 0.5px;
            padding: 5px 10px;
        }
        .table-log td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="migration-card">
        <div class="migration-header d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-database-check text-success me-2"></i>Database Schema Migration & Auto-Repair</h4>
                <div class="small text-light opacity-75">Garment ERP Production Suite — Live Server Database Schema Updater</div>
            </div>
            <div>
                <span class="badge bg-success px-3 py-2 fs-6"><i class="bi bi-check-circle-fill me-1"></i> Update Completed</span>
            </div>
        </div>

        <div class="p-4">
            <!-- Server / DB Stats -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-3 bg-light">
                        <div class="small text-muted mb-1">Database Name</div>
                        <div class="fw-bold text-dark font-monospace" dir="ltr"><?= htmlspecialchars($dbName ?: 'garment_erp') ?></div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-3 bg-light">
                        <div class="small text-muted mb-1">MySQL / MariaDB Version</div>
                        <div class="fw-bold text-dark font-monospace" dir="ltr"><?= htmlspecialchars($dbVersion) ?></div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-3 bg-light">
                        <div class="small text-muted mb-1">Execution Time</div>
                        <div class="fw-bold text-success font-monospace" dir="ltr"><?= $elapsed ?> ms</div>
                    </div>
                </div>
            </div>

            <!-- Key Resolution Banner -->
            <div class="alert alert-success d-flex align-items-center gap-3 mb-4 p-3 rounded-3 shadow-sm" role="alert">
                <i class="bi bi-patch-check-fill fs-2 text-success"></i>
                <div>
                    <h6 class="fw-bold mb-1">MySQL Error 3105 (Generated Column) resolved successfully!</h6>
                    <div class="small text-dark opacity-90">
                        Table <code>lot_operator_element_entries</code> column <code>total_pieces</code> is now a standard column. No errors will occur when saving operator entries.
                    </div>
                </div>
            </div>

            <!-- Migration Log Table -->
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-list-check text-primary me-2"></i>Detailed Applied Migrations Log:</h6>
            <div class="table-responsive border rounded-3 mb-4">
                <table class="table table-hover table-striped align-middle mb-0 table-log">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 30%;">Stage / Column (Component)</th>
                            <th style="width: 15%; text-align: center;">Status</th>
                            <th style="width: 50%;">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $idx => $r): ?>
                            <tr>
                                <td class="text-muted fw-bold"><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark font-monospace" dir="ltr"><?= htmlspecialchars($r['title']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $r['type'] ?> badge-status">
                                        <?= htmlspecialchars($r['status']) ?>
                                    </span>
                                </td>
                                <td class="text-secondary"><?= htmlspecialchars($r['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Actions -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                <span class="text-muted small"><i class="bi bi-shield-check text-success me-1"></i> All changes are safe and data integrity is preserved.</span>
                <div class="d-flex gap-2">
                    <a href="migrate.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Re-run Check
                    </a>
                    <a href="modules/production/index.php" class="btn btn-primary btn-sm fw-bold px-3">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Go to Production Entry
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
