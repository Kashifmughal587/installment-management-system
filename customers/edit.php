<?php
/**
 * Edit an existing customer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('manage_customers');

$db = getDB();
$id = (int)get('id', 0);
if ($id <= 0 && post('id')) {
    $id = (int)post('id');
}

$stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) {
    http_response_code(404);
    flash_set('error', 'Customer not found.');
    redirect('customers/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $fields = ['full_name', 'guardian_name', 'guardian_relation', 'cnic', 'dob', 'gender',
        'occupation', 'monthly_income', 'mobile', 'whatsapp', 'email', 'current_address',
        'permanent_address', 'city', 'district', 'notes', 'status'];
    $posted = [];
    foreach ($fields as $f) {
        $posted[$f] = post($f, '');
    }

    if (mb_strlen(trim($posted['full_name'])) < 2) {
        $errors[] = 'Customer full name is required.';
    }
    $cnicDigits = preg_replace('/\D/', '', $posted['cnic']);
    if ($posted['cnic'] === '') {
        $errors[] = 'CNIC number is required.';
    } elseif (strlen($cnicDigits) !== 13) {
        $errors[] = 'CNIC number must be exactly 13 digits.';
    } else {
        $posted['cnic'] = substr($cnicDigits, 0, 5) . '-' . substr($cnicDigits, 5, 7) . '-' . substr($cnicDigits, 12, 1);
    }
    if ($posted['mobile'] === '') {
        $errors[] = 'Primary mobile number is required.';
    }
    if (post('whatsapp_same') === '1') {
        $posted['whatsapp'] = $posted['mobile'];
    }
    if (post('permanent_same') === '1') {
        $posted['permanent_address'] = $posted['current_address'];
    }
    if ($posted['email'] !== '' && !filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($posted['monthly_income'] !== '' && !is_numeric($posted['monthly_income'])) {
        $errors[] = 'Monthly income must be a number.';
    }

    // Uploads (photo / cnic front / back) with optional removal.
    $newPaths = [];
    if (!$errors) {
        $map = [
            'photo'      => ['photo_path', 'remove_photo'],
            'cnic_front' => ['cnic_front_path', 'remove_cnic_front'],
            'cnic_back'  => ['cnic_back_path', 'remove_cnic_back'],
        ];
        foreach ($map as $field => [$col, $removeField]) {
            $res = handle_upload($field, 'customers', [
                'path'   => $customer[$col] ?? null,
                'remove' => (post($removeField) === '1'),
            ]);
            if (!$res['ok']) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $res['error'];
                break;
            }
            $newPaths[$col] = $res['path'];
        }
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE customers SET
                    full_name = :full_name, guardian_name = :guardian_name, guardian_relation = :guardian_relation,
                    cnic = :cnic, dob = :dob, gender = :gender, occupation = :occupation,
                    monthly_income = :monthly_income, mobile = :mobile, whatsapp = :whatsapp, email = :email,
                    current_address = :current_address, permanent_address = :permanent_address,
                    city = :city, district = :district, notes = :notes, status = :status,
                    photo_path = :photo_path, cnic_front_path = :cnic_front_path, cnic_back_path = :cnic_back_path,
                    updated_by = :updated_by
                 WHERE id = :id'
            );
            $stmt->execute([
                ':full_name' => $posted['full_name'],
                ':guardian_name' => $posted['guardian_name'] ?: null,
                ':guardian_relation' => $posted['guardian_relation'] ?: null,
                ':cnic' => $posted['cnic'] ?: null,
                ':dob' => $posted['dob'] ?: null,
                ':gender' => $posted['gender'] ?: null,
                ':occupation' => $posted['occupation'] ?: null,
                ':monthly_income' => $posted['monthly_income'] !== '' ? round_money($posted['monthly_income']) : null,
                ':mobile' => $posted['mobile'] ?: null,
                ':whatsapp' => $posted['whatsapp'] ?: null,
                ':email' => $posted['email'] ?: null,
                ':current_address' => $posted['current_address'] ?: null,
                ':permanent_address' => $posted['permanent_address'] ?: null,
                ':city' => $posted['city'] ?: null,
                ':district' => $posted['district'] ?: null,
                ':notes' => $posted['notes'] ?: null,
                ':status' => in_array($posted['status'], ['active', 'inactive', 'archived'], true) ? $posted['status'] : 'active',
                ':photo_path' => $newPaths['photo_path'] ?? null,
                ':cnic_front_path' => $newPaths['cnic_front_path'] ?? null,
                ':cnic_back_path' => $newPaths['cnic_back_path'] ?? null,
                ':updated_by' => current_user_id(),
                ':id' => $id,
            ]);
            audit_log(current_user_id(), 'customer_updated', 'customer', $id, ['code' => $customer['customer_code']]);
            $db->commit();

            flash_set('success', 'Customer updated successfully.');
            redirect('customers/view.php?id=' . $id);
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Could not update the customer.';
            app_log('customer update failed: ' . $ex->getMessage());
        }
    }

    // Merge posted values back for re-render.
    $customer = array_merge($customer, $posted);
}

$formAction = base_url('customers/edit.php?id=' . $id);
$mode = 'edit';
$pageTitle = 'Edit Customer';
$confirmDuplicate = false;
include __DIR__ . '/../includes/header.php';
?>
<div class="mb-3">
    <a href="<?= e(base_url('customers/view.php?id=' . $id)) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to customer</a>
    <h5 class="mt-2 mb-0">Edit Customer - <?= e($customer['customer_code']) ?></h5>
</div>
<?php
include __DIR__ . '/_form.php';
include __DIR__ . '/../includes/footer.php';
?>
