<?php
/**
 * Edit an agreement.
 *
 * If the agreement already has active payments, financial changes require the
 * owner's "amendment" workflow with a recorded reason, and the previous values
 * are preserved in agreement_amendments.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agreement.php';
require_permission('manage_agreements');

$db = getDB();
$id = (int)get('id', 0);
if ($id <= 0 && post('id')) {
    $id = (int)post('id');
}

function load_agreement(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM agreements WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$agreement = load_agreement($db, $id);
if (!$agreement) {
    http_response_code(404);
    flash_set('error', 'Agreement not found.');
    redirect('agreements/index.php');
}
if (in_array($agreement['status'], ['completed', 'cancelled'], true)) {
    flash_set('warning', 'Completed or cancelled agreements cannot be edited. Their history is preserved.');
    redirect('agreements/view.php?id=' . $id);
}

$customers = $db->query("SELECT id, customer_code, full_name, mobile FROM customers WHERE status <> 'archived' ORDER BY full_name ASC")->fetchAll();

$stmt = $db->prepare('SELECT * FROM agreement_products WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$products = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM guarantors WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$guarantors = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE agreement_id = ? AND status = 'active'");
$stmt->execute([$id]);
$activePayments = (int)$stmt->fetchColumn();

$errors = [];
$needsAmendment = $activePayments > 0;

/* Detect whether the stored schedule is custom (non-uniform). */
$stmt = $db->prepare('SELECT due_date, scheduled_amount FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
$stmt->execute([$id]);
$storedInst = $stmt->fetchAll();
$customSchedule = [];
$isCustom = false;
if ($storedInst) {
    $financed = round_money((float)$agreement['agreed_sale_price'] + (float)$agreement['additional_charges'] - (float)$agreement['discount'] - (float)$agreement['advance_payment']);
    $equal = build_equal_schedule($financed, (int)$agreement['installment_count'], (string)$agreement['first_due_date'], $agreement['frequency'], (int)$agreement['due_day']);
    if (count($equal) !== count($storedInst)) {
        $isCustom = true;
    } else {
        foreach ($storedInst as $i => $row) {
            if (abs((float)$row['scheduled_amount'] - (float)$equal[$i]['amount']) > 0.01) {
                $isCustom = true;
                break;
            }
        }
    }
    foreach ($storedInst as $row) {
        $customSchedule[] = ['due_date' => $row['due_date'], 'amount' => $row['scheduled_amount']];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $in = agreement_collect_post();
    $in['use_custom_schedule'] = $isCustom || (($_POST['use_custom_schedule'] ?? '0') === '1');
    $errors = agreement_validate($in, $db);

    $amendReason = post('amend_reason');
    if ($needsAmendment) {
        if (!can('amend_agreement')) {
            $errors[] = 'This agreement already has payments. Only an owner can amend it.';
        } elseif (mb_strlen($amendReason) < 5) {
            $errors[] = 'A reason (at least 5 characters) is required to amend an agreement with payments.';
        }
    }

    if (!$errors) {
        try {
            // Preserve old financial values for auditability.
            if ($needsAmendment) {
                $db->prepare('INSERT INTO agreement_amendments (agreement_id, user_id, reason, old_values, new_values) VALUES (?, ?, ?, ?, ?)')
                   ->execute([
                       $id,
                       current_user_id(),
                       $amendReason,
                       json_encode([
                           'agreed_sale_price' => $agreement['agreed_sale_price'],
                           'advance_payment'   => $agreement['advance_payment'],
                           'installment_count' => $agreement['installment_count'],
                           'frequency'         => $agreement['frequency'],
                           'first_due_date'    => $agreement['first_due_date'],
                           'remaining_balance' => $agreement['remaining_balance'],
                       ], JSON_UNESCAPED_UNICODE),
                       json_encode([
                           'agreed_sale_price' => $in['agreed_sale_price'],
                           'advance_payment'   => $in['advance_payment'],
                           'installment_count' => $in['installment_count'],
                           'frequency'         => $in['frequency'],
                           'first_due_date'    => $in['first_due_date'],
                       ], JSON_UNESCAPED_UNICODE),
                   ]);
            }

            agreement_save($db, $in, $id, current_user_id());
            audit_log(current_user_id(), $needsAmendment ? 'agreement_amended' : 'agreement_updated', 'agreement', $id, ['reason' => $amendReason ?: null]);
            flash_set('success', 'Agreement updated successfully.');
            redirect('agreements/view.php?id=' . $id);
        } catch (Throwable $ex) {
            $errors[] = 'Could not update the agreement.';
            app_log('agreement update failed: ' . $ex->getMessage());
        }
    }

    $agreement = array_merge($agreement, $in);
    $products = $in['products'];
    $guarantors = $in['guarantors'];
    $customSchedule = $in['custom_schedule'];
    if ($in['use_custom_schedule']) {
        $isCustom = true;
    }
}

$agreement['use_custom_schedule'] = $isCustom;

$formAction = base_url('agreements/edit.php?id=' . $id);
$mode = 'edit';
$pageTitle = 'Edit Agreement';
$pageScripts = '<script src="' . e(asset('js/agreement.js')) . '?v=2"></script>';
include __DIR__ . '/../includes/header.php';
?>
<div class="mb-3">
    <a href="<?= e(base_url('agreements/view.php?id=' . $id)) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to agreement</a>
    <h5 class="mt-2 mb-0">Edit Agreement - <?= e($agreement['agreement_no']) ?></h5>
</div>

<?php if ($needsAmendment): ?>
    <div class="alert alert-warning">
        <h6 class="mb-1"><i class="bi bi-exclamation-triangle me-1"></i> This agreement already has recorded payments (<?= (int)$activePayments ?>).</h6>
        Changing financial terms requires an <strong>amendment</strong>. The previous values will be archived and the installment
        schedule will be rebuilt. Only an owner can amend.
        <div class="mt-2 mb-0">
            <label class="form-label mb-1">Reason for amendment <span class="text-danger">*</span></label>
            <input type="text" name="amend_reason" form="agreementForm" class="form-control" maxlength="500"
                   value="<?= e(post('amend_reason')) ?>" placeholder="e.g. Price corrected after discussion with customer">
        </div>
    </div>
<?php endif; ?>

<?php
include __DIR__ . '/_form.php';
include __DIR__ . '/../includes/footer.php';
?>
