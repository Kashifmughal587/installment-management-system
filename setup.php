<?php
/**
 * One-time owner setup.
 *
 * - Imports sql/schema.sql if tables are missing.
 * - Creates the first `owner` account with a hashed password.
 * - Self-disables as soon as any user record exists.
 *
 * Delete this file from the server after setup is complete.
 */

require_once __DIR__ . '/includes/auth.php';

/* ---------------------------------------------------------------- */
/*  Import schema helper                                             */
/* ---------------------------------------------------------------- */
function setup_schema_exists(PDO $db): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = 'users'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function setup_import_schema(PDO $db): void
{
    $file = __DIR__ . '/sql/schema.sql';
    if (!is_file($file)) {
        throw new RuntimeException('sql/schema.sql not found.');
    }
    $sql = (string)file_get_contents($file);
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($statements as $stmt) {
        if ($stmt === '' || preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $stmt)) {
            continue;
        }
        $db->exec($stmt);
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/* ---------------------------------------------------------------- */
/*  State                                                           */
/* ---------------------------------------------------------------- */
$db = getDB();
$schemaExists = setup_schema_exists($db);
$importError = '';

if (!$schemaExists) {
    try {
        setup_import_schema($db);
        $schemaExists = setup_schema_exists($db);
        if (!$schemaExists) {
            $importError = 'Schema import finished but the `users` table is still missing.';
        }
    } catch (Throwable $ex) {
        $importError = 'Schema import failed: ' . $ex->getMessage();
        app_log('setup import failed: ' . $ex->getMessage());
    }
}

$userCount = 0;
if ($schemaExists) {
    $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

$errors = [];
$success = '';

/* ---------------------------------------------------------------- */
/*  Handle POST                                                     */
/* ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $schemaExists = setup_schema_exists($db);

    if ($schemaExists && $userCount > 0) {
        $errors[] = 'An owner account already exists. Setup is disabled.';
    } else {
        $fullName = post('full_name');
        $username = post('username');
        $email    = post('email');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if (mb_strlen($fullName) < 3) {
            $errors[] = 'Full name must be at least 3 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
            $errors[] = 'Username must be 3-60 characters (letters, numbers, dot, dash, underscore).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address or leave it blank.';
        }
        if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$username, $email !== '' ? $email : $username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'That username or email is already taken.';
            }
        }

        if (!$errors) {
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO users (full_name, username, email, password_hash, role, status, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, NULL)')
                   ->execute([$fullName, $username, $email !== '' ? $email : null,
                              password_hash($password, PASSWORD_DEFAULT), 'owner', 'active']);
                $ownerId = (int)$db->lastInsertId();
                audit_log($ownerId, 'setup_owner_created', 'user', $ownerId, ['username' => $username]);
                $db->commit();

                flash_set('success', 'Owner account created. You can now log in.');
                redirect('login.php');
            } catch (Throwable $ex) {
                $db->rollBack();
                $errors[] = 'Failed to create the owner account.';
                app_log('setup insert failed: ' . $ex->getMessage());
            }
        }
    }
    $userCount = $schemaExists ? (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
}

$disabled = ($userCount > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initial Setup - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?= e(asset('css/app.css')) ?>?v=1" rel="stylesheet">
</head>
<body class="login-page">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <span class="setup-mark"><i class="bi bi-shield-lock"></i></span>
                        <h4 class="mt-2 mb-1">Initial Setup</h4>
                        <p class="text-muted mb-0">Create the owner account and initialise the database.</p>
                    </div>

                    <?php if ($importError): ?>
                        <div class="alert alert-danger"><?= e($importError) ?></div>
                    <?php endif; ?>

                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger"><?= e($err) ?></div>
                    <?php endforeach; ?>

                    <?php if ($disabled): ?>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-check-circle"></i>
                            Setup is already complete - an owner account exists.
                            Please log in instead.
                        </div>
                        <a href="<?= e(base_url('login.php')) ?>" class="btn btn-primary w-100">Go to Login</a>
                    <?php else: ?>
                        <div class="alert alert-warning small">
                            The database schema was found. After creating this account,
                            <strong>delete <code>setup.php</code></strong> from the server.
                        </div>
                        <form method="POST" autocomplete="off">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control" required minlength="3" maxlength="150">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required minlength="3" maxlength="60"
                                       pattern="[A-Za-z0-9_.\-]{3,60}" title="Letters, numbers, dot, dash, underscore">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                                <input type="email" name="email" class="form-control" maxlength="190">
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label">Password</label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="setupPassword" class="form-control"
                                               required minlength="<?= (int)MIN_PASSWORD_LENGTH ?>">
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="setupPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" name="confirm_password" class="form-control"
                                           required minlength="<?= (int)MIN_PASSWORD_LENGTH ?>">
                                </div>
                            </div>
                            <div class="text-muted small mt-2">
                                Minimum <?= (int)MIN_PASSWORD_LENGTH ?> characters. Choose something strong - no default password is shipped.
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-3">Create Owner Account</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
