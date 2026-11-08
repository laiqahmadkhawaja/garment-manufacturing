<?php
/**
 * Garment Manufacturing ERP - Core System Configuration & Helpers
 */

// Start Output Buffering to prevent header output issues
if (ob_get_level() === 0) {
    ob_start();
}

// Session configuration & start
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Production error logging configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../error.log');

// =========================================================================
// 1. DATABASE CONFIGURATION (ڈیٹا بیس کنفیگریشن)
// Update these credentials for your live hosting server or local environment:
// =========================================================================
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');     // Database host (usually 'localhost' or '127.0.0.1')
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');          // Database port (default: 3306)
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'garment_erp');   // Name of the database on MySQL
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');          // Database username
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // Database password

// Database Singleton Connection Helper
function get_db(): PDO {
    static $instance = null;
    if ($instance === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("<strong>Database connection error:</strong> Unable to connect to MySQL database [<strong>" . htmlspecialchars(DB_NAME) . "</strong>] on <strong>" . htmlspecialchars(DB_HOST) . "</strong>.<br><br>Please verify database credentials in <code>config/config.php</code>.<br><br><small>Technical details: " . htmlspecialchars($e->getMessage()) . "</small>");
        }
    }
    return $instance;
}

// =========================================================================
// 2. BASE URL AUTO-DETECTION (آٹو یو آر ایل ڈیٹیکشن)
// Automatically detects directory whether hosted at /Garment/, /smartfit/, or domain root.
// Can also be manually overridden: define('BASE_URL', 'https://example.com/smartfit/');
// =========================================================================
if (!defined('BASE_URL')) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
    $protocol = $isHttps ? "https://" : "http://";
    $hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.*?)/(modules|includes|config|api|database|scripts)/#', $scriptName, $m)) {
        $sub = $m[1];
    } else {
        $dir = str_replace('\\', '/', dirname($scriptName));
        $sub = ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
    }
    
    define('BASE_URL', rtrim($protocol . $hostName . '/' . ltrim($sub, '/'), '/') . '/');
}

define('APP_NAME', 'Garment ERP');
define('APP_VERSION', '1.0.0');

/**
 * Safe redirect helper
 */
function redirect(string $url, ?string $flashMessage = null, string $flashType = 'success'): void {
    if ($flashMessage !== null) {
        set_flash($flashType, $flashMessage);
    }
    
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = BASE_URL . ltrim($url, '/');
    }
    
    // Clean buffer before redirect
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Location: " . $url);
    exit;
}

/**
 * Flash message helpers
 */
function set_flash(string $type, string $message): void {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message
    ];
}

function get_flash_messages(): array {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * JSON Response helper for AJAX
 */
function json_response(array $data, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * String and Data Sanitization
 */
function clean(mixed $data): mixed {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function format_currency(float|int|string $amount): string {
    return 'Rs. ' . number_format((float)$amount, 2);
}

function format_date(?string $date, string $format = 'd M Y'): string {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * CSRF Protection
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function check_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verify_csrf($token)) {
        if (!empty($_SESSION['user_id'])) {
            // Re-generate token to allow retry
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        set_flash('danger', 'Security validation expired. Please submit the form again.');
        $backUrl = $_SERVER['HTTP_REFERER'] ?? (defined('BASE_URL') ? BASE_URL : '/');
        header("Location: $backUrl");
        exit;
    }
}

/**
 * Audit log recorder
 */
function log_audit(string $action, string $entityType, ?int $entityId = null, mixed $oldValues = null, mixed $newValues = null): void {
    try {
        $db = get_db();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues ? (is_string($oldValues) ? $oldValues : json_encode($oldValues)) : null,
            $newValues ? (is_string($newValues) ? $newValues : json_encode($newValues)) : null,
            $ip
        ]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

/**
 * Generate sequential document numbers
 */
function generate_doc_number(string $prefix, string $table, string $column): string {
    $db = get_db();
    $like = $prefix . '%';
    $stmt = $db->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();

    if ($last) {
        // extract numeric part directly following the prefix or anywhere in string
        if (preg_match('/' . preg_quote($prefix, '/') . '(\d+)/', $last, $matches)) {
            $next = (int)$matches[1] + 1;
        } elseif (preg_match('/(\d+)/', $last, $matches)) {
            $next = (int)$matches[1] + 1;
        } else {
            $next = 101;
        }
    } else {
        $next = 101;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Get system setting
 */
function get_setting(string $key, string $default = ''): string {
    static $settingsCache = null;
    if ($settingsCache === null) {
        $db = get_db();
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        $settingsCache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $settingsCache[$key] ?? $default;
}

/**
 * Helper to get default warehouse ID for raw materials or finished goods.
 * Dynamically resolves active warehouse or returns null if no warehouses exist.
 */
function get_default_warehouse_id(string $type = 'raw'): ?int {
    $db = get_db();
    try {
        if ($type === 'raw') {
            $stmt = $db->query("SELECT id FROM warehouses WHERE (code = 'WH-RAW' OR name LIKE '%Raw%') AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $id = $stmt ? $stmt->fetchColumn() : null;
            if ($id) return (int)$id;
        } else {
            $stmt = $db->query("SELECT id FROM warehouses WHERE (code IN ('WH-MAIN', 'WH-FIN') OR name LIKE '%Finished%' OR name LIKE '%Main%') AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $id = $stmt ? $stmt->fetchColumn() : null;
            if ($id) return (int)$id;
        }

        // Fallback: any active warehouse
        $stmt = $db->query("SELECT id FROM warehouses WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        $id = $stmt ? $stmt->fetchColumn() : null;
        if ($id) return (int)$id;
    } catch (Exception $e) {
        // Fallback to null
    }
    return null;
}

/**
 * Record an account transaction and update account balance atomically
 * 
 * @param int $accountId Account ID in cash_bank_accounts
 * @param string $date Transaction date (Y-m-d)
 * @param string $type Transaction type ('Deposit', 'Withdrawal', 'Transfer In', 'Transfer Out', 'Customer Payment', 'Supplier Payment', 'Labour Payment', 'Expense', 'Opening Balance', 'Adjustment')
 * @param float $amount Amount
 * @param string $flow 'inflow' (credit to account) or 'outflow' (debit from account)
 * @param string $description Description / notes
 * @param string|null $refType Reference type (e.g. 'EXPENSE', 'TRANSFER', 'WORKER_PAYMENT', 'DISPATCH')
 * @param int|null $refId Reference entity ID
 * @param string|null $refNo Reference voucher / check / receipt number
 * @param int|null $relatedAccId Related account ID (for transfers)
 * @param int|null $userId User performing transaction
 * @return int Transaction ID
 */
function record_account_transaction(
    int $accountId,
    string $date,
    string $type,
    float $amount,
    string $flow,
    string $description = '',
    ?string $refType = null,
    ?int $refId = null,
    ?string $refNo = null,
    ?int $relatedAccId = null,
    ?int $userId = null
): int {
    $db = get_db();
    $amount = abs($amount);
    
    // Fetch current account balance
    $stmt = $db->prepare("SELECT current_balance FROM cash_bank_accounts WHERE id = ? FOR UPDATE");
    $stmt->execute([$accountId]);
    $currentBal = (float)$stmt->fetchColumn();
    
    if ($flow === 'inflow') {
        $debit = 0.00;
        $credit = $amount;
        $newBal = $currentBal + $amount;
        $up = $db->prepare("UPDATE cash_bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
        $up->execute([$amount, $accountId]);
    } else {
        $debit = $amount;
        $credit = 0.00;
        $newBal = $currentBal - $amount;
        $up = $db->prepare("UPDATE cash_bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
        $up->execute([$amount, $accountId]);
    }
    
    $ins = $db->prepare("INSERT INTO account_transactions 
        (account_id, transaction_date, transaction_type, amount, debit, credit, running_balance, related_account_id, reference_type, reference_id, reference_no, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $accountId,
        $date,
        $type,
        $amount,
        $debit,
        $credit,
        $newBal,
        $relatedAccId,
        $refType,
        $refId,
        $refNo,
        $description,
        $userId ?? ($_SESSION['user_id'] ?? null)
    ]);
    
    return (int)$db->lastInsertId();
}

/**
 * Auto-issue raw materials to a production lot based on product BOM / Recipe
 * Automatically deducts available warehouse stock, creates material issue records,
 * and detects stock shortages with exact deficiency numbers.
 */
function auto_issue_lot_materials(int $lotId): array {
    $db = get_db();
    
    // 1. Fetch Lot info
    $lStmt = $db->prepare("SELECT l.*, p.name AS product_name FROM lots l JOIN products p ON l.product_id = p.id WHERE l.id = ?");
    $lStmt->execute([$lotId]);
    $lot = $lStmt->fetch();
    if (!$lot) {
        return ['success' => false, 'message' => 'Lot not found', 'issued_items' => [], 'shortages' => [], 'all_fulfilled' => false];
    }
    
    $totalPieces = (int)$lot['total_pieces'];
    if ($totalPieces <= 0) {
        return ['success' => false, 'message' => 'Lot has 0 pieces', 'issued_items' => [], 'shortages' => [], 'all_fulfilled' => false];
    }
    
    // 2. Fetch active BOM / Recipe for this product
    $bStmt = $db->prepare("SELECT * FROM bom_headers WHERE product_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $bStmt->execute([$lot['product_id']]);
    $recipe = $bStmt->fetch();
    if (!$recipe) {
        return ['success' => true, 'message' => 'No active recipe configured for product', 'issued_items' => [], 'shortages' => [], 'all_fulfilled' => true];
    }
    
    // 3. Fetch Recipe Items
    $biStmt = $db->prepare("SELECT bi.*, m.name AS material_name, m.code AS material_code, m.cost_price, m.current_stock, u.code AS unit_code 
                            FROM bom_items bi 
                            JOIN materials m ON bi.material_id = m.id 
                            JOIN units u ON m.unit_id = u.id 
                            WHERE bi.bom_id = ?");
    $biStmt->execute([$recipe['id']]);
    $items = $biStmt->fetchAll();
    if (empty($items)) {
        return ['success' => true, 'message' => 'Recipe has no items', 'issued_items' => [], 'shortages' => [], 'all_fulfilled' => true];
    }
    
    $wastageMult = 1 + (($recipe['wastage_percent'] ?? 0) / 100);
    $issuedItems = [];
    $shortages = [];
    $totalIssueCost = 0.00;
    $itemsToIssue = [];
    
    // 4. Calculate required vs already issued vs available stock
    foreach ($items as $it) {
        $matId = (int)$it['material_id'];
        $baseReq = $it['quantity_per_piece'] * $totalPieces;
        $batchRequired = round($baseReq * $wastageMult, 2);
        
        // Check already issued
        $issStmt = $db->prepare("SELECT COALESCE(SUM(mii.quantity), 0) 
                                 FROM material_issue_items mii 
                                 JOIN material_issues mi ON mii.issue_id = mi.id 
                                 WHERE mi.lot_id = ? AND mii.material_id = ?");
        $issStmt->execute([$lotId, $matId]);
        $alreadyIssued = (float)$issStmt->fetchColumn();
        
        $pendingToIssue = round(max(0, $batchRequired - $alreadyIssued), 2);
        
        // Fresh stock check from materials table
        $stkStmt = $db->prepare("SELECT current_stock, cost_price, name FROM materials WHERE id = ?");
        $stkStmt->execute([$matId]);
        $currMat = $stkStmt->fetch();
        $currStock = (float)($currMat['current_stock'] ?? 0);
        $unitCost = (float)($currMat['cost_price'] ?? 0);
        
        if ($pendingToIssue > 0) {
            if ($currStock >= $pendingToIssue) {
                // Full allocation available
                $qtyToIssue = $pendingToIssue;
                $itemsToIssue[] = [
                    'material_id' => $matId,
                    'material_name' => $it['material_name'],
                    'material_code' => $it['material_code'],
                    'unit_code' => $it['unit_code'],
                    'quantity' => $qtyToIssue,
                    'unit_price' => $unitCost,
                    'total_cost' => round($qtyToIssue * $unitCost, 2)
                ];
                $totalIssueCost += round($qtyToIssue * $unitCost, 2);
            } else {
                // Shortage exists
                $shortageAmount = round($pendingToIssue - max(0, $currStock), 2);
                $shortages[] = [
                    'material_id' => $matId,
                    'material_name' => $it['material_name'],
                    'material_code' => $it['material_code'],
                    'unit_code' => $it['unit_code'],
                    'batch_required' => $batchRequired,
                    'already_issued' => $alreadyIssued,
                    'pending' => $pendingToIssue,
                    'current_stock' => $currStock,
                    'shortage' => $shortageAmount
                ];
                
                // If there is any partial stock (> 0), issue available
                if ($currStock > 0) {
                    $qtyToIssue = $currStock;
                    $itemsToIssue[] = [
                        'material_id' => $matId,
                        'material_name' => $it['material_name'],
                        'material_code' => $it['material_code'],
                        'unit_code' => $it['unit_code'],
                        'quantity' => $qtyToIssue,
                        'unit_price' => $unitCost,
                        'total_cost' => round($qtyToIssue * $unitCost, 2)
                    ];
                    $totalIssueCost += round($qtyToIssue * $unitCost, 2);
                }
            }
        }
    }
    
    // 5. If items need issuing, create material_issues batch
    if (!empty($itemsToIssue)) {
        $db->beginTransaction();
        try {
            $issueNum = generate_doc_number('ISS-', 'material_issues', 'issue_number');
            $issueDate = date('Y-m-d');
            $notes = "Auto-issued from Recipe for Lot #{$lot['lot_number']} ($totalPieces pcs)";
            
            $iStmt = $db->prepare("INSERT INTO material_issues (issue_number, issue_date, lot_id, total_cost, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $iStmt->execute([$issueNum, $issueDate, $lotId, $totalIssueCost, $notes, $_SESSION['user_id'] ?? null]);
            $issueId = (int)$db->lastInsertId();
            
            $itemStmt = $db->prepare("INSERT INTO material_issue_items (issue_id, material_id, quantity, unit_price, total_cost) VALUES (?, ?, ?, ?, ?)");
            $upMat = $db->prepare("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?");
            $whId = get_default_warehouse_id('raw');
            $txStmt = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, lot_id, warehouse_id, transaction_type, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, ?, ?, 'Material Issue', ?, ?, 'MATERIAL_ISSUE', ?, ?, ?)");
            
            foreach ($itemsToIssue as $iss) {
                $itemStmt->execute([$issueId, $iss['material_id'], $iss['quantity'], $iss['unit_price'], $iss['total_cost']]);
                $upMat->execute([$iss['quantity'], $iss['material_id']]);
                $txStmt->execute([$issueDate, $iss['material_id'], $lotId, $whId, $iss['quantity'], $iss['unit_price'], $issueId, "Auto recipe allocation for Lot #{$lot['lot_number']}", $_SESSION['user_id'] ?? null]);
            }
            
            log_audit('AUTO_ISSUE_MATERIAL', 'material_issues', $issueId, null, [
                'lot_id' => $lotId,
                'items_count' => count($itemsToIssue),
                'total_cost' => $totalIssueCost
            ]);
            
            $db->commit();
            $issuedItems = $itemsToIssue;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to auto issue materials: " . $e->getMessage());
        }
    }
    
    return [
        'success' => true,
        'issued_items' => $issuedItems,
        'shortages' => $shortages,
        'total_shortage_items' => count($shortages),
        'all_fulfilled' => empty($shortages)
    ];
}

/**
 * Calculate required cutting pieces for garment elements based on lot pieces and anatomy breakdown
 *
 * E.g., if a lot has 100 waistcoats/sweaters with 80 sleeved and 20 sleeveless:
 * Sleeves (2 per piece) -> 80 * 2 = 160 sleeves.
 * Front/Back (1 per piece) -> 100 * 1 = 100 fronts, 100 backs.
 *
 * @param array $productElements List of elements from product_elements table
 * @param int $totalPieces Total lot pieces
 * @param bool $hasAnatomyVariants Whether lot has anatomy breakdown (sleeves vs sleeveless)
 * @param array $anatomyBreakdown Breakdown array with keys 'with_sleeves', 'sleeveless'
 * @return array Calculated elements with batch required, target pieces, notes, and total cutting count
 */
function calculate_lot_element_requirements(array $productElements, int $totalPieces, bool $hasAnatomyVariants = false, array $anatomyBreakdown = []): array {
    $result = [];
    $totalBatchCut = 0;
    $sleevedQty = (int)($anatomyBreakdown['with_sleeves'] ?? $totalPieces);
    $sleevelessQty = (int)($anatomyBreakdown['sleeveless'] ?? 0);

    foreach ($productElements as $pe) {
        $name = trim($pe['element_name'] ?? '');
        $qtyPerUnit = (int)($pe['quantity'] ?? 1);
        $isSleeve = (bool)preg_match('/(sleeve|بازو|bazu|aasteen|آستین)/iu', $name);

        if ($hasAnatomyVariants && $isSleeve) {
            $targetPieces = $sleevedQty;
            $batchCut = $qtyPerUnit * $sleevedQty;
            $calcNote = "{$sleevedQty} بازو والے پیسز کے لیے ({$qtyPerUnit} × {$sleevedQty} = {$batchCut})";
        } else {
            $targetPieces = $totalPieces;
            $batchCut = $qtyPerUnit * $totalPieces;
            $calcNote = "تمام {$totalPieces} پیسز کے لیے ({$qtyPerUnit} × {$totalPieces} = {$batchCut})";
        }

        $totalBatchCut += $batchCut;

        $result[] = [
            'id' => $pe['id'] ?? null,
            'element_name' => $name,
            'quantity_per_unit' => $qtyPerUnit,
            'target_pieces' => $targetPieces,
            'batch_required' => $batchCut,
            'is_sleeve' => $isSleeve,
            'calc_note' => $calcNote,
            'original_notes' => $pe['notes'] ?? ''
        ];
    }

    return [
        'elements' => $result,
        'total_cut_pieces' => $totalBatchCut,
        'has_anatomy_variants' => $hasAnatomyVariants,
        'sleeved_qty' => $sleevedQty,
        'sleeveless_qty' => $sleevelessQty
    ];
}

/**
 * Calculate allowable pieces per variant and per color for a given workflow stage on a lot.
 * Strictly guarantees that if a color had rejections in a preceding stage (e.g., 1 piece of 1192 rejected in Stitching),
 * subsequent stages automatically restrict that exact color to the passed good quantity (e.g., 49 instead of 50),
 * preventing users from shifting defective pieces across colors.
 *
 * @param int $lotId
 * @param int $operationId
 * @return array
 */
function get_lot_variant_stage_allowance(int $lotId, int $operationId): array {
    $db = get_db();

    // 1. Get current operation sequence in this lot
    $seqStmt = $db->prepare("SELECT sequence FROM lot_workflow_steps WHERE lot_id = ? AND operation_id = ?");
    $seqStmt->execute([$lotId, $operationId]);
    $currSeq = $seqStmt->fetchColumn();

    if ($currSeq === false) {
        // Fallback for sub-operations
        $subStmt = $db->prepare("SELECT lws.sequence FROM operations o JOIN lot_workflow_steps lws ON o.parent_id = lws.operation_id WHERE o.id = ? AND lws.lot_id = ?");
        $subStmt->execute([$operationId, $lotId]);
        $currSeq = $subStmt->fetchColumn();
        if ($currSeq === false) {
            $currSeq = (int)$db->query("SELECT sequence FROM operations WHERE id = $operationId")->fetchColumn();
        }
    }
    $currSeq = (float)$currSeq;

    // 2. Fetch all variants planned for this lot
    $vStmt = $db->prepare("SELECT lv.color_id, lv.size_id, lv.quantity AS planned, 
                                  c.name AS color_name, COALESCE(c.color_code, c.name) AS color_code,
                                  s.name AS size_name, s.code AS size_code
                           FROM lot_variants lv
                           JOIN colors c ON lv.color_id = c.id
                           JOIN sizes s ON lv.size_id = s.id
                           WHERE lv.lot_id = ?");
    $vStmt->execute([$lotId]);
    $lotVariants = $vStmt->fetchAll();

    // 3. Find preceding workflow steps with sequence < currSeq
    $prevStepsStmt = $db->prepare("SELECT lws.operation_id, o.name AS op_name, lws.sequence
                                   FROM lot_workflow_steps lws
                                   JOIN operations o ON lws.operation_id = o.id
                                   WHERE lws.lot_id = ? AND lws.sequence < ?
                                   ORDER BY lws.sequence DESC");
    $prevStepsStmt->execute([$lotId, $currSeq]);
    $prevSteps = $prevStepsStmt->fetchAll();

    // Check total output of immediate preceding step (e.g. Operator Entry before Cutting)
    $prevOpTotalGood = null;
    if (!empty($prevSteps)) {
        $immPrevOpId = (int)$prevSteps[0]['operation_id'];
        $totStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
        $totStmt->execute([$lotId, $immPrevOpId]);
        $prevOpTotalGood = (int)$totStmt->fetchColumn();
    }

    $prevColorOpId = null;
    $prevColorOpName = null;

    // Find the closest preceding step that has color-tracked production entries
    foreach ($prevSteps as $ps) {
        $chkStmt = $db->prepare("SELECT COUNT(*) FROM production_entries WHERE lot_id = ? AND operation_id = ? AND color_id IS NOT NULL");
        $chkStmt->execute([$lotId, $ps['operation_id']]);
        if ((int)$chkStmt->fetchColumn() > 0) {
            $prevColorOpId = (int)$ps['operation_id'];
            $prevColorOpName = $ps['op_name'];
            break;
        }
    }

    // 4. Query already recorded good quantities in current operation
    $curDoneStmt = $db->prepare("SELECT color_id, size_id, COALESCE(SUM(good_qty), 0) AS good_done, COALESCE(SUM(rejected_qty), 0) AS rejected_done
                                 FROM production_entries 
                                 WHERE lot_id = ? AND operation_id = ? AND color_id IS NOT NULL
                                 GROUP BY color_id, size_id");
    $curDoneStmt->execute([$lotId, $operationId]);
    $currentDoneMap = [];
    while ($cd = $curDoneStmt->fetch()) {
        $currentDoneMap[$cd['color_id'] . '_' . $cd['size_id']] = [
            'good_done' => (int)$cd['good_done'],
            'rejected_done' => (int)$cd['rejected_done']
        ];
    }

    // 5. Query previous operation's outputs per variant if preceding color stage exists
    $prevGoodMap = [];
    $prevColorGoodTotals = [];
    $prevColorRejTotals = [];
    if ($prevColorOpId !== null) {
        $pStmt = $db->prepare("SELECT color_id, size_id, 
                                      COALESCE(SUM(good_qty), 0) AS good_done,
                                      COALESCE(SUM(rejected_qty), 0) AS rejected_done
                               FROM production_entries
                               WHERE lot_id = ? AND operation_id = ? AND color_id IS NOT NULL
                               GROUP BY color_id, size_id");
        $pStmt->execute([$lotId, $prevColorOpId]);
        while ($pRow = $pStmt->fetch()) {
            $k = $pRow['color_id'] . '_' . $pRow['size_id'];
            $prevGoodMap[$k] = [
                'good_done' => (int)$pRow['good_done'],
                'rejected_done' => (int)$pRow['rejected_done']
            ];
            $cId = (int)$pRow['color_id'];
            $prevColorGoodTotals[$cId] = ($prevColorGoodTotals[$cId] ?? 0) + (int)$pRow['good_done'];
            $prevColorRejTotals[$cId] = ($prevColorRejTotals[$cId] ?? 0) + (int)$pRow['rejected_done'];
        }
    }

    // 6. Build allowance matrix
    $variantAllowances = [];
    $colorAllowances = [];

    foreach ($lotVariants as $lv) {
        $cId = (int)$lv['color_id'];
        $sId = (int)$lv['size_id'];
        $k = $cId . '_' . $sId;
        $planned = (int)$lv['planned'];

        $curDone = (int)($currentDoneMap[$k]['good_done'] ?? 0);
        $curRej = (int)($currentDoneMap[$k]['rejected_done'] ?? 0);

        if ($prevColorOpId !== null) {
            // Preceding color stage exists: allowable is good pieces passed from prev stage
            $prevGood = (int)($prevGoodMap[$k]['good_done'] ?? 0);
            $prevRej = (int)($prevGoodMap[$k]['rejected_done'] ?? 0);
            $allowable = min($planned, $prevGood);
            if ($prevOpTotalGood !== null) {
                $allowable = min($allowable, (int)$prevOpTotalGood);
            }
        } elseif (!empty($prevSteps)) {
            // Preceding stage exists in workflow (e.g. Cutting before Tailoring, Tailoring before Safety)
            // Allowable is strictly bounded by what was completed in immediate preceding stage
            $prevGood = min($planned, (int)$prevOpTotalGood);
            $prevRej = 0;
            $allowable = $prevGood;
            if (empty($prevColorOpName) && !empty($prevSteps[0]['op_name'])) {
                $prevColorOpName = $prevSteps[0]['op_name'];
            }
        } else {
            // First stage in lot workflow: bounded by planned quantity
            $prevGood = $planned;
            $prevRej = 0;
            $allowable = $planned;
        }

        $pending = max(0, $allowable - $curDone);

        $variantAllowances[$k] = [
            'color_id' => $cId,
            'color_name' => $lv['color_name'],
            'color_code' => $lv['color_code'],
            'size_id' => $sId,
            'size_name' => $lv['size_name'],
            'size_code' => $lv['size_code'],
            'planned' => $planned,
            'prev_stage_good' => $prevGood,
            'prev_stage_rejected' => $prevRej,
            'allowable' => $allowable,
            'current_done' => $curDone,
            'current_rejected' => $curRej,
            'pending' => $pending
        ];

        // Color aggregated allowances
        if (!isset($colorAllowances[$cId])) {
            $colorAllowances[$cId] = [
                'color_id' => $cId,
                'color_name' => $lv['color_name'],
                'color_code' => $lv['color_code'],
                'planned' => 0,
                'prev_stage_good' => 0,
                'prev_stage_rejected' => 0,
                'allowable' => 0,
                'current_done' => 0,
                'pending' => 0
            ];
        }
        $colorAllowances[$cId]['planned'] += $planned;
        $colorAllowances[$cId]['prev_stage_good'] += $prevGood;
        $colorAllowances[$cId]['prev_stage_rejected'] += $prevRej;
        $colorAllowances[$cId]['allowable'] += $allowable;
        $colorAllowances[$cId]['current_done'] += $curDone;
        $colorAllowances[$cId]['pending'] += $pending;
    }

    return [
        'has_prev_color_stage' => ($prevColorOpId !== null),
        'prev_operation_id' => $prevColorOpId,
        'prev_operation_name' => $prevColorOpName,
        'prev_stage_total_good' => $prevOpTotalGood,
        'variants' => $variantAllowances,
        'colors' => $colorAllowances
    ];
}

/**
 * Determine the immediate next workflow operation for a lot after a given operation.
 * Supports both signatures: (int $lotId, int $currentOpId) or (PDO $db, int $lotId, int $currentOpId)
 * 
 * @param mixed $arg1 Lot ID or PDO instance
 * @param mixed $arg2 Current Op ID or Lot ID
 * @param int|null $arg3 Current Op ID if $arg1 is PDO
 * @return array|null Next operation info ['operation_id', 'op_name', 'op_code', 'sequence', 'default_mode'] or null if final step.
 */
function get_next_lot_workflow_operation(mixed $arg1, mixed $arg2, ?int $arg3 = null): ?array {
    $db = get_db();
    if ($arg1 instanceof PDO) {
        $lotId = (int)$arg2;
        $currentOpId = (int)$arg3;
    } else {
        $lotId = (int)$arg1;
        $currentOpId = (int)$arg2;
    }

    // Query all applicable operations in chronological sequence for this lot
    $stmt = $db->prepare("SELECT o.id AS operation_id, o.name AS op_name, o.code AS op_code,
                                 COALESCE(lws.sequence, o.sequence) AS sequence,
                                 COALESCE(lws.sequence, (COALESCE(p_op.sequence, 10) + (o.sequence / 100))) AS effective_seq
                          FROM operations o
                          LEFT JOIN lot_workflow_steps lws ON lws.operation_id = o.id AND lws.lot_id = ?
                          LEFT JOIN operations p_op ON o.parent_id = p_op.id
                          WHERE (lws.lot_id = ? OR o.parent_id IN (SELECT operation_id FROM lot_workflow_steps WHERE lot_id = ?))
                            AND o.is_active = 1
                          ORDER BY effective_seq ASC, o.id ASC");
    $stmt->execute([$lotId, $lotId, $lotId]);
    $ops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundCurrent = false;
    foreach ($ops as $op) {
        if ($foundCurrent) {
            $nameLower = strtolower($op['op_name']);
            $code = $op['op_code'] ?? '';
            $mode = 'matrix';
            if (str_contains($nameLower, 'cut') || $code === 'OP-CUT') {
                $mode = 'cutting';
            } elseif (str_contains($nameLower, 'operator') || str_contains($nameLower, 'knitt') || $code === 'OP-OPERATOR') {
                $mode = 'operator_initial';
            }
            $op['default_mode'] = $mode;
            return $op;
        }
        if ((int)$op['operation_id'] === $currentOpId) {
            $foundCurrent = true;
        }
    }
    return null;
}

/**
 * Redirect to the next operation in a lot's workflow or lot overview upon step completion.
 */
function redirect_next_production_step(int $lotId, int $operationId, string $message, string $fallbackMode = 'matrix'): void {
    $nextOp = get_next_lot_workflow_operation($lotId, $operationId);
    if ($nextOp) {
        $nextOpId = (int)$nextOp['operation_id'];
        $nextMode = $nextOp['default_mode'] ?? $fallbackMode;
        $message .= " — اگلا مرحلہ لوڈ ہو گیا: {$nextOp['op_name']}";
        redirect("modules/production/index.php?lot_id=$lotId&operation_id=$nextOpId&mode=$nextMode", $message);
    } else {
        $message .= " — اس لاٹ کے تمام ورک فلو مراحل مکمل ہو گئے!";
        redirect("modules/production/index.php?lot_id=$lotId", $message);
    }
}

