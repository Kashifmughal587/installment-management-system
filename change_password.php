<?php
require_once __DIR__ . '/includes/auth.php';
require_login(); // any authenticated user may change their own password

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $db = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([current_user_id()]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($current, $hash)) {
        $errors[] = 'Current password is incorrect.';
    }
    if (mb_strlen($new) < MIN_PASSWORD_LENGTH) {
        $errors[] = 'New password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
    } elseif ($new === $current) {
        $errors[] = 'New password must be different from the current password.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (!$errors) {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
        audit_log(current_user_id(), 'password_changed', 'user', current_user_id());
        // Invalidate other sessions by rotating the session identifier now.
        session_regenerate_id(true);
        flash_set('success', 'Password changed successfully.');
        $success = 'Password changed successfully.';
    }
}

$pageTitle = 'Change Password';
include __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-key me-1"></i> Change Password</h5>
            </div>
            <div class="card-body p-4">
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= e($success) ?></div>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="<?= e(base_url('change_password.php')) ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <button class="btn btn-outline-secondary" type="button" data-toggle-password="current_password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?= (int)MIN_PASSWORD_LENGTH ?>">
                            <button class="btn btn-outline-secondary" type="button" data-toggle-password="new_password"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text">Minimum <?= (int)MIN_PASSWORD_LENGTH ?> characters.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="<?= (int)MIN_PASSWORD_LENGTH ?>">
                            <button class="btn btn-outline-secondary" type="button" data-toggle-password="confirm_password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Update Password</button>
                        <a href="<?= e(base_url('dashboard.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
