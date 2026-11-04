<?php
/**
 * Garment Manufacturing ERP - Printable 4-Copy Lot Design Sheet (لاٹ ڈیزائن پرچہ)
 * Exact digital reproduction of the physical factory traveller:
 * 1. Operator Copy (آپریٹر کاپی)
 * 2. Factory Copy (فیکٹری کاپی)
 * 3. Cutting Master Copy (کٹائی ماسٹر)
 * 4. Band Plate Expense Copy (بینڈ فلیٹ خرچہ)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$copyFilter = trim($_GET['copy'] ?? 'all'); // 'all', 'operator', 'factory', 'cutting', 'bandplate'
$db = get_db();

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             COALESCE(l.design_number, d.design_number) AS design_number,
                             d.name AS design_name, d.machine_type, d.measurements_json, d.sketch_image,
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
    die("Lot #$lotId not found.");
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

// Dynamic Garment Types for Cutting Master Section
$garmentTypes = $db->query("SELECT * FROM garment_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// Decode measurements or provide standard defaults
$measurements = !empty($lot['measurements_json']) ? json_decode($lot['measurements_json'], true) : [];
$plaLen = $measurements['pla']['length'] ?? '41';
$plaWid = $measurements['pla']['width'] ?? '15';
$plaBor = $measurements['pla']['border'] ?? '46';
$bazoLen = $measurements['bazo']['length'] ?? '48';
$bazoWid = $measurements['bazo']['width'] ?? '14';
$bazoBor = $measurements['bazo']['border'] ?? '42';
$samMins = $lot['estimated_minutes'] ?? '7';

$machine = $lot['machine_type'] ?: 'CMS 430';
$operatorDisplay = $lot['operator_name'] ?: ($lot['supervisor_name'] ?: 'ارشد');
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
    <title>لاٹ ڈیزائن پرچہ - Lot #<?= clean($lot['lot_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 8mm 8mm 8mm;
        }
        body {
            background: #fff !important;
            color: #000;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.25;
            direction: rtl;
            text-align: right;
            padding: 0;
            margin: 0;
        }
        .sheet-container {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
        }
        .form-section {
            border: 2px solid #000;
            padding: 8px 10px;
            margin-bottom: 12px;
            position: relative;
            background: #fff;
        }
        .section-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 13px;
        }
        .perforated-divider {
            border-top: 2px dashed #666;
            margin: 14px 0;
            position: relative;
            text-align: center;
        }
        .perforated-divider span {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            padding: 0 10px;
            font-size: 10px;
            color: #666;
            font-weight: bold;
        }
        .tbl-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            text-align: center;
        }
        .tbl-grid th, .tbl-grid td {
            border: 1px solid #000;
            padding: 4px 5px;
            vertical-align: middle;
        }
        .tbl-grid th {
            background-color: #f1f1f1 !important;
            font-weight: bold;
            color: #000;
        }
        .sweater-sketch-box {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
            background: #fafafa;
            border-radius: 4px;
        }
        .sketch-svg {
            max-width: 100%;
            height: 120px;
        }
        .badge-lot {
            font-size: 14px;
            font-weight: 800;
            font-family: monospace;
            border: 1px solid #000;
            padding: 2px 8px;
            border-radius: 3px;
        }
        .no-print-bar {
            background: #1e293b;
            color: #fff;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        @media print {
            .no-print-bar { display: none !important; }
            body { font-size: 11px; }
            .form-section { page-break-inside: avoid; }
            .sheet-container { width: 100% !important; max-width: 100% !important; }
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar (Hidden on Print) -->
<div class="no-print-bar">
    <div class="d-flex align-items-center gap-3">
        <h5 class="mb-0 fw-bold"><i class="bi bi-printer me-2"></i>لاٹ ڈیزائن پرچہ (4-Copy Print Sheet)</h5>
        <span class="badge bg-primary fs-6 font-monospace">#<?= clean($lot['lot_number']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <div class="btn-group btn-group-sm" role="group">
            <a href="?id=<?= $lotId ?>&copy=all" class="btn btn-outline-light <?= $copyFilter === 'all' ? 'active' : '' ?>">All 4 Copies</a>
            <a href="?id=<?= $lotId ?>&copy=operator" class="btn btn-outline-light <?= $copyFilter === 'operator' ? 'active' : '' ?>">Operator Copy</a>
            <a href="?id=<?= $lotId ?>&copy=cutting" class="btn btn-outline-light <?= $copyFilter === 'cutting' ? 'active' : '' ?>">Cutting Master</a>
            <a href="?id=<?= $lotId ?>&copy=bandplate" class="btn btn-outline-light <?= $copyFilter === 'bandplate' ? 'active' : '' ?>">Band Print Expense</a>
        </div>
        <button onclick="window.print()" class="btn btn-success btn-sm fw-bold px-3">
            <i class="bi bi-printer-fill me-1"></i> Print Sheet (پرنٹ کریں)
        </button>
        <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lotId ?>" class="btn btn-outline-secondary btn-sm text-white">Back to Lot</a>
    </div>
</div>

<div class="sheet-container">

    <!-- ======================================================================= -->
    <!-- SECTION 1: OPERATOR COPY (آپریٹر کاپی) -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'operator'])): ?>
    <div class="form-section">
        <!-- Section Header -->
        <div class="section-header-bar">
            <div>
                <span>آپریٹر: </span> <span class="text-decoration-underline"><?= clean($operatorDisplay) ?></span>
                <span class="ms-3">مشین: </span> <span class="badge-lot bg-light"><?= clean($machine) ?></span>
            </div>
            <div>
                <span>تاریخ: </span> <span><?= format_date($lot['lot_date']) ?></span>
                <span class="ms-3">سائز: </span> <span class="badge-lot bg-light"><?= clean($sizeDisplay) ?></span>
            </div>
            <div>
                <span>ڈیزائن نمبر: </span> <span class="badge-lot bg-light"><?= clean($designDisplay) ?></span>
                <span class="ms-3">تعداد: </span> <span class="badge-lot bg-light"><?= (int)$lot['total_pieces'] ?></span>
                <span class="ms-3">لاٹ نمبر: </span> <span class="badge-lot bg-dark text-white font-monospace"><?= clean($lot['lot_number']) ?></span>
            </div>
        </div>

        <div class="row g-2 align-items-center">
            <!-- Left: Sweater Sketch with Measurements -->
            <div class="col-3">
                <div class="sweater-sketch-box">
                    <svg class="sketch-svg" viewBox="0 0 160 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Body -->
                        <path d="M45 35 L115 35 L120 120 L40 120 Z" stroke="#000" stroke-width="2" fill="#f8fafc"/>
                        <!-- Ribbed Border -->
                        <rect x="40" y="112" width="80" height="8" stroke="#000" stroke-width="1.5" fill="#e2e8f0"/>
                        <line x1="50" y1="112" x2="50" y2="120" stroke="#000"/>
                        <line x1="60" y1="112" x2="60" y2="120" stroke="#000"/>
                        <line x1="70" y1="112" x2="70" y2="120" stroke="#000"/>
                        <line x1="80" y1="112" x2="80" y2="120" stroke="#000"/>
                        <line x1="90" y1="112" x2="90" y2="120" stroke="#000"/>
                        <line x1="100" y1="112" x2="100" y2="120" stroke="#000"/>
                        <line x1="110" y1="112" x2="110" y2="120" stroke="#000"/>
                        <!-- Neck (V-Neck / Round) -->
                        <path d="M65 35 L80 60 L95 35 Z" stroke="#000" stroke-width="1.5" fill="#ffffff"/>
                        <!-- Sleeves -->
                        <?php if (empty($lot['has_anatomy_variants']) || !empty($lot['with_sleeves_qty'])): ?>
                            <path d="M45 35 L15 80 L28 85 L50 55" stroke="#000" stroke-width="1.5" fill="#f8fafc"/>
                            <path d="M115 35 L145 80 L132 85 L110 55" stroke="#000" stroke-width="1.5" fill="#f8fafc"/>
                        <?php else: ?>
                            <!-- Sleeveless Cross lines -->
                            <line x1="20" y1="40" x2="45" y2="70" stroke="#dc2626" stroke-width="2" stroke-dasharray="3,3"/>
                            <line x1="140" y1="40" x2="115" y2="70" stroke="#dc2626" stroke-width="2" stroke-dasharray="3,3"/>
                        <?php endif; ?>
                    </svg>
                    <div class="d-flex justify-content-between align-items-center mt-1 px-1 small fw-bold">
                        <span>منٹ: <strong class="badge bg-light text-dark border"><?= clean($samMins) ?></strong></span>
                        <span class="text-muted" style="font-size: 10px;"><?= clean($lot['product_name']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Center: Palla Measurements & Color Scheme -->
            <div class="col-4">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th colspan="2">پلا پیمائش</th>
                            <th colspan="4">کلر سکیم</th>
                        </tr>
                        <tr>
                            <th>تفصیل</th>
                            <th>سائز</th>
                            <th>4</th>
                            <th>3</th>
                            <th>2</th>
                            <th>1</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold">لمبائی</td>
                            <td><?= clean($plaLen) ?></td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">چوڑائی</td>
                            <td><?= clean($plaWid) ?></td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">بارڈر</td>
                            <td><?= clean($plaBor) ?></td>
                            <td></td><td></td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Right: Color Table with Piece Allocations -->
            <div class="col-5">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th style="width: 25%;">رنگ نمبر</th>
                            <th style="width: 35%;">رنگ نام</th>
                            <th style="width: 20%;">تعداد</th>
                            <th style="width: 20%;">پلا / بازو</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($colorSummary as $cs): ?>
                            <tr>
                                <td class="fw-bold font-monospace fs-6"><?= clean($cs['color_code']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <span class="rounded-circle border d-inline-block" style="width: 10px; height: 10px; background: <?= clean($cs['hex_code']) ?>;"></span>
                                        <span><?= clean($cs['color_name']) ?></span>
                                    </div>
                                </td>
                                <td class="fw-bold fs-6"><?= (int)$cs['total_qty'] ?></td>
                                <td class="small"><?= (int)$cs['total_qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Blank rows if fewer than 4 colors -->
                        <?php for ($i = count($colorSummary); $i < 4; $i++): ?>
                            <tr>
                                <td>&nbsp;</td><td></td><td></td><td></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="2" class="text-start pe-2">کل پیسز:</td>
                            <td class="fs-6"><?= (int)$lot['total_pieces'] ?></td>
                            <td>144</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="text-start small text-muted mt-1" style="font-size: 10px;">
            نقل نمبر 1: آپریٹر کاپی (Machine Operator Record)
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-divider">
            <span>✂ یہاں سے کاٹیں (Perforated Cutting Line) ✂</span>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 2: FACTORY COPY (فیکٹری کاپی) -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'factory'])): ?>
    <div class="form-section">
        <div class="section-header-bar">
            <div>
                <span>آپریٹر: </span> <span class="text-decoration-underline"><?= clean($operatorDisplay) ?></span>
                <span class="ms-3">مشین: </span> <span class="badge-lot bg-light"><?= clean($machine) ?></span>
            </div>
            <div>
                <span>تاریخ: </span> <span><?= format_date($lot['lot_date']) ?></span>
                <span class="ms-3">سائز: </span> <span class="badge-lot bg-light"><?= clean($sizeDisplay) ?></span>
            </div>
            <div>
                <span>ڈیزائن نمبر: </span> <span class="badge-lot bg-light"><?= clean($designDisplay) ?></span>
                <span class="ms-3">تعداد: </span> <span class="badge-lot bg-light"><?= (int)$lot['total_pieces'] ?></span>
                <span class="ms-3">لاٹ نمبر: </span> <span class="badge-lot bg-dark text-white font-monospace"><?= clean($lot['lot_number']) ?></span>
            </div>
        </div>

        <div class="row g-2 align-items-center">
            <!-- Palla & Bazo Measurements Side by Side -->
            <div class="col-6">
                <div class="row g-1">
                    <div class="col-6">
                        <table class="tbl-grid">
                            <thead>
                                <tr><th colspan="2">پلا پیمائش</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>لمبائی</td><td class="fw-bold"><?= clean($plaLen) ?></td></tr>
                                <tr><td>چوڑائی</td><td class="fw-bold"><?= clean($plaWid) ?></td></tr>
                                <tr><td>بارڈر</td><td class="fw-bold"><?= clean($plaBor) ?></td></tr>
                                <tr><td>منٹ</td><td class="fw-bold"><?= clean($samMins) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-6">
                        <table class="tbl-grid">
                            <thead>
                                <tr><th colspan="2">بازو پیمائش</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>لمبائی</td><td class="fw-bold"><?= clean($bazoLen) ?></td></tr>
                                <tr><td>چوڑائی</td><td class="fw-bold"><?= clean($bazoWid) ?></td></tr>
                                <tr><td>بارڈر</td><td class="fw-bold"><?= clean($bazoBor) ?></td></tr>
                                <tr><td>منٹ</td><td class="fw-bold"><?= clean($samMins) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right: Color Table with Palla / Bazo Breakdown -->
            <div class="col-6">
                <table class="tbl-grid">
                    <thead>
                        <tr>
                            <th>رنگ نمبر</th>
                            <th>رنگ نام</th>
                            <th>پلا تعداد</th>
                            <th>بازو تعداد</th>
                            <th>کل تعداد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($colorSummary as $cs): ?>
                            <tr>
                                <td class="fw-bold font-monospace"><?= clean($cs['color_code']) ?></td>
                                <td><?= clean($cs['color_name']) ?></td>
                                <td><?= (int)$cs['total_qty'] ?></td>
                                <td><?= (int)$cs['total_qty'] * 2 ?></td>
                                <td class="fw-bold"><?= (int)$cs['total_qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = count($colorSummary); $i < 4; $i++): ?>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="2" class="text-start pe-2">ٹوٹل:</td>
                            <td><?= (int)$lot['total_pieces'] ?></td>
                            <td><?= (int)$lot['total_pieces'] * 2 ?></td>
                            <td><?= (int)$lot['total_pieces'] ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="text-start small text-muted mt-1" style="font-size: 10px;">
            نقل نمبر 2: فیکٹری کاپی (Factory Permanent Book Copy)
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-divider">
            <span>✂ یہاں سے کاٹیں (Cutting Master Section) ✂</span>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 3: CUTTING MASTER SECTION (کٹائی ماسٹر) -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'cutting'])): ?>
    <div class="form-section">
        <div class="section-header-bar">
            <div>
                <span>کٹائی ماسٹر: </span> <span class="text-decoration-underline fw-bold">علی رضا (یا کٹنگ ماسٹر)</span>
                <span class="ms-3">کٹنگ تاریخ: </span> <span>____________________</span>
            </div>
            <div>
                <span>لاٹ نمبر: </span> <span class="badge-lot bg-dark text-white font-monospace"><?= clean($lot['lot_number']) ?></span>
                <span class="ms-3">ڈیزائن نمبر: </span> <span class="badge-lot bg-light"><?= clean($designDisplay) ?></span>
                <span class="ms-3">سائز: </span> <span class="badge-lot bg-light"><?= clean($sizeDisplay) ?></span>
                <span class="ms-3">تعداد: </span> <span class="badge-lot bg-light"><?= (int)$lot['total_pieces'] ?></span>
            </div>
        </div>

        <div class="row g-2 align-items-center">
            <!-- Small sweater thumbnail -->
            <div class="col-2 text-center">
                <svg class="sketch-svg" style="height: 80px;" viewBox="0 0 160 140" fill="none">
                    <path d="M45 35 L115 35 L120 120 L40 120 Z" stroke="#000" stroke-width="2" fill="#f8fafc"/>
                    <path d="M65 35 L80 60 L95 35 Z" stroke="#000" stroke-width="1.5" fill="#ffffff"/>
                    <path d="M45 35 L15 80 L28 85 L50 55" stroke="#000" stroke-width="1.5" fill="#f8fafc"/>
                    <path d="M115 35 L145 80 L132 85 L110 55" stroke="#000" stroke-width="1.5" fill="#f8fafc"/>
                </svg>
            </div>

            <!-- Dynamic Garment Types Grid -->
            <div class="col-10">
                <div class="table-responsive">
                    <table class="tbl-grid">
                        <thead>
                            <tr>
                                <?php foreach ($garmentTypes as $gt): ?>
                                    <th style="min-width: 60px; font-size: 10px;"><?= clean($gt['name']) ?></th>
                                <?php endforeach; ?>
                                <th style="min-width: 70px;">کل کٹنگ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Row 1: Pieces cut -->
                            <tr>
                                <?php foreach ($garmentTypes as $gt): ?>
                                    <td style="height: 32px;"></td>
                                <?php endforeach; ?>
                                <td class="fw-bold"></td>
                            </tr>
                            <!-- Row 2: Secondary notes or color -->
                            <tr>
                                <?php foreach ($garmentTypes as $gt): ?>
                                    <td style="height: 32px;"></td>
                                <?php endforeach; ?>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="text-start small text-muted mt-1" style="font-size: 10px;">
            نقل نمبر 3: کٹائی ماسٹر کاپی (Cutting Department Traveller) — تمام اقسام متحرک و ایڈمن سے قابلِ تبدیل ہیں
        </div>
    </div>
    <?php endif; ?>

    <!-- Perforated Line -->
    <?php if ($copyFilter === 'all'): ?>
        <div class="perforated-divider">
            <span>✂ یہاں سے کاٹیں (Band Plate / Hand Print Expense) ✂</span>
        </div>
    <?php endif; ?>

    <!-- ======================================================================= -->
    <!-- SECTION 4: BAND PLATE / HAND PRINT EXPENSE (بینڈ فلیٹ خرچہ) -->
    <!-- ======================================================================= -->
    <?php if (in_array($copyFilter, ['all', 'bandplate'])): ?>
    <div class="form-section">
        <div class="section-header-bar">
            <div>
                <span class="fs-6 fw-bold">بینڈ فلیٹ خرچہ / ہینڈ پرنٹ لاگ (Band Plate & Print Cost)</span>
            </div>
            <div>
                <span>لاٹ نمبر: </span> <span class="badge-lot bg-dark text-white font-monospace"><?= clean($lot['lot_number']) ?></span>
                <span class="ms-3">ڈیزائن نمبر: </span> <span class="badge-lot bg-light"><?= clean($designDisplay) ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="tbl-grid">
                <thead>
                    <tr>
                        <th style="width: 15%;">رنگ (Color)</th>
                        <th style="width: 12%;">لمبی ٹیپ</th>
                        <th style="width: 10%;">وی</th>
                        <th style="width: 10%;">کالر</th>
                        <th style="width: 10%;">بون</th>
                        <th style="width: 12%;">پاکٹ</th>
                        <th style="width: 15%;">گول گلا</th>
                        <th style="width: 16%;">کل تعداد (Total)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($colorSummary as $cs): ?>
                        <tr>
                            <td class="fw-bold font-monospace"><?= clean($cs['color_code']) ?> (<?= clean($cs['color_name']) ?>)</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?= (int)$cs['total_qty'] ?></td>
                            <td><?= (int)$cs['total_qty'] ?></td>
                            <td class="fw-bold fs-6"><?= (int)$cs['total_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($i = count($colorSummary); $i < 4; $i++): ?>
                        <tr>
                            <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td class="text-start pe-2">ٹوٹل پیسز:</td>
                        <td></td><td></td><td></td><td></td>
                        <td><?= (int)$lot['total_pieces'] ?></td>
                        <td><?= (int)$lot['total_pieces'] ?></td>
                        <td class="fs-6"><?= (int)$lot['total_pieces'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="text-start small text-muted mt-1" style="font-size: 10px;">
            نقل نمبر 4: بینڈ فلیٹ / ہینڈ پرنٹ خرچہ شیٹ (Head Fit & Band Plate Record)
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
