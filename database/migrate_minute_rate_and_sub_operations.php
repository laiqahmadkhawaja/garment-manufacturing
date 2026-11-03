<?php
/**
 * Migration: Standard Allowed Minutes (SAM), Worker Minute Rate (Rs/min), and Sub-Operations Hierarchy
 */
require_once __DIR__ . '/../config/config.php';

$db = get_db();

echo "Running Migration: SAM, Worker Minute Rate & Sub-Operations...\n";

// 1. Alter operations table
try {
    $cols = $db->query("DESCRIBE operations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('parent_id', $cols)) {
        $db->exec("ALTER TABLE operations ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER id");
        $db->exec("ALTER TABLE operations ADD CONSTRAINT fk_operations_parent FOREIGN KEY (parent_id) REFERENCES operations(id) ON DELETE SET NULL");
        echo "Added operations.parent_id column.\n";
    }
    if (!in_array('estimated_minutes', $cols)) {
        $db->exec("ALTER TABLE operations ADD COLUMN estimated_minutes DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER sequence");
        echo "Added operations.estimated_minutes column.\n";
    }
} catch (Exception $e) {
    echo "Notice on operations alter: " . $e->getMessage() . "\n";
}

// 2. Alter workers table
try {
    $wCols = $db->query("DESCRIBE workers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('rate_per_minute', $wCols)) {
        $db->exec("ALTER TABLE workers ADD COLUMN rate_per_minute DECIMAL(8,2) NOT NULL DEFAULT 2.50 AFTER default_rate");
        echo "Added workers.rate_per_minute column.\n";
    }
    // Update existing workers to default 2.50
    $db->exec("UPDATE workers SET rate_per_minute = 2.50 WHERE rate_per_minute IS NULL OR rate_per_minute = 0.00");
} catch (Exception $e) {
    echo "Notice on workers alter: " . $e->getMessage() . "\n";
}

// 3. Alter production_entries table
try {
    $peCols = $db->query("DESCRIBE production_entries")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('minutes_per_piece', $peCols)) {
        $db->exec("ALTER TABLE production_entries ADD COLUMN minutes_per_piece DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER rate");
        echo "Added production_entries.minutes_per_piece column.\n";
    }
    if (!in_array('worker_minute_rate', $peCols)) {
        $db->exec("ALTER TABLE production_entries ADD COLUMN worker_minute_rate DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER minutes_per_piece");
        echo "Added production_entries.worker_minute_rate column.\n";
    }
    if (!in_array('total_minutes', $peCols)) {
        $db->exec("ALTER TABLE production_entries ADD COLUMN total_minutes DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER worker_minute_rate");
        echo "Added production_entries.total_minutes column.\n";
    }
} catch (Exception $e) {
    echo "Notice on production_entries alter: " . $e->getMessage() . "\n";
}

// 4. Update standard estimated minutes for base operations
$baseSam = [
    'OP-CUT'    => 2.00,
    'OP-TAIL'   => 2.00,
    'OP-SAFE'   => 1.20,
    'OP-STITCH' => 4.80,
    'OP-NECK'   => 1.60,
    'OP-BTN'    => 2.40,
    'OP-KAJ'    => 1.60,
    'OP-PRESS'  => 1.20,
    'OP-PACK'   => 0.80
];

foreach ($baseSam as $code => $mins) {
    $stmt = $db->prepare("UPDATE operations SET estimated_minutes = ? WHERE code = ?");
    $stmt->execute([$mins, $code]);
}
echo "Populated estimated minutes for base operations.\n";

// 5. Seed Sub-Operations under Tailoring (id = 2)
$tailoring = $db->query("SELECT id, department_id FROM operations WHERE code = 'OP-TAIL' OR name LIKE '%Tailoring%' LIMIT 1")->fetch();
if ($tailoring) {
    $tailId = (int)$tailoring['id'];
    $tailDept = (int)$tailoring['department_id'];

    $subOps = [
        [
            'code' => 'OP-TAIL-SLV',
            'name' => 'Attach Sleeves (بازو لگانا)',
            'sequence' => 21,
            'estimated_minutes' => 2.00,
            'default_rate' => 5.00 // 2.00 mins * 2.50 Rs/min = 5.00 Rs
        ],
        [
            'code' => 'OP-TAIL-PCK',
            'name' => 'Attach Pockets (جیبیں لگانا)',
            'sequence' => 22,
            'estimated_minutes' => 1.60,
            'default_rate' => 4.00 // 1.60 mins * 2.50 Rs/min = 4.00 Rs
        ],
        [
            'code' => 'OP-TAIL-CLR',
            'name' => 'Attach Collar / Neck (گلا یا کالر جوڑنا)',
            'sequence' => 23,
            'estimated_minutes' => 1.20,
            'default_rate' => 3.00 // 1.20 mins * 2.50 Rs/min = 3.00 Rs
        ],
        [
            'code' => 'OP-TAIL-PLK',
            'name' => 'Front Placket / Patti (سامنے کی پٹی)',
            'sequence' => 24,
            'estimated_minutes' => 1.40,
            'default_rate' => 3.50 // 1.40 mins * 2.50 Rs/min = 3.50 Rs
        ]
    ];

    $insSub = $db->prepare("INSERT INTO operations (parent_id, code, name, department_id, sequence, estimated_minutes, default_rate, rate_unit, is_mandatory, is_active)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'Per piece', 1, 1)
                           ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), estimated_minutes = VALUES(estimated_minutes), default_rate = VALUES(default_rate)");

    foreach ($subOps as $so) {
        $insSub->execute([
            $tailId,
            $so['code'],
            $so['name'],
            $tailDept,
            $so['sequence'],
            $so['estimated_minutes'],
            $so['default_rate']
        ]);
    }
    echo "Seeded sub-operations under Tailoring (Attach Sleeves, Attach Pockets, Attach Collar, Front Placket).\n";
}

echo "Migration Completed Successfully!\n";
