<?php
/**
 * Add a new customer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('manage_customers');

$db = getDB();
$errors = [];
$duplicateWarning = [];
$confirmDuplicate = false;

$defaults = [
    'full_name' => '', 'guardian_name' => '', 'guardian_relation' => '', 'cnic' => '',
    'dob' => '', 'gender' => '', 'occupation' => '', 'monthly_income' => '',
    'mobile' => '', 'whatsapp' => '', 'email' => '',
    'current_address' => '', 'permanent_address' => '', 'city' => '', 'district' => '',
    'notes' => '', 'status' => 'active', 'photo_path' => '', 'cnic_front_path' => '', 'cnic_back_path' => '',
];
$customer = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    foreach ($defaults as $k => $_) {
        $customer[$k] = post($k, '');
    }
    $confirmDuplicate = (post('confirm_duplicate') === '1');

    if (mb_strlen(trim($customer['full_name'])) < 2) {
        $errors[] = 'Customer full name is required.';
    }
    $cnicDigits = preg_replace('/\D/', '', $customer['cnic']);
    if ($customer['cnic'] === '') {
        $errors[] = 'CNIC number is required.';
    } elseif (strlen($cnicDigits) !== 13) {
        $errors[] = 'CNIC number must be exactly 13 digits.';
    } else {
        $customer['cnic'] = substr($cnicDigits, 0, 5) . '-' . substr($cnicDigits, 5, 7) . '-' . substr($cnicDigits, 12, 1);
    }
    if ($customer['mobile'] === '') {
        $errors[] = 'Primary mobile number is required.';
    }
    if (post('whatsapp_same') === '1') {
        $customer['whatsapp'] = $customer['mobile'];
    }
    if (post('permanent_same') === '1') {
        $customer['permanent_address'] = $customer['current_address'];
    }
    if ($customer['email'] !== '' && !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($customer['monthly_income'] !== '' && !is_numeric($customer['monthly_income'])) {
        $errors[] = 'Monthly income must be a number.';
    }

    // Duplicate detection (CNIC or mobile).
    if (!$errors) {
        $dupSql = 'SELECT id, customer_code, full_name, mobile, cnic FROM customers WHERE 1=1 AND (';
        $dupParams = [];
        $parts = [];
        if ($customer['cnic'] !== '') {
            $parts[] = 'cnic = :cnic';
            $dupParams[':cnic'] = $customer['cnic'];
        }
        if ($customer['mobile'] !== '') {
            $parts[] = 'mobile = :mobile';
            $dupParams[':mobile'] = $customer['mobile'];
        }
        $dupSql .= implode(' OR ', $parts) . ') LIMIT 5';
        $dupStmt = $db->prepare($dupSql);
        $dupStmt->execute($dupParams);
        $duplicateWarning = $dupStmt->fetchAll();
        if ($duplicateWarning && !$confirmDuplicate) {
            $errors[] = 'A customer with the same CNIC or mobile number may already exist. Please review below and confirm if you still want to save.';
        }
    }

    // Uploads
    $uploads = [];
    if (!$errors) {
        foreach (['photo' => 'photo_path', 'cnic_front' => 'cnic_front_path', 'cnic_back' => 'cnic_back_path'] as $field => $col) {
            $res = handle_upload($field, 'customers');
            if (!$res['ok']) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $res['error'];
                break;
            }
            $uploads[$col] = $res['path'];
        }
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $code = next_customer_code($db);
            $stmt = $db->prepare(
                'INSERT INTO customers
                 (customer_code, full_name, guardian_name, guardian_relation, cnic, dob, gender, occupation,
                  monthly_income, photo_path, mobile, whatsapp, email, current_address, permanent_address,
                  city, district, cnic_front_path, cnic_back_path, notes, status, created_by, updated_by)
                 VALUES
                 (:code, :full_name, :guardian_name, :guardian_relation, :cnic, :dob, :gender, :occupation,
                  :monthly_income, :photo_path, :mobile, :whatsapp, :email, :current_address, :permanent_address,
                  :city, :district, :cnic_front_path, :cnic_back_path, :notes, :status, :created_by, :updated_by)'
            );
            $stmt->execute([
                ':code' => $code,
                ':full_name' => $customer['full_name'],
                ':guardian_name' => $customer['guardian_name'] ?: null,
                ':guardian_relation' => $customer['guardian_relation'] ?: null,
                ':cnic' => $customer['cnic'] ?: null,
                ':dob' => $customer['dob'] ?: null,
                ':gender' => $customer['gender'] ?: null,
                ':occupation' => $customer['occupation'] ?: null,
                ':monthly_income' => $customer['monthly_income'] !== '' ? round_money($customer['monthly_income']) : null,
                ':photo_path' => $uploads['photo_path'] ?? null,
                ':mobile' => $customer['mobile'] ?: null,
                ':whatsapp' => $customer['whatsapp'] ?: null,
                ':email' => $customer['email'] ?: null,
                ':current_address' => $customer['current_address'] ?: null,
                ':permanent_address' => $customer['permanent_address'] ?: null,
                ':city' => $customer['city'] ?: null,
                ':district' => $customer['district'] ?: null,
                ':cnic_front_path' => $uploads['cnic_front_path'] ?? null,
                ':cnic_back_path' => $uploads['cnic_back_path'] ?? null,
                ':notes' => $customer['notes'] ?: null,
                ':status' => in_array($customer['status'], ['active', 'inactive', 'archived'], true) ? $customer['status'] : 'active',
                ':created_by' => current_user_id(),
                ':updated_by' => current_user_id(),
            ]);
            $newId = (int)$db->lastInsertId();
            audit_log(current_user_id(), 'customer_created', 'customer', $newId, ['code' => $code, 'name' => $customer['full_name']]);
            $db->commit();

            flash_set('success', 'Customer ' . $code . ' created successfully.');
            redirect('customers/view.php?id=' . $newId);
        } catch (Throwable $ex) {
            $db->rollBack();
            foreach ($uploads as $p) {
                delete_upload($p);
            }
            $errors[] = 'Could not save the customer. Please try again.';
            app_log('customer insert failed: ' . $ex->getMessage());
        }
    }
}

$formAction = base_url('customers/add.php');
$mode = 'add';
$pageTitle = 'Add Customer';
include __DIR__ . '/../includes/header.php';
?>
<div class="mb-3">
    <a href="<?= e(base_url('customers/index.php')) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to customers</a>
    <h5 class="mt-2 mb-0">Add New Customer</h5>
</div>

<?php if ($duplicateWarning): ?>
    <div class="alert alert-warning duplicate-warning">
        <h6 class="mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Possible duplicate customer(s)</h6>
        <ul class="mb-2">
            <?php foreach ($duplicateWarning as $d): ?>
                <li>
                    <a href="<?= e(base_url('customers/view.php?id=' . (int)$d['id'])) ?>" target="_blank">
                        <?= e($d['customer_code']) ?> - <?= e($d['full_name']) ?>
                    </a>
                    (Mobile: <?= e($d['mobile'] ?: '-') ?>, CNIC: <?= e($d['cnic'] ?: '-') ?>)
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="POST" action="<?= e($formAction) ?>" class="d-inline">
            <?= csrf_field() ?>
            <?php foreach ($customer as $k => $val): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($val) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="mode" value="add">
            <input type="hidden" name="confirm_duplicate" value="1">
            <button type="submit" class="btn btn-sm btn-warning" data-confirm="Save anyway despite the possible duplicate?">Save anyway (it is a different person)</button>
        </form>
    </div>
<?php endif; ?>

<?php
$confirmDuplicate = $confirmDuplicate;
include __DIR__ . '/_form.php';
include __DIR__ . '/../includes/footer.php';
?>
