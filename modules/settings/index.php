<?php
/**
 * Garment Manufacturing ERP - Factory Settings & Administration
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_role('super_admin');

$pageTitle = 'Administration & Settings';
$activeNav = 'settings';
$db = get_db();

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $settings = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'currency_symbol' => trim($_POST['currency_symbol'] ?? 'Rs.'),
            'tax_number' => trim($_POST['tax_number'] ?? ''),
            'allow_negative_stock' => isset($_POST['allow_negative_stock']) ? '1' : '0'
        ];

        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) 
                              VALUES (?, ?, 'Company') 
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        log_audit('UPDATE_SETTINGS', 'system_settings', null, null, $settings);
        redirect('modules/settings/index.php', 'Factory profile and settings updated successfully!');
    }

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 3);
        $phone = trim($_POST['phone'] ?? '');

        if (empty($username) || empty($password) || empty($fullName)) {
            set_flash('danger', 'Username, password and full name are required.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, role_id, phone, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$username, $hash, $fullName, $roleId, $phone]);
            log_audit('CREATE_USER', 'users', (int)$db->lastInsertId(), null, ['username' => $username, 'role_id' => $roleId]);
            redirect('modules/settings/index.php#users', "User '$fullName' created successfully!");
        }
    }

    if ($action === 'create_backup') {
        // Generate SQL dump of all tables
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $dump = "-- Garment ERP Database Backup\n-- Generated on: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $t) {
            $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
            $dump .= "DROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n\n";

            $rows = $db->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                foreach ($rows as $r) {
                    $cols = array_map(function($c) { return "`$c`"; }, array_keys($r));
                    $vals = array_map(function($v) use ($db) {
                        return $v === null ? "NULL" : $db->quote($v);
                    }, array_values($r));
                    $dump .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $dump .= "\n";
            }
        }
        $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $backupDir = __DIR__ . '/../../backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);
        $filename = 'backup_garment_' . date('Y-m-d_His') . '.sql';
        file_put_contents("$backupDir/$filename", $dump);

        log_audit('BACKUP_DATABASE', 'system_backups', null, null, ['filename' => $filename]);
        redirect('modules/settings/index.php#backup', "Database backup '$filename' created successfully!");
    }
}

// Fetch users
$users = $db->query("SELECT u.*, r.display_name AS role_display FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id ASC")->fetchAll();
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// List backup files
$backupDir = __DIR__ . '/../../backups';
$backups = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $f) {
        if (str_ends_with($f, '.sql')) {
            $backups[] = [
                'filename' => $f,
                'size' => filesize("$backupDir/$f"),
                'date' => filemtime("$backupDir/$f")
            ];
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Factory Settings & Administration</h4>
                <p class="text-muted small mb-0">Company branding, user permissions and database disaster recovery</p>
            </div>
        </div>

        <ul class="nav nav-pills mb-4 gap-2" id="settingsTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#tab-company" type="button">
                    <i class="bi bi-building me-1"></i> Company Profile
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#tab-users" type="button">
                    <i class="bi bi-people-fill me-1"></i> Users & Roles (<?= count($users) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#tab-backup" type="button">
                    <i class="bi bi-database-down me-1"></i> Database Backups
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- COMPANY PROFILE TAB -->
            <div class="tab-pane fade show active" id="tab-company">
                <div class="card-modern" style="max-width: 820px;">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Factory Branding & General Parameters</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_settings">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Factory / Company Name *</label>
                                    <input type="text" name="company_name" class="form-control" value="<?= clean(get_setting('company_name', 'Ali Garment Manufacturing Ltd.')) ?>" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Phone / WhatsApp</label>
                                    <input type="text" name="company_phone" class="form-control" value="<?= clean(get_setting('company_phone', '+92 300 1234567')) ?>">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label">Official Email</label>
                                    <input type="email" name="company_email" class="form-control" value="<?= clean(get_setting('company_email', 'info@aligarments.com')) ?>">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Tax / NTN / STRN Number</label>
                                    <input type="text" name="tax_number" class="form-control" value="<?= clean(get_setting('tax_number', 'NTN: 1234567-8')) ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Factory Address (Appears on Challans & Job Sheets)</label>
                                    <input type="text" name="company_address" class="form-control" value="<?= clean(get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan')) ?>">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label">Currency Symbol</label>
                                    <input type="text" name="currency_symbol" class="form-control" value="<?= clean(get_setting('currency_symbol', 'Rs.')) ?>">
                                </div>
                                <div class="col-12 col-md-6 d-flex align-items-center">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" name="allow_negative_stock" value="1" id="allow_neg" <?= get_setting('allow_negative_stock', '0') === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" for="allow_neg">
                                            Allow Negative Inventory in Emergency
                                        </label>
                                    </div>
                                </div>

                                <div class="col-12 d-flex justify-content-end pt-3 border-top">
                                    <button type="submit" class="btn btn-primary px-5 fw-bold">Save Settings</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- USERS & ROLES TAB -->
            <div class="tab-pane fade" id="tab-users">
                <div class="card-modern mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person-plus me-1 text-primary"></i> Create System User</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_user">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-control" placeholder="e.g. supervisor1" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control" placeholder="e.g. Master Shabbir" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Assigned Role *</label>
                                    <select name="role_id" class="form-select" required>
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= $r['id'] ?>"><?= clean($r['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Create User</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Active Users (<?= count($users) ?>)</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Assigned Role</th>
                                        <th>Status</th>
                                        <th>Created Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= clean($u['username']) ?></td>
                                            <td class="fw-semibold text-dark"><?= clean($u['full_name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($u['role_display']) ?></span></td>
                                            <td><span class="badge-status badge-completed">Active</span></td>
                                            <td><?= format_date($u['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BACKUP TAB -->
            <div class="tab-pane fade" id="tab-backup">
                <div class="card-modern mb-4">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Database Backup & Disaster Recovery</h6>
                            <span class="text-muted small">One-click complete MySQL snapshot containing all production lots, ledgers and stock</span>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_backup">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-success fw-bold">
                                <i class="bi bi-database-down me-1"></i> Create New Backup Now
                            </button>
                        </form>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Backup Filename</th>
                                        <th>Timestamp</th>
                                        <th class="text-end">File Size</th>
                                        <th class="text-end">Download</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($backups)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No database backups generated yet. Click "Create New Backup Now" to capture a snapshot.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($backups as $b): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><i class="bi bi-file-earmark-code me-2"></i><?= clean($b['filename']) ?></td>
                                                <td><?= date('d M Y, h:i A', $b['date']) ?></td>
                                                <td class="text-end fw-semibold"><?= number_format($b['size'] / 1024, 1) ?> KB</td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>backups/<?= clean($b['filename']) ?>" download class="btn btn-sm btn-outline-primary py-1 px-3">
                                                        <i class="bi bi-download me-1"></i> Download SQL
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
