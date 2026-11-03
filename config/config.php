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

// Require DB
require_once __DIR__ . '/db.php';

// Dynamically determine BASE_URL
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Auto calculate subfolder
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $parts = explode('/', trim($scriptDir, '/'));
    $garmentIndex = array_search('Garment', $parts);
    if ($garmentIndex !== false) {
        $sub = '/' . implode('/', array_slice($parts, 0, $garmentIndex + 1));
    } else {
        $sub = '/Garment';
    }
    define('BASE_URL', rtrim($protocol . $hostName . $sub, '/') . '/');
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
        // extract numeric part
        preg_match('/\d+$/', $last, $matches);
        $next = isset($matches[0]) ? (int)$matches[0] + 1 : 101;
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
 * Trigger internal notification
 */
function create_notification(string $title, string $message, ?string $link = null, string $type = 'info', ?int $roleId = null, ?int $userId = null): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, role_id, title, message, link, alert_type) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $roleId, $title, $message, $link, $type]);
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
    }
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
            $txStmt = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, lot_id, warehouse_id, transaction_type, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, ?, 2, 'Material Issue', ?, ?, 'MATERIAL_ISSUE', ?, ?, ?)");
            
            foreach ($itemsToIssue as $iss) {
                $itemStmt->execute([$issueId, $iss['material_id'], $iss['quantity'], $iss['unit_price'], $iss['total_cost']]);
                $upMat->execute([$iss['quantity'], $iss['material_id']]);
                $txStmt->execute([$issueDate, $iss['material_id'], $lotId, $iss['quantity'], $iss['unit_price'], $issueId, "Auto recipe allocation for Lot #{$lot['lot_number']}", $_SESSION['user_id'] ?? null]);
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
