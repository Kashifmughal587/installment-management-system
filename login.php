<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $identity = post('identity');
    $password = (string)($_POST['password'] ?? '');

    $result = attempt_login($identity, $password);
    if ($result['ok']) {
        // Never redirect to an arbitrary external URL.
        $intended = $_SESSION['_intended'] ?? '';
        unset($_SESSION['_intended']);
        if ($intended && strpos($intended, '//') === false && $intended[0] === '/' && stripos($intended, '/login.php') === false) {
            redirect($intended);
        }
        redirect('dashboard.php');
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?= e(asset('css/app.css')) ?>?v=1" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-wrapper">
        <div class="card login-card shadow">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <?php if ($loginLogo = setting('logo_path', '')): ?>
                        <img src="<?= e(upload_url($loginLogo)) ?>" alt="<?= e(setting('shop_name', APP_NAME)) ?>" class="login-logo">
                    <?php else: ?>
                        <span class="login-mark brand-initials"><?= e(shop_initials()) ?></span>
                    <?php endif; ?>
                    <h4 class="mt-3 mb-1"><?= e(setting('shop_name', APP_NAME)) ?></h4>
                    <p class="text-muted small mb-0">Owner &amp; Staff Sign In</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?>
                    </div>
                <?php endif; ?>
                <?php foreach (flash_get() as $f): ?>
                    <div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="<?= e(base_url('login.php')) ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="identity">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="identity" name="identity" class="form-control" required autofocus value="<?= e(post('identity')) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" id="password" name="password" class="form-control" required>
                            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </form>

                <div class="text-center mt-4 text-muted small">
                    Forgot your password? Contact the business owner.
                </div>
            </div>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
