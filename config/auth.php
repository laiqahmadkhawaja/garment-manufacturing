<?php
/**
 * Garment Manufacturing ERP - Authentication & Access Control
 */

require_once __DIR__ . '/config.php';

function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    static $userCache = null;
    if ($userCache === null) {
        $db = get_db();
        $stmt = $db->prepare("SELECT u.*, 
                                     COALESCE(r.name, CASE WHEN u.role_id = 1 OR u.username = 'admin' THEN 'super_admin' ELSE 'viewer' END) AS role_name, 
                                     COALESCE(r.display_name, CASE WHEN u.role_id = 1 OR u.username = 'admin' THEN 'Super Admin' ELSE 'Viewer' END) AS role_display 
                              FROM users u 
                              LEFT JOIN roles r ON u.role_id = r.id 
                              WHERE u.id = ? AND u.is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $userCache = $stmt->fetch() ?: null;
    }
    return $userCache;
}

function require_login(): void {
    if (!is_logged_in() || current_user() === null) {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['role']);
        redirect('login.php', 'Please log in to continue.', 'warning');
    }
}

function has_role(string|array $roles): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    $roleName = strtolower($user['role_name'] ?? '');
    $username = strtolower($user['username'] ?? '');
    $roleId = (int)($user['role_id'] ?? 0);

    // Super admin or user with role_id=1 or username 'admin' has universal master access
    if (in_array($roleName, ['super_admin', 'admin', 'administrator'], true) || $username === 'admin' || $roleId === 1) {
        return true;
    }
    if (is_string($roles)) {
        return strtolower($user['role_name'] ?? '') === strtolower($roles);
    }
    $lowerRoles = array_map('strtolower', $roles);
    return in_array(strtolower($user['role_name'] ?? ''), $lowerRoles, true);
}

function require_role(string|array $roles): void {
    require_login();
    if (!has_role($roles)) {
        redirect('modules/dashboard/index.php', 'You do not have permission to access that section.', 'danger');
    }
}
