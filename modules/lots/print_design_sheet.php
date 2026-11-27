<?php
/**
 * Garment Manufacturing ERP - Printable 4-Copy Lot Design Sheet
 * Exact digital reproduction of the physical factory traveller with authentic Jameel Noori Nastaleeq font:
 * 1. Operator Copy
 * 2. Factory Copy
 * 3. Cutting Master Copy
 * 4. Band Plate Expense Copy
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$copyFilter = trim($_GET['copy'] ?? 'all'); // 'all', 'operator', 'factory', 'cutting', 'bandplate'
$db = get_db();

// If lot ID is invalid or missing, fallback to latest active lot
$activeLots = $db->query("SELECT id, lot_number FROM lots WHERE status NOT IN ('Closed', 'Cancelled') ORDER BY id DESC")->fetchAll();
if ($lotId <= 0 && !empty($activeLots)) {
    $lotId = (int)$activeLots[0]['id'];
}

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             COALESCE(l.design_number, d.design_number) AS design_number,
                             d.name AS design_name, 
                             COALESCE(NULLIF(l.machine_name, ''), d.machine_type, 'CMS 430') AS lot_machine_name,
                             d.machine_type, 
                             COALESCE(l.measurements_json, d.measurements_json) AS measurements_json, 
                             COALESCE(NULLIF(l.sketch_image, ''), d.sketch_image) AS sketch_image_file,
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
        redirect('modules/lots/print_design_sheet.php?id=' . (int)$activeLots[0]['id'], 'Lot #' . $lotId . ' not found. Loaded active lot.', 'info');
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

// Group quantities by color code
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
            'total_qty' => 0,
            'sizes' => []
        ];
    }
    $colorSummary[$code]['total_qty'] += (int)$v['quantity'];
    $colorSummary[$code]['sizes'][$v['size_code']] = (int)$v['quantity'];
    $sizesUsed[$v['size_id']] = $v['size_name'] . ' (' . $v['size_code'] . ')';
}

// Product pattern elements (Front, Back, Sleeve, Pocket, Gola)
$peStmt = $db->prepare("SELECT * FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
$peStmt->execute([$lot['product_id']]);
$productElements = $peStmt->fetchAll();

// Anatomy breakdown calculation
$anatomyData = !empty($lot['anatomy_breakdown']) ? json_decode($lot['anatomy_breakdown'], true) : [];
$sleevedQty = (int)($anatomyData['with_sleeves'] ?? 0);
$sleevelessQty = (int)($anatomyData['sleeveless'] ?? 0);
$hasAnatomy = !empty($lot['has_anatomy_variants']) && ($sleevedQty > 0 || $sleevelessQty > 0);
$totalLotPieces = (int)$lot['total_pieces'];

// Dynamic Garment Types / Product Elements for Copy #4 (Excludes plain back & sleeves per factory standard)
$bandPlateElements = [];
if (!empty($productElements)) {
    foreach ($productElements as $pe) {
        $name = trim($pe['element_name']);
        // Exclude knitted back panel and sleeves from printing / band plate traveller
        $isBack = (stripos($name, 'back') !== false || stripos($name, 'پچھلا') !== false || stripos($name, 'بیک') !== false);
        $isSleeves = (stripos($name, 'sleeve') !== false || stripos($name, 'بازو') !== false);
        if ($isBack || $isSleeves) {
            continue;
        }
        $bandPlateElements[] = [
            'name' => $name,
            'qty' => (int)$pe['quantity'] ?: 1,
            'is_sleeves' => false
        ];
    }
}

if (empty($bandPlateElements)) {
    $bandPlateElements = [
        ['name' => 'Front Panel', 'qty' => 1, 'is_sleeves' => false],
        ['name' => 'V-Tape / Gala (VTape / Gala)', 'qty' => 1, 'is_sleeves' => false],
        ['name' => 'Armhole Tape (Moday Tape)', 'qty' => 1, 'is_sleeves' => false],
    ];
}

// Keep dynamicElements for backwards compatibility if needed
$dynamicElements = $bandPlateElements;

// Decode measurements or provide standard defaults (with size master fallback)
$measurements = !empty($lot['measurements_json']) ? json_decode($lot['measurements_json'], true) : [];
if (empty($measurements)) {
    $fallbackSizeId = $lot['single_size_id'] ?? null;
    if (!$fallbackSizeId && isset($lotId)) {
        $fallbackSizeId = $db->query("SELECT size_id FROM lot_variants WHERE lot_id = " . (int)$lotId . " LIMIT 1")->fetchColumn();
    }
    if ($fallbackSizeId) {
        $sizeRow = $db->query("SELECT measurements_json FROM sizes WHERE id = " . (int)$fallbackSizeId)->fetch(PDO::FETCH_ASSOC);
        if (!empty($sizeRow['measurements_json'])) {
            $measurements = json_decode($sizeRow['measurements_json'], true) ?: [];
        }
    }
}
$plaLen = $measurements['pla']['length'] ?? '41';
$plaWid = $measurements['pla']['width'] ?? '15';
$plaBor = $measurements['pla']['border'] ?? '46';
$samMins = $measurements['pla']['mins'] ?? ($lot['estimated_minutes'] ?? '7');

$bazoLen = $measurements['bazo']['length'] ?? '48';
$bazoWid = $measurements['bazo']['width'] ?? '14';
$bazoBor = $measurements['bazo']['border'] ?? '42';
$bazoMins = $measurements['bazo']['mins'] ?? ($lot['estimated_minutes'] ?? '7');

// Extract unique size labels from lot variants or single size for Size Auto Pick
$lotSizeLabels = [];
foreach ($variants as $v) {
    $sLabel = $v['size_code'] ?: $v['size_name'];
    if ($sLabel && !in_array($sLabel, $lotSizeLabels)) {
        $lotSizeLabels[] = $sLabel;
    }
}
if (empty($lotSizeLabels)) {
    if (!empty($lot['single_size_code'])) {
        $lotSizeLabels[] = $lot['single_size_code'];
    } elseif (!empty($lot['single_size_name'])) {
        $lotSizeLabels[] = $lot['single_size_name'];
    } else {
        $lotSizeLabels[] = '34/36';
    }
}

// Columns 1 to 4 size headers (Auto-picked)
$sizeCol1 = $lotSizeLabels[0] ?? (!empty($lot['single_size_name']) ? $lot['single_size_name'] : '34/36');
$sizeCol2 = $lotSizeLabels[1] ?? (isset($measurements['scheme']['2']) && !empty($measurements['scheme']['2']['length']) ? '2' : '');
$sizeCol3 = $lotSizeLabels[2] ?? (isset($measurements['scheme']['3']) && !empty($measurements['scheme']['3']['length']) ? '3' : '');
$sizeCol4 = $lotSizeLabels[3] ?? (isset($measurements['scheme']['4']) && !empty($measurements['scheme']['4']['length']) ? '4' : '');

// Size / Color Scheme Columns 1, 2, 3, 4 (Auto-fill from Palla if empty)
$scheme1Len = !empty($measurements['scheme']['1']['length']) ? $measurements['scheme']['1']['length'] : $plaLen;
$scheme1Wid = !empty($measurements['scheme']['1']['width']) ? $measurements['scheme']['1']['width'] : $plaWid;
$scheme1Bor = !empty($measurements['scheme']['1']['border']) ? $measurements['scheme']['1']['border'] : $plaBor;
$scheme1Mins = !empty($measurements['scheme']['1']['mins']) ? $measurements['scheme']['1']['mins'] : $samMins;

$scheme2Len = $measurements['scheme']['2']['length'] ?? '';
$scheme2Wid = $measurements['scheme']['2']['width'] ?? '';
$scheme2Bor = $measurements['scheme']['2']['border'] ?? '';
$scheme2Mins = $measurements['scheme']['2']['mins'] ?? '';

$scheme3Len = $measurements['scheme']['3']['length'] ?? '';
$scheme3Wid = $measurements['scheme']['3']['width'] ?? '';
$scheme3Bor = $measurements['scheme']['3']['border'] ?? '';
$scheme3Mins = $measurements['scheme']['3']['mins'] ?? '';

$scheme4Len = $measurements['scheme']['4']['length'] ?? '';
$scheme4Wid = $measurements['scheme']['4']['width'] ?? '';
$scheme4Bor = $measurements['scheme']['4']['border'] ?? '';
$scheme4Mins = $measurements['scheme']['4']['mins'] ?? '';

$machine = !empty($lot['lot_machine_name']) ? $lot['lot_machine_name'] : 'CMS 430';
$sketchImagePath = (!empty($lot['sketch_image_file']) && file_exists(__DIR__ . '/../../' . $lot['sketch_image_file'])) ? $lot['sketch_image_file'] : null;

$operatorDisplay = $lot['operator_name'] ?: ($lot['supervisor_name'] ?: 'Arshad');
if ($lot['shift_type'] === 'Double Shift' && $lot['night_operator_name']) {
    $operatorDisplay .= ' / ' . $lot['night_operator_name'];
}

$sizeDisplay = !empty($lot['single_size_name']) ? ($lot['single_size_name'] . ' (' . $lot['single_size_code'] . ')') : implode(', ', $sizesUsed);
if (empty($sizeDisplay)) $sizeDisplay = '34/36';

$designDisplay = $lot['design_number'] ?: '39/26';
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Lot Design Sheet - Lot #<?= clean($lot['lot_number']) ?></title>
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
            margin: 3mm 4mm 3mm 4mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            background: #f8fafc;
            color: #000;
            font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', 'Urdu Typesetting', Tahoma, 'Segoe UI', sans-serif;
            font-size: 13px;
            line-height: 1.45;
            direction: rtl;
            text-align: right;
            padding: 0;
            margin: 0;
        }
        /* Latin elements, monospace codes & numeric data */
        .font-latin,
        .badge-boxed,
        .badge-lot-dark,
        td[dir="ltr"],
        th[dir="ltr"],
        span[dir="ltr"],
        div[dir="ltr"] {
            font-family: Consolas, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif !important;
            line-height: 1.25;
        }

        .sheet-container {
            max-width: 890px;
            margin: 8px auto;
            background: #fff;
            padding: 6px 8px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            border-radius: 4px;
        }
        .form-section {
            border: 1.5px solid #000;
            padding: 5px 7px;
            margin-bottom: 5px;
            position: relative;
            background: #fff;
            min-height: 250px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
        }
        .section-body-row {
            flex: 1 1 auto;
            display: flex;
            align-items: stretch;
            margin-bottom: 3px;
        }
        .section-col-flex {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        /* Header Table */
        .header-grid-tbl {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            border-bottom: 1.5px solid #000;
            margin-bottom: 4px;
        }
        .header-grid-tbl td {
            padding: 2.5px 3.5px;
            vertical-align: middle;
            font-size: 13px;
            line-height: 1.4;
        }
        .lbl {
            font-weight: 700;
            color: #000;
            margin-left: 2px;
            font-size: 13.5px;
        }
        .val {
            color: #000;
        }
        .badge-boxed {
            border: 1px solid #000;
            padding: 1.5px 6px;
            background: #f8fafc;
            border-radius: 2px;
            font-weight: 700;
            font-size: 11.5px;
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
            vertical-align: middle;
        }
        /* Perforated Cutting Line */
        .perforated-cut-divider {
            position: relative;
            text-align: center;
            margin: 4px 0;
            height: 14px;
        }
        .perforated-cut-divider .cut-line {
            border-top: 1.5px dashed #444;
            position: absolute;
            top: 7px;
            left: 0;
            right: 0;
        }
        .perforated-cut-divider .cut-label {
            position: relative;
            display: inline-block;
            background: #fff;
            padding: 0 10px;
            font-size: 11px;
            font-weight: 700;
            color: #333;
            line-height: 14px;
        }
        /* Grids and Tables */
        .tbl-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            text-align: center;
            margin-bottom: 0;
        }
        .tbl-grid th, .tbl-grid td {
            border: 1px solid #000;
            padding: 3px 3px;
            vertical-align: middle;
            line-height: 1.35;
        }
        .tbl-grid th {
            background-color: #f1f3f5 !important;
            font-weight: 700;
            font-size: 13px;
            color: #000;
        }

        /* Prototype Sweater Card */
        .sweater-card {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            background: #fafafa;
            border-radius: 2px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sketch-svg {
            max-width: 100%;
            height: 68px;
            margin: 0 auto;
            display: block;
        }
        .sketch-meta {
            border-top: 1px dashed #777;
            padding-top: 2px;
            margin-top: 2px;
            font-size: 10px;
            line-height: 1.25;
            text-align: right;
            font-family: Consolas, 'Segoe UI', sans-serif;
        }
        .sketch-meta div {
            display: flex;
            justify-content: space-between;
        }

        .copy-footer-note {
            font-size: 10px;
            color: #444;
            margin-top: 3px;
            font-weight: 700;
            text-align: left;
            direction: ltr;
            font-family: Consolas, 'Segoe UI', sans-serif;
        }
        /* Top Navigation Bar */
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
                margin: 3mm 4mm 3mm 4mm;
            }
            html, body { 
                background: #fff !important; 
                font-size: 11.5px !important;
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
            .form-section { 
                height: 69mm !important;
                min-height: 69mm !important;
                max-height: 69mm !important;
                margin: 0 !important; 
                padding: 2mm 3.2mm !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }
            .section-body-row {
                flex: 1 1 auto !important;
                display: flex !important;
                align-items: stretch !important;
                margin-bottom: 1mm !important;
                min-height: 0 !important;
            }
            .section-col-flex {
                display: flex !important;
                flex-direction: column !important;
                height: 100% !important;
                min-height: 0 !important;
            }
            .perforated-cut-divider { 
                height: 3.5mm !important;
                min-height: 3.5mm !important;
                max-height: 3.5mm !important;
                margin: 0 !important; 
                line-height: 3.5mm !important;
                overflow: hidden !important;
            }
            .perforated-cut-divider .cut-line {
                top: 1.75mm !important;
                border-top: 1px dashed #666 !important;
            }
            .perforated-cut-divider .cut-label {
                font-size: 8px !important;
                line-height: 3.5mm !important;
                padding: 0 6px !important;
            }
            .header-grid-tbl {
                margin-bottom: 1mm !important;
            }
            .header-grid-tbl td {
                padding: 1px 2px !important;
                font-size: 11px !important;
                line-height: 1.25 !important;
            }
            .lbl {
                font-size: 11.5px !important;
            }
            .tbl-grid {
                height: 100% !important;
                width: 100% !important;
                flex: 1 1 auto !important;
            }
            .tbl-grid th, .tbl-grid td {
                padding: 1.5px 2px !important;
                font-size: 10.5px !important;
                line-height: 1.25 !important;
            }
            .tbl-grid th {
                font-size: 11px !important;
            }
            .sweater-card {
                height: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                padding: 2px 3px !important;
            }
            .sketch-svg {
                max-height: 35mm !important;
                min-height: 26mm !important;
                height: 100% !important;
            }
            .sketch-meta {
                font-size: 8.5px !important;
                line-height: 1.15 !important;
                padding-top: 1px !important;
                margin-top: 1px !important;
            }
            .copy-footer-note {
                font-size: 8.5px !important;
                margin-top: 1mm !important;
                line-height: 1.2 !important;
            }
            .badge-lot-dark {
                background: #000 !important;
                color: #fff !important;
            }
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar (Hidden on Print) -->
<div class="no-print-bar">
    <div class="d-flex align-items-center gap-3">
        <h5 class="mb-0 fw-bold"><i class="bi bi-printer me-2"></i>Lot Design Sheet</h5>
        <span class="badge bg-primary fs-6 font-monospace" dir="ltr">#<?= clean($lot['lot_number']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <div class="btn-group btn-group-sm" role="group">
            <a href="?id=<?= $lotId ?>&copy=all" class="btn btn-outline-light <?= $copyFilter === 'all' ? 'active' : '' ?>">All 4 Copies</a>
            <a href="?id=<?= $lotId ?>&copy=operator" class="btn btn-outline-light <?= $copyFilter === 'operator' ? 'active' : '' ?>">Operator Copy</a>
            <a href="?id=<?= $lotId ?>&copy=factory" class="btn btn-outline-light <?= $copyFilter === 'factory' ? 'active' : '' ?>">Factory Copy</a>
            <a href="?id=<?= $lotId ?>&copy=cutting" class="btn btn-outline-light <?= $copyFilter === 'cutting' ? 'active' : '' ?>">Cutting Master</a>
            <a href="?id=<?= $lotId ?>&copy=bandplate" class="btn btn-outline-light <?= $copyFilter === 'bandplate' ? 'active' : '' ?>">Band Plate Expense</a>
        </div>
        <button onclick="window.print()" class="btn btn-success btn-sm fw-bold px-3">
            <i class="bi bi-printer-fill me-1"></i> Print
        </button>
        <a href="<?= BASE_URL ?>modules/lots/edit.php?id=<?= $lotId ?>" class="btn btn-warning btn-sm fw-bold text-dark">
            <i class="bi bi-pencil-square me-1"></i> Edit
        </a>
        <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lotId ?>" class="btn btn-outline-secondary btn-sm text-white">
            <i class="bi bi-arrow-right me-1"></i> Back to Lot
        </a>
    </div>
</div>

<div class="sheet-container">

    <!-- ======================================================================= -->
    <!-- SECTION 1: OPERATOR COPY -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'operator'])): ?>
    <div class="form-section">
        <table class="header-grid-tbl">
            <tr>
                <td style="width: 20%;">
                    <span class="lbl">Operator:</span> <span class="val fw-bold"><?= clean($operatorDisplay) ?></span>
                </td>
                <td style="width: 14%;">
                    <span class="lbl">Machine:</span> <span class="badge-boxed" dir="ltr"><?= clean($machine) ?></span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Size:</span> <span class="badge-boxed" dir="ltr"><?= clean($sizeDisplay) ?></span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Design:</span> <span class="badge-boxed" dir="ltr"><?= clean($designDisplay) ?></span>
                </td>
                <td style="width: 12%;">
                    <span class="lbl">Quantity:</span> <span class="badge-boxed" dir="ltr"><?= (int)$lot['total_pieces'] ?></span>
                </td>
                <td style="width: 28%; text-align: left;">
                    <span class="lbl">Lot #:</span> <span class="badge-lot-dark" dir="ltr"><?= clean($lot['lot_number']) ?></span>
                </td>
            </tr>
        </table>

        <div class="row g-2 align-items-stretch section-body-row">
            <!-- Col 1 (Right): Color Table with Piece Breakdown (Clean without duplicate columns) -->
            <div class="col-5 section-col-flex">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th style="width: 28%;">Color Code</th>
                            <th style="width: 44%;">Color Name</th>
                            <th style="width: 28%;">Quantity (Pcs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($colorSummary as $cs): ?>
                            <tr>
                                <td class="fw-bold font-monospace" dir="ltr"><?= clean($cs['color_code']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <span class="rounded-circle border d-inline-block" style="width: 8px; height: 8px; background: <?= clean($cs['hex_code']) ?>;"></span>
                                        <span class="fw-bold"><?= clean($cs['color_name']) ?></span>
                                    </div>
                                </td>
                                <td class="fw-bold" dir="ltr"><?= (int)$cs['total_qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = count($colorSummary); $i < 4; $i++): ?>
                            <tr><td>&nbsp;</td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="2" class="text-start pe-2">Total Quantity:</td>
                            <td dir="ltr"><?= (int)$lot['total_pieces'] ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Col 2 (Center): Palla Measurements & Size Auto Pick Scheme -->
            <div class="col-4 section-col-flex">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th colspan="2" style="width: 40%;">Panel Specs</th>
                            <th colspan="4" style="width: 60%;">Size Measurements</th>
                        </tr>
                        <tr>
                            <th>Description</th>
                            <th>Size</th>
                            <th><?= clean($sizeCol4 ?: '4') ?></th>
                            <th><?= clean($sizeCol3 ?: '3') ?></th>
                            <th><?= clean($sizeCol2 ?: '2') ?></th>
                            <th><?= clean($sizeCol1 ?: '1') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold">Length</td>
                            <td class="fw-bold" dir="ltr"><?= clean($plaLen) ?></td>
                            <td dir="ltr"><?= clean($scheme4Len) ?></td>
                            <td dir="ltr"><?= clean($scheme3Len) ?></td>
                            <td dir="ltr"><?= clean($scheme2Len) ?></td>
                            <td dir="ltr"><?= clean($scheme1Len) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Width</td>
                            <td class="fw-bold" dir="ltr"><?= clean($plaWid) ?></td>
                            <td dir="ltr"><?= clean($scheme4Wid) ?></td>
                            <td dir="ltr"><?= clean($scheme3Wid) ?></td>
                            <td dir="ltr"><?= clean($scheme2Wid) ?></td>
                            <td dir="ltr"><?= clean($scheme1Wid) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Border</td>
                            <td class="fw-bold" dir="ltr"><?= clean($plaBor) ?></td>
                            <td dir="ltr"><?= clean($scheme4Bor) ?></td>
                            <td dir="ltr"><?= clean($scheme3Bor) ?></td>
                            <td dir="ltr"><?= clean($scheme2Bor) ?></td>
                            <td dir="ltr"><?= clean($scheme1Bor) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">mins</td>
                            <td class="fw-bold" dir="ltr"><?= clean($samMins) ?></td>
                            <td dir="ltr"><?= clean($scheme4Mins) ?></td>
                            <td dir="ltr"><?= clean($scheme3Mins) ?></td>
                            <td dir="ltr"><?= clean($scheme2Mins) ?></td>
                            <td dir="ltr"><?= clean($scheme1Mins) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Col 3 (Left): Sweater Prototype Sketch / Photo & SAM Box -->
            <div class="col-3 section-col-flex">
                <div class="sweater-card">
                    <?php if ($sketchImagePath): ?>
                        <div class="d-flex align-items-center justify-content-center p-1" style="height: 70px; background: #fff; border-radius: 2px; overflow: hidden;">
                            <img src="<?= BASE_URL . clean($sketchImagePath) ?>" alt="Garment Photo" class="sketch-img" style="max-height: 68px; max-width: 100%; object-fit: contain; margin: 0 auto; display: block;">
                        </div>
                    <?php else: ?>
                        <svg class="sketch-svg" viewBox="0 0 160 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Body -->
                            <path d="M45 32 L115 32 L120 115 L40 115 Z" stroke="#000" stroke-width="2" fill="#ffffff"/>
                            <!-- Ribbed Border -->
                            <rect x="40" y="107" width="80" height="8" stroke="#000" stroke-width="1.5" fill="#f1f5f9"/>
                            <line x1="50" y1="107" x2="50" y2="115" stroke="#000"/>
                            <line x1="60" y1="107" x2="60" y2="115" stroke="#000"/>
                            <line x1="70" y1="107" x2="70" y2="115" stroke="#000"/>
                            <line x1="80" y1="107" x2="80" y2="115" stroke="#000"/>
                            <line x1="90" y1="107" x2="90" y2="115" stroke="#000"/>
                            <line x1="100" y1="107" x2="100" y2="115" stroke="#000"/>
                            <line x1="110" y1="107" x2="110" y2="115" stroke="#000"/>
                            <!-- Neck (V-Neck) -->
                            <path d="M65 32 L80 56 L95 32 Z" stroke="#000" stroke-width="1.5" fill="#ffffff"/>
                            <!-- Sleeves -->
                            <?php if (empty($lot['has_anatomy_variants']) || !empty($sleevedQty)): ?>
                                <path d="M45 32 L15 75 L28 80 L50 50" stroke="#000" stroke-width="1.5" fill="#ffffff"/>
                                <path d="M115 32 L145 75 L132 80 L110 50" stroke="#000" stroke-width="1.5" fill="#ffffff"/>
                            <?php else: ?>
                                <!-- Sleeveless Cross lines -->
                                <line x1="20" y1="36" x2="45" y2="65" stroke="#dc2626" stroke-width="2" stroke-dasharray="3,3"/>
                                <line x1="140" y1="36" x2="115" y2="65" stroke="#dc2626" stroke-width="2" stroke-dasharray="3,3"/>
                            <?php endif; ?>
                        </svg>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center px-1 my-1">
                        <span class="small fw-bold">mins: <strong class="badge-boxed" dir="ltr"><?= clean($samMins) ?></strong></span>
                        <span class="badge bg-light text-dark border font-latin" style="font-size: 9.5px;"><?= clean($lot['product_name']) ?></span>
                    </div>

                    <div class="sketch-meta">
                        <div><span>DESIGN:</span> <strong dir="ltr"><?= clean($designDisplay) ?></strong></div>
                        <div><span>NUMBER:</span> <span dir="ltr"><?= clean($lot['product_code'] ?: '—') ?></span></div>
                        <div><span>YARN:</span> <span><?= clean($lot['yarn_type'] ?? 'Wool') ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="copy-footer-note">
            Copy #1: Machine Operator Record (Copy #1: Operator Copy)
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-cut-divider">
            <div class="cut-line"></div>
            <div class="cut-label"><i class="bi bi-scissors me-1"></i> Cut Here <i class="bi bi-scissors ms-1"></i></div>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 2: FACTORY COPY -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'factory'])): ?>
    <div class="form-section">
        <table class="header-grid-tbl">
            <tr>
                <td style="width: 20%;">
                    <span class="lbl">Operator:</span> <span class="val fw-bold"><?= clean($operatorDisplay) ?></span>
                </td>
                <td style="width: 14%;">
                    <span class="lbl">Machine:</span> <span class="badge-boxed" dir="ltr"><?= clean($machine) ?></span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Size:</span> <span class="badge-boxed" dir="ltr"><?= clean($sizeDisplay) ?></span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Design:</span> <span class="badge-boxed" dir="ltr"><?= clean($designDisplay) ?></span>
                </td>
                <td style="width: 12%;">
                    <span class="lbl">Quantity:</span> <span class="badge-boxed" dir="ltr"><?= (int)$lot['total_pieces'] ?></span>
                </td>
                <td style="width: 28%; text-align: left;">
                    <span class="lbl">Lot #:</span> <span class="badge-lot-dark" dir="ltr"><?= clean($lot['lot_number']) ?></span>
                </td>
            </tr>
        </table>

        <div class="row g-2 align-items-stretch section-body-row">
            <!-- Right: Color Breakdown Table (50%) -->
            <div class="col-6 section-col-flex">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th style="width: 24%;">Color Code</th>
                            <th style="width: 40%;">Color Name</th>
                            <th style="width: 18%;">Panel Qty</th>
                            <th style="width: 18%;">Sleeves Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($colorSummary as $cs): ?>
                            <tr>
                                <td class="fw-bold font-monospace" dir="ltr"><?= clean($cs['color_code']) ?></td>
                                <td class="fw-bold"><?= clean($cs['color_name']) ?></td>
                                <td class="fw-semibold" dir="ltr"><?= (int)$cs['total_qty'] ?></td>
                                <td class="fw-semibold" dir="ltr">
                                    <?php 
                                    if ($hasAnatomy && $totalLotPieces > 0) {
                                        $cWithSleeves = round(($sleevedQty / $totalLotPieces) * (int)$cs['total_qty']);
                                        echo (int)($cWithSleeves * 2);
                                    } else {
                                        echo (int)$cs['total_qty'] * 2;
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = count($colorSummary); $i < 4; $i++): ?>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="2" class="text-start pe-2">Total:</td>
                            <td dir="ltr"><?= (int)$lot['total_pieces'] ?></td>
                            <td dir="ltr">
                                <?= $hasAnatomy ? (int)($sleevedQty * 2) : (int)($lot['total_pieces'] * 2) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Left: Side-by-side Palla & Bazo Measurements (50%) with Size Auto Pick -->
            <div class="col-6 section-col-flex">
                <div class="row g-1">
                    <div class="col-6 section-col-flex">
                        <table class="tbl-grid">
                            <thead>
                                <tr><th colspan="2">Panel Specs (Size: <?= clean($sizeDisplay) ?>)</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Length</td><td class="fw-bold" dir="ltr"><?= clean($plaLen) ?></td></tr>
                                <tr><td>Width</td><td class="fw-bold" dir="ltr"><?= clean($plaWid) ?></td></tr>
                                <tr><td>Border</td><td class="fw-bold" dir="ltr"><?= clean($plaBor) ?></td></tr>
                                <tr><td>mins</td><td class="fw-bold" dir="ltr"><?= clean($samMins) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-6 section-col-flex">
                        <table class="tbl-grid">
                            <thead>
                                <tr><th colspan="2">Sleeves Specs (Size: <?= clean($sizeDisplay) ?>)</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Length</td><td class="fw-bold" dir="ltr"><?= clean($bazoLen) ?></td></tr>
                                <tr><td>Width</td><td class="fw-bold" dir="ltr"><?= clean($bazoWid) ?></td></tr>
                                <tr><td>Border</td><td class="fw-bold" dir="ltr"><?= clean($bazoBor) ?></td></tr>
                                <tr><td>mins</td><td class="fw-bold" dir="ltr"><?= clean($bazoMins) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="copy-footer-note">
            Copy #2: Factory Permanent Book Copy (Copy #2: Factory Permanent Copy)
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-cut-divider">
            <div class="cut-line"></div>
            <div class="cut-label"><i class="bi bi-scissors me-1"></i> Cut Here <i class="bi bi-scissors ms-1"></i></div>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 3: CUTTING MASTER SECTION (Cutting Master Copy) -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'cutting'])): ?>
    <div class="form-section">
        <table class="header-grid-tbl">
            <tr>
                <td style="width: 25%;">
                    <span class="lbl">Cutting Master:</span> <span class="val fw-bold">Ali Raza (Cutting Dept)</span>
                </td>
                <td style="width: 38%; text-align: center;">
                    <span class="badge bg-dark text-white px-2 py-1" style="font-size: 11.5px;">
                        Total Quantity: <?= number_format($totalLotPieces) ?> pcs 
                        <?php if ($hasAnatomy): ?>
                            (With Sleeves: <?= number_format($sleevedQty) ?> | Sleeveless: <?= number_format($sleevelessQty) ?>)
                        <?php endif; ?>
                    </span>
                </td>
                <td style="width: 12%;">
                    <span class="lbl">Size:</span> <span class="badge-boxed" dir="ltr"><?= clean($sizeDisplay) ?></span>
                </td>
                <td style="width: 11%;">
                    <span class="lbl">Design:</span> <span class="badge-boxed" dir="ltr"><?= clean($designDisplay) ?></span>
                </td>
                <td style="width: 14%; text-align: left;">
                    <span class="lbl">Lot #:</span> <span class="badge-lot-dark" dir="ltr"><?= clean($lot['lot_number']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Dynamic Product-Centric Cutting Table (Roomy, Wide & Dynamic Columns) -->
        <table class="tbl-grid tbl-cutting" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 10%;">Sketch</th>
                    <th style="width: 24%;">Color</th>
                    <th style="width: 24%;">Product / Design Type</th>
                    <th style="width: 14%;">With Sleeves</th>
                    <th style="width: 14%;">Sleeveless</th>
                    <th style="width: 14%;">Total Cut</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sumBazoCut = 0;
                $sumSandoCut = 0;
                $sumTotalCut = 0;
                $cIndex = 0;
                $totalColorsCount = count($colorSummary);
                foreach ($colorSummary as $cs): 
                    $cIndex++;
                    $cQty = (int)$cs['total_qty'];
                    if ($hasAnatomy && $totalLotPieces > 0) {
                        if ($cIndex === $totalColorsCount) {
                            $cBazo = max(0, $sleevedQty - $sumBazoCut);
                            $cSando = max(0, $sleevelessQty - $sumSandoCut);
                        } else {
                            $cBazo = (int)round(($sleevedQty / $totalLotPieces) * $cQty);
                            $cSando = max(0, $cQty - $cBazo);
                        }
                    } else {
                        $cBazo = $cQty;
                        $cSando = 0;
                    }
                    $sumBazoCut += $cBazo;
                    $sumSandoCut += $cSando;
                    $sumTotalCut += $cQty;
                ?>
                    <tr>
                        <td class="p-1 text-center" style="vertical-align: middle;">
                            <?php if ($sketchImagePath): ?>
                                <img src="<?= BASE_URL . clean($sketchImagePath) ?>" alt="Sketch" style="max-height: 38px; max-width: 45px; object-fit: contain; display: block; margin: auto;">
                            <?php else: ?>
                                <svg class="sketch-svg" style="height: 32px; width: 100%; max-width: 40px; margin: auto; display: block;" viewBox="0 0 160 140" fill="none">
                                    <path d="M45 32 L115 32 L120 115 L40 115 Z" stroke="#000" stroke-width="2" fill="#fff"/>
                                    <path d="M65 32 L80 56 L95 32 Z" stroke="#000" stroke-width="1.5" fill="#fff"/>
                                    <?php if ($cBazo > 0): ?>
                                        <path d="M45 32 L15 75 L28 80 L50 50" stroke="#000" stroke-width="1.5" fill="#fff"/>
                                        <path d="M115 32 L145 75 L132 80 L110 50" stroke="#000" stroke-width="1.5" fill="#fff"/>
                                    <?php endif; ?>
                                </svg>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span class="rounded-circle border d-inline-block" style="width: 8px; height: 8px; background: <?= clean($cs['hex_code']) ?>;"></span>
                                <strong class="font-monospace" dir="ltr"><?= clean($cs['color_code']) ?></strong>
                                <span>-</span>
                                <span class="fw-bold"><?= clean($cs['color_name']) ?></span>
                            </div>
                        </td>
                        <td class="fw-bold">
                            <?= clean($lot['product_name']) ?>
                            <?php if (!empty($lot['product_type']) && $lot['product_type'] !== $lot['product_name']): ?>
                                <span class="text-muted small">(<?= clean($lot['product_type']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold" dir="ltr"><?= (int)$cBazo ?></td>
                        <td class="fw-bold text-muted" dir="ltr"><?= (int)$cSando ?></td>
                        <td class="fw-bold" dir="ltr"><?= (int)$cQty ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = count($colorSummary); $i < 3; $i++): ?>
                    <tr>
                        <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold" style="background: #e9ecef !important;">
                    <td colspan="3" class="text-start pe-3">Total Cut Breakdown:</td>
                    <td dir="ltr"><?= (int)$sumBazoCut ?></td>
                    <td dir="ltr"><?= (int)$sumSandoCut ?></td>
                    <td dir="ltr"><?= (int)$sumTotalCut ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="copy-footer-note">
            Copy #3: Cutting Department Traveller (Copy #3: Cutting Master Copy) — Dynamic Color & Design Breakdown
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-cut-divider">
            <div class="cut-line"></div>
            <div class="cut-label"><i class="bi bi-scissors me-1"></i> Cut Here <i class="bi bi-scissors ms-1"></i></div>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 4: BAND PLATE / HAND PRINT EXPENSE -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'bandplate'])): ?>
    <div class="form-section">
        <table class="header-grid-tbl">
            <tr>
                <td style="width: 38%;">
                    <span class="lbl fw-bold">Band Plate Expense & Print Log</span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Size:</span> <span class="badge-boxed" dir="ltr"><?= clean($sizeDisplay) ?></span>
                </td>
                <td style="width: 13%;">
                    <span class="lbl">Design:</span> <span class="badge-boxed" dir="ltr"><?= clean($designDisplay) ?></span>
                </td>
                <td style="width: 12%;">
                    <span class="lbl">Quantity:</span> <span class="badge-boxed" dir="ltr"><?= (int)$lot['total_pieces'] ?></span>
                </td>
                <td style="width: 24%; text-align: left;">
                    <span class="lbl">Lot #:</span> <span class="badge-lot-dark" dir="ltr"><?= clean($lot['lot_number']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Dynamic Product Elements Table (Print & Band Plate Only - No Back, No Sleeves, No Extra Total) -->
        <table class="tbl-grid" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 28%;">Color (Color)</th>
                    <?php 
                    $elemColWidth = count($bandPlateElements) > 0 ? (72 / count($bandPlateElements)) : 72;
                    foreach ($bandPlateElements as $elem): 
                    ?>
                        <th style="width: <?= number_format($elemColWidth, 1) ?>%;">
                            <?= clean($elem['name']) ?>
                            <?php if ($elem['qty'] > 1): ?>
                                <span class="badge bg-light text-dark border px-1" dir="ltr">×<?= (int)$elem['qty'] ?></span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($colorSummary as $cs): 
                    $cQty = (int)$cs['total_qty'];
                ?>
                    <tr>
                        <td class="fw-bold font-monospace" dir="ltr"><?= clean($cs['color_code']) ?> (<?= clean($cs['color_name']) ?>)</td>
                        <?php foreach ($bandPlateElements as $elem): 
                            $ePieces = $cQty * $elem['qty'];
                        ?>
                            <td class="fw-semibold" dir="ltr"><?= (int)$ePieces ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = count($colorSummary); $i < 3; $i++): ?>
                    <tr>
                        <td>&nbsp;</td>
                        <?php foreach ($bandPlateElements as $elem): ?>
                            <td></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="copy-footer-note">
            Copy #4: Head Fit & Band Plate Record (Copy #4: Head Fit & Band Plate Record) — Dynamic Pattern Components
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
