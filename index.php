<?php
/**
 * Garment Manufacturing ERP - Root Entry Point
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    redirect('modules/dashboard/index.php');
} else {
    redirect('login.php');
}
