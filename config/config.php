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
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
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
