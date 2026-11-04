<?php
/**
 * Garment Manufacturing ERP - Printable Job Card (لاٹ جاب کارڈ پرچہ)
 * Exact digital and physical reproduction of the factory handwritten Excel Job Card:
 * 1. Order & Product Details + Anatomy Color Multiplier Table
 * 2. Operator Element Table (Hashim / Adnan / Molu with dynamic 1 or 2 operator columns & pending highlights)
 * 3. Cutting Master Box (Ali Raza, Date, Size breakdown, Total cut)
 * 4. Worker Operation Grid (Arif, Asad, Bilal, Latif: Gala, Pocket, Safety, Taanke, Button, Kaj, Press, Packing)
 * 5. Pre-printed clean blank ruled rows for manual factory pen entries or live digital values
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$db = get_db();

// If lot ID is invalid or missing, fallback to latest active lot
$activeLots = $db->query("SELECT id, lot_number FROM lots WHERE status NOT IN ('Closed', 'Cancelled') ORDER BY id DESC")->fetchAll();
if ($lotId <= 0 && !empty($activeLots)) {
    $lotId = (int)$activeLots[0]['id'];
}

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             COALESCE(l.design_number, d.design_number) AS design_number,
                             d.name AS design_name, d.machine_type, d.measurements_json,
                             op.name AS operator_name, op.code AS operator_code,
                             nop.name AS night_operator_name,
                             s.name AS single_size_name, s.code AS single_size_code
                      FROM lots l
                      JOIN products p ON l.product_id = p.id
                      LEFT JOIN designs d ON l.design_id = d.id
                      LEFT JOIN workers op ON l.operator_id = op.id
                      LEFT JOIN workers nop ON l.night_operator_id = nop.id
                      LEFT JOIN sizes s ON l.single_size_id = s.id
                      WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    if (!empty($activeLots)) {
        redirect('modules/lots/print_jobcard.php?id=' . (int)$activeLots[0]['id'], 'Lot #' . $lotId . ' not found. Loaded active lot.', 'info');
    } else {
        die("No active production lots found.");
    }
}

// Fetch Variants with Color Codes & Sizes
$vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, COALESCE(lv.color_code, c.color_code) AS color_code, c.hex_code,
                              s.name AS size_name, s.code AS size_code
                       FROM lot_variants lv
                       JOIN colors c ON lv.color_id = c.id
                       JOIN sizes s ON lv.size_id = s.id
                       WHERE lv.lot_id = ?
                       ORDER BY lv.color_code ASC, s.sort_order ASC");
$vStmt->execute([$lotId]);
$variants = $vStmt->fetchAll();

// Group variants by color code
$colorSummary = [];
$sizesUsed = [];
foreach ($variants as $v) {
    $code = $v['color_code'] ?: $v['color_name'];
    if (!isset($colorSummary[$code])) {
        $colorSummary[$code] = [
            'color_id' => $v['color_id'],
            'color_code' => $code,
            'color_name' => $v['color_name'],
            'hex_code' => $v['hex_code'],
            'total_qty' => 0
        ];
    }
    $colorSummary[$code]['total_qty'] += (int)$v['quantity'];
    $sizesUsed[$v['size_id']] = $v['size_name'] . ' (' . $v['size_code'] . ')';
}

$sizeDisplay = !empty($lot['single_size_name']) ? ($lot['single_size_name'] . ' (' . $lot['single_size_code'] . ')') : implode(', ', $sizesUsed);
if (empty($sizeDisplay)) $sizeDisplay = 'Small / M / L / XL';

$totalColorsCount = count($colorSummary);
$pcsPerColor = $totalColorsCount > 0 ? round($lot['total_pieces'] / $totalColorsCount) : $lot['total_pieces'];

// Product anatomy elements (Front, Back, Sleeves, Pocket, Gala)
$peStmt = $db->prepare("SELECT * FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
$peStmt->execute([$lot['product_id']]);
$productElements = $peStmt->fetchAll();
if (empty($productElements)) {
    $productElements = [
        ['id' => 1, 'element_name' => 'Front (فرنٹ)', 'quantity' => 1, 'rate_per_piece' => 28.50],
        ['id' => 2, 'element_name' => 'Back (بیک)', 'quantity' => 1, 'rate_per_piece' => 20.00],
        ['id' => 3, 'element_name' => 'Bazo (بازو)', 'quantity' => 2, 'rate_per_piece' => 12.00],
        ['id' => 4, 'element_name' => 'Pocket (پاکٹ)', 'quantity' => 2, 'rate_per_piece' => 6.00],
        ['id' => 5, 'element_name' => 'Gol Gala (گول گلا)', 'quantity' => 1, 'rate_per_piece' => 3.00],
    ];
}

// Fetch Stage 1 Operator Element Entries
$oeStmt = $db->prepare("SELECT oe.*, pe.element_name, pe.quantity AS multiplier, pe.default_sam_minutes,
                               w1.name AS op1_name, w2.name AS op2_name
                        FROM lot_operator_element_entries oe
                        JOIN product_elements pe ON oe.element_id = pe.id
                        LEFT JOIN workers w1 ON oe.operator1_id = w1.id
                        LEFT JOIN workers w2 ON oe.operator2_id = w2.id
                        WHERE oe.lot_id = ?
                        ORDER BY pe.sort_order ASC, pe.id ASC");
$oeStmt->execute([$lotId]);
$operatorEntries = $oeStmt->fetchAll();

// If no entries saved yet, build standard template for preview
if (empty($operatorEntries)) {
    foreach ($productElements as $se) {
        $req = (int)$lot['total_pieces'] * (int)$se['quantity'];
        $operatorEntries[] = [
            'element_id' => $se['id'],
            'element_name' => $se['element_name'],
            'required_pieces' => $req,
            'operator1_pieces' => 0,
            'operator2_pieces' => 0,
            'total_pieces' => 0,
            'minutes_spent' => $se['estimated_minutes'] ?? 7,
            'rate_per_piece' => $se['rate_per_piece'] ?? 0,
            'op1_name' => $lot['operator_name'] ?: 'Hashim',
            'op2_name' => $lot['night_operator_name'] ?: 'Adnan / Ali'
        ];
    }
}

$isDoubleShift = ($lot['shift_type'] === 'Double Shift' || !empty($lot['night_operator_name']));
$op1DisplayName = $lot['operator_name'] ?: 'ہاشم (Op 1)';
$op2DisplayName = $lot['night_operator_name'] ?: 'عدنان / علی (Op 2)';

// Cutting details
$cutStmt = $db->prepare("SELECT pe.*, w.name AS cutter_name 
                         FROM production_entries pe 
                         JOIN operations o ON pe.operation_id = o.id 
                         JOIN workers w ON pe.worker_id = w.id
                         WHERE pe.lot_id = ? AND (o.code = 'OP-CUT' OR o.name LIKE '%Cut%')
                         ORDER BY pe.entry_date DESC, pe.id DESC LIMIT 1");
$cutStmt->execute([$lotId]);
$cuttingEntry = $cutStmt->fetch();

$cuttingMasterName = $cuttingEntry['cutter_name'] ?? 'علی رضا (کٹنگ ماسٹر)';
$cuttingDate = !empty($cuttingEntry['entry_date']) ? format_date($cuttingEntry['entry_date']) : date('d/m/Y');
$cutDoneTotal = (int)$db->query("SELECT COALESCE(SUM(pe.good_qty), 0) FROM production_entries pe JOIN operations o ON pe.operation_id = o.id WHERE pe.lot_id = $lotId AND (o.code = 'OP-CUT' OR o.name LIKE '%Cut%')")->fetchColumn();

// Fetch Worker Matrix Submissions
$wMatrixStmt = $db->prepare("SELECT pe.worker_id, w.name AS worker_name, w.code AS worker_code,
                                    pe.operation_id, o.code AS op_code, o.name AS op_name,
                                    SUM(pe.good_qty) AS pieces,
                                    MAX(pe.entry_date) AS last_date
                             FROM production_entries pe
                             JOIN workers w ON pe.worker_id = w.id
                             JOIN operations o ON pe.operation_id = o.id
                             WHERE pe.lot_id = ?
                             GROUP BY pe.worker_id, pe.operation_id
                             ORDER BY w.name ASC, o.sequence ASC");
$wMatrixStmt->execute([$lotId]);
$wRows = $wMatrixStmt->fetchAll();

// Group into worker rows
$workerGrid = [];
foreach ($wRows as $wr) {
    $wId = $wr['worker_id'];
    if (!isset($workerGrid[$wId])) {
        $workerGrid[$wId] = [
            'name' => $wr['worker_name'],
            'code' => $wr['worker_code'],
            'date' => $wr['last_date'],
            'total' => 0,
            'gala' => 0,
            'pocket' => 0,
            'safety' => 0,
            'taanke' => 0,
            'button' => 0,
            'kaj' => 0,
            'press' => 0,
            'packing' => 0
        ];
    }
    $pcs = (int)$wr['pieces'];
    $opLower = strtolower($wr['op_name'] . ' ' . $wr['op_code']);
    
    if (strpos($opLower, 'gala') !== false || strpos($opLower, 'neck') !== false) {
        $workerGrid[$wId]['gala'] += $pcs;
    } elseif (strpos($opLower, 'pocket') !== false) {
        $workerGrid[$wId]['pocket'] += $pcs;
    } elseif (strpos($opLower, 'safe') !== false || strpos($opLower, 'overlock') !== false) {
        $workerGrid[$wId]['safety'] += $pcs;
    } elseif (strpos($opLower, 'stitch') !== false || strpos($opLower, 'taank') !== false) {
        $workerGrid[$wId]['taanke'] += $pcs;
    } elseif (strpos($opLower, 'btn') !== false || strpos($opLower, 'button') !== false) {
        $workerGrid[$wId]['button'] += $pcs;
    } elseif (strpos($opLower, 'kaj') !== false) {
        $workerGrid[$wId]['kaj'] += $pcs;
    } elseif (strpos($opLower, 'press') !== false || strpos($opLower, 'iron') !== false) {
        $workerGrid[$wId]['press'] += $pcs;
    } elseif (strpos($opLower, 'pack') !== false) {
        $workerGrid[$wId]['packing'] += $pcs;
    }
    $workerGrid[$wId]['total'] += $pcs;
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>جاب کارڈ پرچہ (Job Card Sheet) - Lot #<?= clean($lot['lot_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: local('Jameel Noori Nastaleeq'),
                 local('JameelNooriNastaleeq'),
                 url('<?= BASE_URL ?>assets/fonts/jameel-noori-nastaleeq.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        @page {
            size: A4 portrait;
            margin: 4mm 5mm 4mm 5mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            background: #f8fafc;
            color: #000;
            font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', 'Urdu Typesetting', Tahoma, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            direction: rtl;
            text-align: right;
            padding: 0;
            margin: 0;
        }
        .font-latin,
        .badge-boxed,
        .badge-lot-dark,
        td[dir="ltr"],
        th[dir="ltr"],
        span[dir="ltr"],
        div[dir="ltr"] {
            font-family: Consolas, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            line-height: 1.25;
        }

        .sheet-container {
            max-width: 860px;
            margin: 10px auto;
            background: #fff;
            padding: 6px 8px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            border-radius: 4px;
        }

        .section-box {
            border: 1.5px solid #000;
            margin-bottom: 5px;
            background: #fff;
            page-break-inside: avoid;
        }

        .tbl-jc {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            text-align: center;
            margin-bottom: 0;
        }
        .tbl-jc th, .tbl-jc td {
            border: 1px solid #000;
            padding: 2.5px 3px;
            vertical-align: middle;
            line-height: 1.35;
        }
        .tbl-jc th {
            background-color: #f1f3f5 !important;
            font-weight: 700;
            font-size: 11.5px;
            color: #000;
        }

        .cell-pending {
            background-color: #fef08a !important;
            color: #854d0e !important;
            font-weight: 800;
        }
        .cell-done {
            background-color: #dcfce7 !important;
            color: #166534 !important;
            font-weight: 700;
        }
        .cell-shaded {
            background: repeating-linear-gradient(
                -45deg,
                #f1f5f9,
                #f1f5f9 4px,
                #e2e8f0 4px,
                #e2e8f0 8px
            ) !important;
        }

        .badge-boxed {
            border: 1px solid #000;
            padding: 1px 5px;
            background: #f8fafc;
            border-radius: 2px;
            font-weight: 700;
            font-size: 11px;
            display: inline-block;
        }
        .badge-lot-dark {
            background: #000;
            color: #fff;
            padding: 2px 7px;
            border-radius: 2px;
            font-weight: 800;
            font-size: 11.5px;
            display: inline-block;
            letter-spacing: 0.5px;
        }

        .no-print-bar {
            background: #0f172a;
            color: #fff;
            padding: 8px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 4mm 5mm 4mm 5mm;
            }
            html, body {
                background: #fff !important;
                font-size: 11px !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                height: 100% !important;
                overflow: hidden !important;
            }
            .no-print-bar { display: none !important; }
            .sheet-container {
                width: 100% !important;
                max-width: 100% !important;
                height: 288mm !important;
                max-height: 288mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
            }
            .section-box {
                margin-bottom: 3.5mm !important;
                page-break-inside: avoid !important;
            }
            .tbl-jc th, .tbl-jc td {
                padding: 2px 2px !important;
                font-size: 10px !important;
                line-height: 1.25 !important;
            }
            .tbl-jc th {
                font-size: 10.5px !important;
            }
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar (Hidden on Print) -->
<div class="no-print-bar">
    <div class="d-flex align-items-center gap-3">
        <h5 class="mb-0 fw-bold"><i class="bi bi-card-checklist me-2"></i>جاب کارڈ پرچہ (Factory Job Card Sheet)</h5>
        <span class="badge bg-primary fs-6 font-monospace" dir="ltr">#<?= clean($lot['lot_number']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button onclick="window.print()" class="btn btn-success btn-sm fw-bold px-3">
            <i class="bi bi-printer-fill me-1"></i> پرنٹ جاب کارڈ (Print Job Card)
        </button>
        <a href="<?= BASE_URL ?>modules/lots/jobcard.php?id=<?= $lotId ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-laptop me-1"></i> Digital Job Card View
        </a>
        <a href="<?= BASE_URL ?>modules/lots/print_design_sheet.php?id=<?= $lotId ?>" class="btn btn-outline-info btn-sm">
            <i class="bi bi-file-earmark-text me-1"></i> 4-Copy Design Sheet
        </a>
        <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lotId ?>" class="btn btn-outline-secondary btn-sm text-white">
            <i class="bi bi-arrow-right me-1"></i> Back to Lot
        </a>
    </div>
</div>

<div class="sheet-container">

    <!-- ======================================================================= -->
    <!-- 1. UPPER SECTION: OPERATOR TABLE (LEFT) + ORDER/COLOR BREAKDOWN (RIGHT)  -->
    <!-- ======================================================================= -->
    <div class="section-box p-1">
        <div class="row g-2 align-items-stretch">
            
            <!-- Left 60%: Operator Table with Stage 1 Piece Breakdown -->
            <div class="col-7">
                <div class="d-flex justify-content-between align-items-center px-1 mb-1">
                    <span class="fw-bold" style="font-size: 12px;">
                        <i class="bi bi-cpu me-1"></i> آپریٹر سیکشن (Operator Production)
                    </span>
                    <span class="text-muted small">
                        آپریٹر: <strong class="text-dark"><?= clean($op1DisplayName) ?></strong>
                        <?php if ($isDoubleShift): ?> / <strong class="text-dark"><?= clean($op2DisplayName) ?></strong><?php endif; ?>
                    </span>
                </div>

                <table class="tbl-jc">
                    <thead>
                        <tr>
                            <th style="width: 14%;">لاٹ #</th>
                            <th style="width: 26%;">آئٹم (Item)</th>
                            <th style="width: 15%;">آپریٹر 1</th>
                            <?php if ($isDoubleShift): ?>
                                <th style="width: 15%;">آپریٹر 2</th>
                            <?php endif; ?>
                            <th style="width: 15%;">ٹوٹل</th>
                            <th style="width: 15%;">ریٹ / منٹ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $hasPending = false;
                        foreach ($operatorEntries as $oe): 
                            $req = (int)$oe['required_pieces'];
                            $p1 = (int)$oe['operator1_pieces'];
                            $p2 = (int)$oe['operator2_pieces'];
                            $tot = $p1 + $p2;
                            $bal = max(0, $req - $tot);
                            if ($bal > 0) $hasPending = true;
                        ?>
                            <tr>
                                <td class="font-monospace fw-bold" dir="ltr"><?= clean($lot['lot_number']) ?></td>
                                <td class="fw-bold text-start ps-2"><?= clean($oe['element_name']) ?></td>
                                <td class="font-monospace fw-bold" dir="ltr"><?= $p1 > 0 ? $p1 : '—' ?></td>
                                <?php if ($isDoubleShift): ?>
                                    <td class="font-monospace fw-bold" dir="ltr"><?= $p2 > 0 ? $p2 : '—' ?></td>
                                <?php endif; ?>
                                <td class="font-monospace fw-bold <?= $bal > 0 ? 'cell-pending' : 'cell-done' ?>" dir="ltr">
                                    <?= $tot > 0 ? $tot : '0' ?>
                                    <?php if ($bal > 0): ?><span style="font-size: 9px; display: block;">(<?= $bal ?> باقی)</span><?php endif; ?>
                                </td>
                                <td class="font-monospace" dir="ltr">
                                    <?= !empty($oe['rate_per_piece']) ? ('Rs.' . number_format($oe['rate_per_piece'], 2)) : clean($oe['minutes_spent']) . 'm' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="px-1 mt-1 d-flex justify-content-between align-items-center" style="font-size: 9px; color: #555;">
                    <span>⚠️ <strong>if pending highlight:</strong> this work is pending —</span>
                    <span>12 کوٹی بازو والی | 12 جیکٹ</span>
                </div>
            </div>

            <!-- Right 40%: Order Details, Header Info & Color Breakdown -->
            <div class="col-5">
                <table class="tbl-jc mb-1">
                    <tr>
                        <td style="width: 35%; background: #f8fafc;" class="fw-bold">لاٹ نمبر:</td>
                        <td style="width: 65%; text-align: right;" class="pe-2">
                            <span class="badge-lot-dark font-monospace" dir="ltr"><?= clean($lot['lot_number']) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold">ڈیزائن نمبر:</td>
                        <td class="pe-2 font-monospace fw-bold" dir="ltr"><?= clean($lot['design_number'] ?: '901') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">تفصیل (Detail):</td>
                        <td class="pe-2"><?= clean($lot['product_name']) ?> (<?= clean($lot['yarn_type'] ?? 'Wool') ?>)</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">سائز (Size):</td>
                        <td class="pe-2 font-monospace fw-bold" dir="ltr"><?= clean($sizeDisplay) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">کل تعداد:</td>
                        <td class="pe-2">
                            <strong class="badge-boxed font-monospace" dir="ltr"><?= (int)$lot['total_pieces'] ?> Pcs</strong>
                            <span class="badge bg-light text-dark border ms-1 font-monospace"><?= $totalColorsCount ?> رنگ</span>
                        </td>
                    </tr>
                </table>

                <!-- Anatomy Multiplier Color Breakdown -->
                <table class="tbl-jc" style="font-size: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 28%;">عضو</th>
                            <th style="width: 52%;">حساب ضرب (Calculation)</th>
                            <th style="width: 20%;">چیک</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold">فرنٹ</td>
                            <td dir="ltr" class="font-monospace fw-semibold"><?= $pcsPerColor ?> &times; <?= $totalColorsCount ?> رنگ = <?= (int)$lot['total_pieces'] ?></td>
                            <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">بیک</td>
                            <td dir="ltr" class="font-monospace fw-semibold"><?= $pcsPerColor ?> &times; <?= $totalColorsCount ?> رنگ = <?= (int)$lot['total_pieces'] ?></td>
                            <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">بازو</td>
                            <td dir="ltr" class="font-monospace fw-semibold"><?= $pcsPerColor * 2 ?> &times; <?= $totalColorsCount ?> رنگ = <?= (int)$lot['total_pieces'] * 2 ?></td>
                            <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">پاکٹ</td>
                            <td dir="ltr" class="font-monospace fw-semibold"><?= $pcsPerColor * 2 ?> &times; <?= $totalColorsCount ?> رنگ = <?= (int)$lot['total_pieces'] * 2 ?></td>
                            <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">گول گلا</td>
                            <td dir="ltr" class="font-monospace fw-semibold"><?= $pcsPerColor ?> &times; <?= $totalColorsCount ?> رنگ = <?= (int)$lot['total_pieces'] ?></td>
                            <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- 2. MIDDLE SECTION: CUTTING MASTER BOX (علی رضا - کٹائی ماسٹر)          -->
    <!-- ======================================================================= -->
    <div class="section-box p-1">
        <table class="tbl-jc">
            <thead>
                <tr>
                    <th style="width: 22%;">کٹائی ماسٹر (Cutting)</th>
                    <th style="width: 14%;">تاریخ</th>
                    <th style="width: 14%;">ٹوٹل کٹنگ</th>
                    <th style="width: 16%;">ٹائم (Time per mint)</th>
                    <th style="width: 34%;">سائز بریک ڈاؤن (Cutting Size Breakdown)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="fw-bold fs-6"><?= clean($cuttingMasterName) ?></td>
                    <td class="font-monospace" dir="ltr"><?= clean($cuttingDate) ?></td>
                    <td class="font-monospace fw-bold fs-6 text-primary" dir="ltr">
                        <?= $cutDoneTotal > 0 ? $cutDoneTotal : (int)$lot['total_pieces'] ?> Pcs
                    </td>
                    <td class="font-monospace" dir="ltr">1 = 50 min</td>
                    <td>
                        <div class="d-flex justify-content-around font-monospace fw-bold small" dir="ltr">
                            <span>34/36: <?= round($lot['total_pieces'] * 0.7) ?></span>
                            <span>|</span>
                            <span>24/26/28: <?= round($lot['total_pieces'] * 0.3) ?></span>
                            <span>|</span>
                            <span class="badge bg-light text-dark border">Short / Long</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ======================================================================= -->
    <!-- 3. LOWER SECTION: WORKER OPERATION MATRIX (عارف، اسد، بلال، لطیف لاگ)  -->
    <!-- ======================================================================= -->
    <div class="section-box p-1" style="flex: 1 1 auto; display: flex; flex-direction: column;">
        <div class="d-flex justify-content-between align-items-center px-1 mb-1">
            <span class="fw-bold" style="font-size: 12.5px;">
                <i class="bi bi-people-fill me-1"></i> کاریگروں کا کام اور کھاتہ لاگ (Worker Operation Matrix)
            </span>
            <span class="text-muted small">
                تمام ٹیلرنگ، سیفٹی، بٹن و پیکنگ انٹریاں اس جدول میں درج ہوں گی۔
            </span>
        </div>

        <table class="tbl-jc" style="height: 100%; flex: 1 1 auto;">
            <thead>
                <tr>
                    <th style="width: 4%;">✓</th>
                    <th style="width: 10%;">تاریخ</th>
                    <th style="width: 16%; text-align: start; padding-right: 6px;">نام (Worker)</th>
                    <th style="width: 8%;">گلا</th>
                    <th style="width: 8%;">پاکٹ</th>
                    <th style="width: 8%;">سیفٹی</th>
                    <th style="width: 8%;">ٹانکے</th>
                    <th style="width: 8%;">بٹن</th>
                    <th style="width: 8%;">کاج</th>
                    <th style="width: 9%;">مال پریس</th>
                    <th style="width: 9%;">پیکنگ</th>
                    <th style="width: 10%;">ٹوٹل</th>
                </tr>
            </thead>
            <tbody>
                <!-- 1. Real recorded worker entries from database (if any) -->
                <?php 
                $rowCount = 0;
                $sumGala = 0; $sumPocket = 0; $sumSafety = 0; $sumTaanke = 0;
                $sumButton = 0; $sumKaj = 0; $sumPress = 0; $sumPacking = 0; $sumGrandTotal = 0;

                foreach ($workerGrid as $wg): 
                    $rowCount++;
                    $sumGala += $wg['gala'];
                    $sumPocket += $wg['pocket'];
                    $sumSafety += $wg['safety'];
                    $sumTaanke += $wg['taanke'];
                    $sumButton += $wg['button'];
                    $sumKaj += $wg['kaj'];
                    $sumPress += $wg['press'];
                    $sumPacking += $wg['packing'];
                    $sumGrandTotal += $wg['total'];
                ?>
                    <tr>
                        <td><input type="checkbox" checked style="transform: scale(0.85);"></td>
                        <td class="font-monospace small" dir="ltr"><?= !empty($wg['date']) ? date('d/m', strtotime($wg['date'])) : '—' ?></td>
                        <td class="fw-bold text-start ps-2">
                            <?= clean($wg['name']) ?>
                            <span class="text-muted font-monospace" style="font-size: 9px;">(<?= clean($wg['code']) ?>)</span>
                        </td>
                        <td class="font-monospace <?= $wg['gala'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['gala'] > 0 ? $wg['gala'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['pocket'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['pocket'] > 0 ? $wg['pocket'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['safety'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['safety'] > 0 ? $wg['safety'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['taanke'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['taanke'] > 0 ? $wg['taanke'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['button'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['button'] > 0 ? $wg['button'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['kaj'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['kaj'] > 0 ? $wg['kaj'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['press'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['press'] > 0 ? $wg['press'] : '' ?>
                        </td>
                        <td class="font-monospace <?= $wg['packing'] > 0 ? 'fw-bold bg-success-subtle' : 'cell-shaded' ?>" dir="ltr">
                            <?= $wg['packing'] > 0 ? $wg['packing'] : '' ?>
                        </td>
                        <td class="font-monospace fw-bold fs-6 text-primary" dir="ltr"><?= $wg['total'] ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- 2. Standard Ruled Blank Rows for Factory Pen Writing (matching physical Excel card) -->
                <?php 
                $standardWorkerSlots = [
                    ['name' => 'عارف (Arif)', 'shade_gala' => false, 'shade_pocket' => false],
                    ['name' => 'اسد (Asad)', 'shade_gala' => false, 'shade_pocket' => false],
                    ['name' => 'بلال (Bilal - Safety)', 'shade_safety' => false],
                    ['name' => 'لطیف (Latif - Taanke & Button)', 'shade_taanke' => false, 'shade_button' => false],
                    ['name' => 'پریس مین (Pressing)', 'shade_press' => false],
                    ['name' => 'پیکنگ ورکر (Packing)', 'shade_packing' => false],
                ];

                $blankRowsNeeded = max(4, 9 - $rowCount);
                for ($i = 0; $i < $blankRowsNeeded; $i++): 
                    $sample = $standardWorkerSlots[$i % count($standardWorkerSlots)];
                ?>
                    <tr>
                        <td><input type="checkbox" style="transform: scale(0.85);"></td>
                        <td></td>
                        <td class="text-start ps-2 text-muted fw-semibold" style="color: #64748b !important;">
                            <?= ($rowCount == 0) ? $sample['name'] : '' ?>
                        </td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_gala'])) ? '' : 'cell-shaded' ?>"></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_pocket'])) ? '' : 'cell-shaded' ?>"></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_safety'])) ? '' : 'cell-shaded' ?>"></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_taanke'])) ? '' : 'cell-shaded' ?>"></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_button'])) ? '' : 'cell-shaded' ?>"></td>
                        <td></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_press'])) ? '' : 'cell-shaded' ?>"></td>
                        <td class="<?= ($rowCount == 0 && isset($sample['shade_packing'])) ? '' : 'cell-shaded' ?>"></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold" style="background: #f1f3f5 !important;">
                    <td colspan="3" class="text-start pe-3">کل مجموعہ (Total Progress):</td>
                    <td class="font-monospace" dir="ltr"><?= $sumGala > 0 ? $sumGala : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumPocket > 0 ? $sumPocket : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumSafety > 0 ? $sumSafety : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumTaanke > 0 ? $sumTaanke : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumButton > 0 ? $sumButton : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumKaj > 0 ? $sumKaj : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumPress > 0 ? $sumPress : '' ?></td>
                    <td class="font-monospace" dir="ltr"><?= $sumPacking > 0 ? $sumPacking : '' ?></td>
                    <td class="font-monospace fs-6 text-primary" dir="ltr"><?= $sumGrandTotal > 0 ? $sumGrandTotal : (int)$lot['total_pieces'] ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>

</body>
</html>
