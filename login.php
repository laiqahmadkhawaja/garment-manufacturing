<?php
/**
 * Garment Manufacturing ERP - Login Page
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    redirect('modules/dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Default password check or hash check
        if ($user && (password_verify($password, $user['password_hash']) || ($password === 'admin123' && $username === 'admin'))) {
            // Update hash if needed
            if ($password === 'admin123' && !password_verify($password, $user['password_hash'])) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $up = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $up->execute([$newHash, $user['id']]);
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            log_audit('LOGIN', 'users', $user['id'], null, ['username' => $username]);
            redirect('modules/dashboard/index.php', 'Welcome back, ' . $user['full_name'] . '!');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Garment Manufacturing ERP</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light py-4">
    <div class="container" style="max-width: 440px;">
        <div class="card-modern shadow-lg border-0">
            <div class="p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-3 p-3 mb-3 shadow-sm" style="width: 58px; height: 58px;">
                        <i class="bi bi-scissors fs-2"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">Garment Production ERP</h4>
                    <p class="text-muted small">Sign in to manage lots, workers & inventory</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?= clean($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="username" name="username" class="form-control" placeholder="Enter username" value="admin" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" value="admin123" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                        Sign In to ERP
                    </button>
                </form>

                <div class="mt-4 p-3 bg-light rounded-3 border small text-muted">
                    <div class="fw-bold text-dark mb-1">Default Factory Credentials:</div>
                    <div>Username: <code>admin</code></div>
                    <div>Password: <code>admin123</code></div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>assets/js/jquery.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
