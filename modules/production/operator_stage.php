<?php
/**
 * Garment Manufacturing ERP - Stage 1 Machine Operator Production Entry Forwarder
 * Automatically forwards legacy operator stage links directly to unified Production Entry.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['lot_id'] ?? 0);
redirect("modules/production/index.php" . ($lotId > 0 ? "?lot_id=" . $lotId : ""));
exit;
