<?php
/**
 * User management (owner only).
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('manage_users');

$db = getDB();

$editId = (int)get('edit', 0);
$errors = [];
$form = [
    'id' => 0, 'full_name' => '', 'username' => '', 'email' => '',
    'role' => 'staff', 'status' => 'active',
];

if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $u = $stmt->fetch();
    if ($u) {
        $form = [
            'id' => (int)$u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'],
            'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status'],
        ];
    }
}

function active_owner_count(PDO $db, int $excludeId = 0): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'";
    if ($excludeId) {
        $sql .= ' AND id <> ' . $excludeId;
    }
    return (int)$db->query($sql)->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = post('action');

    if ($action === 'save') {
        $form = [
            'id'        => post_int('id'),
            'full_name' => post('full_name'),
            'username'  => post('username'),
            'email'     => post('email'),
            'role'      => post('role', 'staff'),
            'status'    => post('status', 'active'),
        ];
        $password = (string)post('password');

        if ($form['full_name'] === '') {
            $errors['full_name'] = 'Full name is required.';
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $form['username'])) {
            $errors['username'] = 'Username must be 3-60 chars (letters, numbers, . _ -).';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if (!in_array($form['role'], ['owner', 'staff'], true)) {
            $form['role'] = 'staff';
        }
        if (!in_array($form['status'], ['active', 'inactive'], true)) {
            $form['status'] = 'active';
        }
        if ($form['id'] === 0) {
            if (strlen($password) < MIN_PASSWORD_LENGTH) {
                $errors['password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
            }
        } elseif ($password !== '' && strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        }

        // Uniqueness.
        if (!isset($errors['username'])) {
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$form['username'], $form['id']]);
            if ($stmt->fetch()) {
                $errors['username'] = 'That username is already taken.';
            }
        }
        if ($form['email'] !== '' && !isset($errors['email'])) {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $stmt->execute([$form['email'], $form['id']]);
            if ($stmt->fetch()) {
                $errors['email'] = 'That email is already registered.';
            }
        }

        // Never allow removing the last active owner.
        $isDemotingOwner = ($form['id'] > 0 && ($form['role'] !== 'owner' || $form['status'] !== 'active'));
        if ($isDemotingOwner) {
            $stmt = $db->prepare('SELECT role, status FROM users WHERE id = ?');
            $stmt->execute([$form['id']]);
            $existing = $stmt->fetch();
            if ($existing && $existing['role'] === 'owner' && $existing['status'] === 'active' && active_owner_count($db, $form['id']) === 0) {
                $errors['role'] = 'At least one active owner must remain.';
            }
        }
        if ($form['id'] === current_user_id() && $form['status'] !== 'active') {
            $errors['status'] = 'You cannot deactivate your own account.';
        }

        if (empty($errors)) {
            if ($form['id'] > 0) {
                $sql = 'UPDATE users SET full_name = :fn, username = :un, email = :em, role = :role, status = :status';
                $bind = [
                    ':fn' => $form['full_name'], ':un' => $form['username'],
                    ':em' => $form['email'] ?: null, ':role' => $form['role'],
                    ':status' => $form['status'], ':id' => $form['id'],
                ];
                if ($password !== '') {
                    $sql .= ', password_hash = :ph';
                    $bind[':ph'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id = :id';
                $db->prepare($sql)->execute($bind);
                audit_log(current_user_id(), 'update_user', 'user', $form['id'], ['username' => $form['username']]);
                flash_set('success', 'User updated.');
            } else {
                $db->prepare(
                    'INSERT INTO users (full_name, username, email, password_hash, role, status, created_by)
                     VALUES (:fn, :un, :em, :ph, :role, :status, :by)'
                )->execute([
                    ':fn' => $form['full_name'], ':un' => $form['username'],
                    ':em' => $form['email'] ?: null,
                    ':ph' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => $form['role'], ':status' => $form['status'],
                    ':by' => current_user_id(),
                ]);
                audit_log(current_user_id(), 'create_user', 'user', (int)$db->lastInsertId(), ['username' => $form['username']]);
                flash_set('success', 'User created.');
            }
            redirect('settings/users.php');
        }
    } elseif ($action === 'toggle') {
        $targetId = post_int('id');
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            flash_set('error', 'User not found.');
        } elseif ($targetId === current_user_id()) {
            flash_set('error', 'You cannot change your own status.');
        } elseif ($target['role'] === 'owner' && $target['status'] === 'active' && active_owner_count($db, $targetId) === 0) {
            flash_set('error', 'At least one active owner must remain.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $targetId]);
            audit_log(current_user_id(), 'toggle_user_status', 'user', $targetId, ['status' => $newStatus]);
            flash_set('success', 'User status updated.');
        }
        redirect('settings/users.php');
    }
}

$users = $db->query('SELECT * FROM users ORDER BY role ASC, full_name ASC')->fetchAll();

$pageTitle = 'User Management';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="bi bi-person-plus me-1"></i> <?= $form['id'] ? 'Edit User' : 'Add User' ?>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2 small">Please fix the highlighted fields.</div>
                <?php endif; ?>
                <form method="POST" action="<?= e(base_url('settings/users.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                    <div class="mb-2">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" value="<?= e($form['full_name']) ?>" maxlength="150" required>
                        <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" value="<?= e($form['username']) ?>" maxlength="60" required>
                        <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= e($errors['username']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($form['email']) ?>" maxlength="190">
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Password <?= $form['id'] ? '<span class="text-muted small">(leave blank to keep)</span>' : '<span class="text-danger">*</span>' ?></label>
                        <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" autocomplete="new-password">
                        <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= e($errors['password']) ?></div><?php else: ?><div class="form-text">Min <?= (int)MIN_PASSWORD_LENGTH ?> characters.</div><?php endif; ?>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select <?= isset($errors['role']) ? 'is-invalid' : '' ?>">
                                <option value="staff" <?= $form['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                <option value="owner" <?= $form['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
                            </select>
                            <?php if (isset($errors['role'])): ?><div class="invalid-feedback"><?= e($errors['role']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>">
                                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= e($errors['status']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> <?= $form['id'] ? 'Update' : 'Create' ?></button>
                        <?php if ($form['id']): ?>
                            <a href="<?= e(base_url('settings/users.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-people me-1"></i> Users</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last Login</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <?= e($u['full_name']) ?>
                                    <?php if ((int)$u['id'] === current_user_id()): ?><span class="badge text-bg-info">You</span><?php endif; ?>
                                    <?php if ($u['email']): ?><div class="small text-muted"><?= e($u['email']) ?></div><?php endif; ?>
                                </td>
                                <td><?= e($u['username']) ?></td>
                                <td><span class="badge text-bg-<?= $u['role'] === 'owner' ? 'dark' : 'secondary' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?><span class="badge text-bg-success">Active</span>
                                    <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= e(fmt_datetime($u['last_login_at']) ?: '-') ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary py-0" href="<?= e(base_url('settings/users.php?edit=' . (int)$u['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int)$u['id'] !== current_user_id()): ?>
                                        <form method="POST" class="d-inline" data-confirm="Change this user's status?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'danger' : 'success' ?> py-0" title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $u['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
