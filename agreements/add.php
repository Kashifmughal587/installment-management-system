<?php
/**
 * Create a new installment agreement.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agreement.php';
require_permission('manage_agreements');

$db = getDB();

$customers = $db->query("SELECT id, customer_code, full_name, mobile FROM customers WHERE status <> 'archived' ORDER BY full_name ASC")->fetchAll();

$errors = [];
$products = [];
$guarantors = [];

$agreement = [
    'customer_id'         => (int)get('customer_id', 0),
    'agreement_date'      => date('Y-m-d'),
    'status'              => 'draft',
    'agreed_sale_price'   => '',
    'additional_charges'  => '0.00',
    'discount'            => '0.00',
    'advance_payment'     => '0.00',
    'installment_count'   => 12,
    'frequency'           => 'monthly',
    'first_due_date'      => '',
    'due_day'             => (int)setting('default_due_day', 5),
    'payment_method_pref' => '',
    'terms'               => setting('default_terms', ''),
    'notes'               => '',
    'use_custom_schedule' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $in = agreement_collect_post();
    $errors = agreement_validate($in, $db);

    if (!$errors) {
        try {
            $newId = agreement_save($db, $in, null, current_user_id());
            audit_log(current_user_id(), 'agreement_created', 'agreement', $newId, ['status' => $in['status']]);
            flash_set('success', 'Agreement created successfully.');
            if ($in['status'] === 'active') {
                redirect('agreements/view.php?id=' . $newId . '&created=1');
            }
            redirect('agreements/view.php?id=' . $newId);
        } catch (Throwable $ex) {
            $errors[] = 'Could not save the agreement. Please try again.';
            app_log('agreement create failed: ' . $ex->getMessage());
        }
    }

    // Re-render with posted values.
    $agreement = array_merge($agreement, $in);
    $products = $in['products'];
    $guarantors = $in['guarantors'];
    if (!$products) {
        $products = [['product_name' => '', 'category' => '', 'brand' => '', 'model' => '', 'serial_no' => '', 'quantity' => 1, 'unit_cash_price' => '', 'notes' => '']];
    }
}

$formAction = base_url('agreements/add.php');
$mode = 'add';
$customSchedule = $in['custom_schedule'] ?? [];
$pageTitle = 'Create Agreement';
$pageScripts = '<script src="' . e(asset('js/agreement.js')) . '?v=2"></script>';
include __DIR__ . '/../includes/header.php';
?>
<div class="mb-3">
    <a href="<?= e(base_url('agreements/index.php')) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to agreements</a>
    <h5 class="mt-2 mb-0">Create New Agreement</h5>
</div>
<?php
include __DIR__ . '/_form.php';
include __DIR__ . '/../includes/footer.php';
?>
