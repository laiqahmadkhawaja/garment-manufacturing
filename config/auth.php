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
        $stmt = $db->prepare("SELECT u.*, r.name AS role_name, r.display_name AS role_display 
                              FROM users u 
                              JOIN roles r ON u.role_id = r.id 
                              WHERE u.id = ? AND u.is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $userCache = $stmt->fetch() ?: null;
    }
    return $userCache;
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('login.php', 'Please log in to continue.', 'warning');
    }
}

function has_role(string|array $roles): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if ($user['role_name'] === 'super_admin') {
        return true;
    }
    if (is_string($roles)) {
        return $user['role_name'] === $roles;
    }
    return in_array($user['role_name'], $roles, true);
}

function require_role(string|array $roles): void {
    require_login();
    if (!has_role($roles)) {
        redirect('modules/dashboard/index.php', 'You do not have permission to access that section.', 'danger');
    }
}
