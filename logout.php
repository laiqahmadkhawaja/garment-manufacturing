<?php
/**
 * Garment Manufacturing ERP - Logout
 */
require_once __DIR__ . '/config/config.php';

if (isset($_SESSION['user_id'])) {
    log_audit('LOGOUT', 'users', $_SESSION['user_id']);
}

session_unset();
session_destroy();

redirect('login.php', 'You have been safely signed out.', 'info');
