<?php
/**
 * Garment Manufacturing ERP - Header Partial
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth.php';

// Prevent caching for dynamic factory entries
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

$pageTitle = $pageTitle ?? 'Garment Manufacturing ERP';
$activeNav = $activeNav ?? 'dashboard';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= clean($pageTitle) ?> | <?= clean(get_setting('company_name', 'Garment ERP')) ?></title>
    
    <!-- Offline Local CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
</head>
<body>
<div class="app-wrapper">
