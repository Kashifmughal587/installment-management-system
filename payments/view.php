<?php
/**
 * Payment detail: allocations, audit info, void action.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment.php';
require_permission('view_payments');

$db = getDB();
$id = (int)get('id', 0);

$stmt = $db->prepare(
    'SELECT p.*, c.full_name AS customer_name, c.customer_code, c.mobile,
            g.agreement_no, g.id AS ag_id,
            u.full_name AS received_by_name, vu.full_name AS void_by_name
     FROM payments p
     JOIN customers c ON c.id = p.customer_id
     JOIN agreements g ON g.id = p.agreement_id
     LEFT JOIN users u ON u.id = p.received_by
     LEFT JOIN users vu ON vu.id = p.void_by
     WHERE p.id = ?'
);
$stmt->execute([$id]);
$payment = $stmt->fetch();
if (!$payment) {
    http_response_code(404);
    flash_set('error', 'Payment not found.');
    redirect('payments/index.php');
}

$allocStmt = $db->prepare(
    'SELECT a.amount, i.installment_no, i.due_date, i.scheduled_amount
     FROM payment_allocations a
     LEFT JOIN installments i ON i.id = a.installment_id
     WHERE a.payment_id = ?
     ORDER BY i.installment_no ASC'
);
$allocStmt->execute([$id]);
$allocations = $allocStmt->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'void') {
    csrf_require();
    require_permission('void_payment');
    $reason = post('void_reason');
    if (mb_strlen($reason) < 5) {
        $errors['void_reason'] = 'Please provide a reason (at least 5 characters).';
    } else {
        $res = payment_void($db, $id, current_user_id(), $reason);
        if ($res['ok']) {
            audit_log(current_user_id(), 'void_payment', 'payment', $id, ['reason' => $reason]);
            flash_set('success', 'Payment voided and balances reversed.');
            redirect('payments/view.php?id=' . $id);
        }
        $errors['void_reason'] = $res['error'];
    }
}

$pageTitle = 'Payment ' . $payment['receipt_no'];
$pageActions = '<a href="' . e(base_url('payments/receipt.php?id=' . $id)) . '" target="_blank" class="btn btn-outline-dark btn-sm me-1"><i class="bi bi-receipt me-1"></i> Receipt</a>'
    . '<a href="' . e(base_url('payments/index.php')) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Payment <?= e($payment['receipt_no']) ?></h6>
                <?php if ($payment['status'] === 'void'): ?>
                    <span class="badge text-bg-danger">Void</span>
                <?php else: ?>
                    <span class="badge text-bg-success">Active</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Customer</div>
                        <a href="<?= e(base_url('customers/view.php?id=' . (int)$payment['customer_id'])) ?>"><?= e($payment['customer_name']) ?></a>
                        <div class="small text-muted"><?= e($payment['customer_code']) ?><?= $payment['mobile'] ? ' · ' . e($payment['mobile']) : '' ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Agreement</div>
                        <a href="<?= e(base_url('agreements/view.php?id=' . (int)$payment['ag_id'])) ?>"><?= e($payment['agreement_no']) ?></a>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Date</div>
                        <div><?= e(fmt_date($payment['payment_date'])) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Amount</div>
                        <div class="fw-bold <?= $payment['status'] === 'void' ? 'text-muted text-decoration-line-through' : '' ?>"><?= e(money_pkr($payment['amount'])) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Method</div>
                        <div><?= e(payment_method_label($payment['method'])) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Type</div>
                        <div><?= e(payment_type_label($payment['payment_type'])) ?></div>
                    </div>
                    <?php if ($payment['transaction_ref']): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Reference</div>
                        <div><?= e($payment['transaction_ref']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-3">
                        <div class="text-muted small">Balance Before</div>
                        <div><?= e(money($payment['prev_balance'])) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Balance After</div>
                        <div><?= e(money($payment['new_balance'])) ?></div>
                    </div>
                    <?php if ($payment['notes']): ?>
                    <div class="col-12">
                        <div class="text-muted small">Notes</div>
                        <div><?= nl2br(e($payment['notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($payment['attachment_path']): ?>
                    <div class="col-12">
                        <div class="text-muted small">Attachment</div>
                        <a href="<?= e(upload_url($payment['attachment_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip me-1"></i> View attachment</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">Installment Allocation</h6></div>
            <div class="card-body">
                <?php if (empty($allocations)): ?>
                    <p class="text-muted mb-0">This payment was not allocated to any installment (advance/other).</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light"><tr><th>Installment</th><th>Due Date</th><th class="text-end">Scheduled</th><th class="text-end">Applied</th></tr></thead>
                            <tbody>
                            <?php foreach ($allocations as $a): ?>
                                <tr>
                                    <td>#<?= (int)$a['installment_no'] ?></td>
                                    <td><?= e(fmt_date($a['due_date'])) ?></td>
                                    <td class="text-end"><?= e(money($a['scheduled_amount'])) ?></td>
                                    <td class="text-end text-success"><?= e(money($a['amount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h6 class="mb-0">Record Info</h6></div>
            <div class="card-body small">
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Received by</span><span><?= e($payment['received_by_name'] ?: '-') ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Created</span><span><?= e(fmt_datetime($payment['created_at'])) ?></span></div>
            </div>
        </div>

        <?php if ($payment['status'] === 'void'): ?>
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger-subtle"><h6 class="mb-0 text-danger">Voided</h6></div>
                <div class="card-body small">
                    <div class="mb-1"><span class="text-muted">Reason:</span> <?= e($payment['void_reason']) ?></div>
                    <div class="mb-1"><span class="text-muted">By:</span> <?= e($payment['void_by_name'] ?: '-') ?></div>
                    <div><span class="text-muted">At:</span> <?= e(fmt_datetime($payment['void_at'])) ?></div>
                </div>
            </div>
        <?php elseif (can('void_payment')): ?>
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger-subtle"><h6 class="mb-0 text-danger">Void Payment</h6></div>
                <div class="card-body">
                    <p class="small text-muted">Voiding reverses all installment allocations and restores the balance. This action is logged and cannot be undone.</p>
                    <form method="POST" action="<?= e(base_url('payments/view.php?id=' . $id)) ?>" data-confirm="Void this payment? The balance will be reversed.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="void">
                        <div class="mb-2">
                            <textarea name="void_reason" class="form-control <?= isset($errors['void_reason']) ? 'is-invalid' : '' ?>" rows="2" placeholder="Reason for voiding (required)" required></textarea>
                            <?php if (isset($errors['void_reason'])): ?><div class="invalid-feedback"><?= e($errors['void_reason']) ?></div><?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm w-100"><i class="bi bi-x-circle me-1"></i> Void Payment</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
