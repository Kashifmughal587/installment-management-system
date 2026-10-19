<?php
/**
 * Agreement detail view with workflow actions.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agreement.php';
require_permission('view_agreements');

$db = getDB();
$id = (int)get('id', 0);
if ($id <= 0 && post('id')) {
    $id = (int)post('id');
}

$stmt = $db->prepare('SELECT g.*, c.full_name AS customer_name, c.customer_code, c.mobile, c.cnic,
                             c.guardian_name, c.guardian_relation, c.current_address, c.city, c.district,
                             u.full_name AS created_by_name
                      FROM agreements g
                      JOIN customers c ON c.id = g.customer_id
                      LEFT JOIN users u ON u.id = g.created_by
                      WHERE g.id = ?');
$stmt->execute([$id]);
$agreement = $stmt->fetch();
if (!$agreement) {
    http_response_code(404);
    flash_set('error', 'Agreement not found.');
    redirect('agreements/index.php');
}

/* ---------------- Workflow actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') !== '') {
    csrf_require();
    $action = post('action');
    require_permission('manage_agreements');

    if ($action === 'activate') {
        if ($agreement['status'] !== 'draft') {
            flash_set('error', 'Only draft agreements can be activated.');
        } else {
            $db->prepare("UPDATE agreements SET status = 'active', activated_at = NOW(), updated_by = ? WHERE id = ?")
               ->execute([current_user_id(), $id]);
            audit_log(current_user_id(), 'agreement_activated', 'agreement', $id);
            flash_set('success', 'Agreement activated.');
        }
    } elseif ($action === 'complete') {
        if ((float)$agreement['remaining_balance'] > 0.009) {
            flash_set('error', 'The agreement still has an outstanding balance.');
        } else {
            $db->prepare("UPDATE agreements SET status = 'completed', completed_at = NOW(), updated_by = ? WHERE id = ?")
               ->execute([current_user_id(), $id]);
            audit_log(current_user_id(), 'agreement_completed', 'agreement', $id);
            flash_set('success', 'Agreement marked as completed.');
        }
    } elseif ($action === 'cancel') {
        if (!can('cancel_agreement')) {
            flash_set('error', 'Only an owner can cancel agreements.');
        } else {
            $reason = post('cancel_reason');
            if (mb_strlen($reason) < 5) {
                flash_set('error', 'Please provide a cancellation reason (at least 5 characters).');
            } else {
                $db->prepare("UPDATE agreements SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ?, updated_by = ? WHERE id = ?")
                   ->execute([$reason, current_user_id(), current_user_id(), $id]);
                audit_log(current_user_id(), 'agreement_cancelled', 'agreement', $id, ['reason' => $reason]);
                flash_set('success', 'Agreement cancelled. Payment history was preserved.');
            }
        }
    } elseif ($action === 'reopen') {
        if (!can('cancel_agreement')) {
            flash_set('error', 'Only an owner can reopen agreements.');
        } else {
            $db->prepare("UPDATE agreements SET status = 'active', cancel_reason = NULL, cancelled_at = NULL, cancelled_by = NULL, updated_by = ? WHERE id = ?")
               ->execute([current_user_id(), $id]);
            audit_log(current_user_id(), 'agreement_reopened', 'agreement', $id);
            flash_set('success', 'Agreement reopened as active.');
        }
    }
    redirect('agreements/view.php?id=' . $id);
}

/* ---------------- Data ---------------- */
$stmt = $db->prepare('SELECT * FROM agreement_products WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$products = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM guarantors WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$guarantors = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
$stmt->execute([$id]);
$installments = $stmt->fetchAll();

$stmt = $db->prepare('SELECT p.*, u.full_name AS received_by_name FROM payments p
                      LEFT JOIN users u ON u.id = p.received_by
                      WHERE p.agreement_id = ? ORDER BY p.payment_date ASC, p.id ASC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$stmt = $db->prepare('SELECT a.*, u.full_name AS user_name FROM agreement_amendments a
                      LEFT JOIN users u ON u.id = a.user_id
                      WHERE a.agreement_id = ? ORDER BY a.id DESC');
$stmt->execute([$id]);
$amendments = $stmt->fetchAll();

$allocated = agreement_paid_total($db, $id);
$advancePaid = 0.0;
foreach ($payments as $p) {
    if ($p['status'] === 'active' && $p['payment_type'] === 'advance') {
        $advancePaid += (float)$p['amount'];
    }
}
$collected = $allocated + $advancePaid;

$pageTitle = 'Agreement ' . $agreement['agreement_no'];
include __DIR__ . '/../includes/header.php';
?>
<?php if (get('created') === '1'): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check-circle me-1"></i> Agreement activated. You can print it now.</span>
        <a href="<?= e(base_url('agreements/print.php?id=' . $id)) ?>" target="_blank" class="btn btn-sm btn-dark"><i class="bi bi-printer me-1"></i> Print Agreement</a>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="<?= e(base_url('agreements/index.php')) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to agreements</a>
        <h5 class="mt-2 mb-0">
            <?= e($agreement['agreement_no']) ?> <?= agreement_status_badge($agreement['status']) ?>
        </h5>
        <span class="text-muted small">Created <?= e(fmt_datetime($agreement['created_at'])) ?><?= $agreement['created_by_name'] ? ' by ' . e($agreement['created_by_name']) : '' ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('record_payments') && $agreement['status'] === 'active' && (float)$agreement['remaining_balance'] > 0): ?>
            <a href="<?= e(base_url('payments/add.php?agreement_id=' . $id)) ?>" class="btn btn-sm btn-success"><i class="bi bi-cash-coin me-1"></i> Record Payment</a>
        <?php endif; ?>
        <?php if (can('manage_agreements') && $agreement['status'] === 'draft'): ?>
            <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="activate">
                <button class="btn btn-sm btn-primary"><i class="bi bi-play-circle me-1"></i> Activate</button></form>
        <?php endif; ?>
        <?php if (can('manage_agreements') && $agreement['status'] === 'active' && (float)$agreement['remaining_balance'] <= 0): ?>
            <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="complete">
                <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all me-1"></i> Mark Completed</button></form>
        <?php endif; ?>
        <?php if (can('manage_agreements') && !in_array($agreement['status'], ['completed', 'cancelled'], true)): ?>
            <a href="<?= e(base_url('agreements/edit.php?id=' . $id)) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
        <a href="<?= e(base_url('agreements/print.php?id=' . $id)) ?>" target="_blank" class="btn btn-sm btn-dark"><i class="bi bi-printer me-1"></i> Print</a>
        <?php if (can('cancel_agreement') && $agreement['status'] !== 'cancelled'): ?>
            <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#cancelBox"><i class="bi bi-x-octagon me-1"></i> Cancel</button>
        <?php elseif ($agreement['status'] === 'cancelled'): ?>
            <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reopen">
                <button class="btn btn-sm btn-outline-success" data-confirm="Reopen this cancelled agreement?"><i class="bi bi-arrow-counterclockwise me-1"></i> Reopen</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($agreement['status'] === 'cancelled'): ?>
    <div class="alert alert-danger"><strong>Cancelled.</strong> <?= e($agreement['cancel_reason']) ?> - <?= e(fmt_datetime($agreement['cancelled_at'])) ?></div>
<?php endif; ?>

<?php if (can('cancel_agreement') && $agreement['status'] !== 'cancelled'): ?>
    <div class="collapse mb-3" id="cancelBox">
        <div class="card border-danger">
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="cancel">
                    <label class="form-label">Cancellation reason <span class="text-danger">*</span></label>
                    <input type="text" name="cancel_reason" class="form-control mb-2" maxlength="500" required>
                    <button class="btn btn-danger btn-sm" data-confirm="Cancel this agreement? Payment history will be preserved.">Confirm Cancellation</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-person"></i></div><div><span class="stat-value" style="font-size:.95rem"><?= e($agreement['customer_name']) ?></span><span class="stat-label">Customer</span></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-tag"></i></div><div><span class="stat-value"><?= e(money($agreement['agreed_sale_price'])) ?></span><span class="stat-label">Agreed Price</span></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div><div><span class="stat-value"><?= e(money($collected)) ?></span><span class="stat-label">Collected</span></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-circle"></i></div><div><span class="stat-value"><?= e(money($agreement['remaining_balance'])) ?></span><span class="stat-label">Outstanding</span></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-box-seam me-1"></i> Products</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Product</th><th>Brand / Model</th><th>Serial</th><th class="text-center">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $i => $p): ?>
                            <tr>
                                <td><?= (int)($i + 1) ?></td>
                                <td><?= e($p['product_name']) ?><?= $p['category'] ? '<div class="small text-muted">' . e($p['category']) . '</div>' : '' ?></td>
                                <td><?= e(trim(($p['brand'] ?: '') . ' ' . ($p['model'] ?: '')) ?: '-') ?></td>
                                <td><?= e($p['serial_no'] ?: '-') ?></td>
                                <td class="text-center"><?= e(rtrim(rtrim(number_format((float)$p['quantity'], 2), '0'), '.')) ?></td>
                                <td class="text-end"><?= e(money($p['unit_cash_price'])) ?></td>
                                <td class="text-end"><?= e(money($p['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar3 me-1"></i> Installment Schedule</span>
                <a class="small" href="<?= e(base_url('agreements/schedule.php?id=' . $id)) ?>">Full schedule</a>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height:380px;overflow:auto;">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light position-sticky top-0"><tr><th>#</th><th>Due Date</th><th class="text-end">Scheduled</th><th class="text-end">Paid</th><th class="text-end">Remaining</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($installments as $inst): ?>
                            <tr>
                                <td><?= (int)$inst['installment_no'] ?></td>
                                <td class="text-nowrap"><?= e(fmt_date($inst['due_date'])) ?></td>
                                <td class="text-end"><?= e(money($inst['scheduled_amount'])) ?></td>
                                <td class="text-end text-success"><?= e(money($inst['paid_amount'])) ?></td>
                                <td class="text-end"><?= e(money($inst['remaining_amount'])) ?></td>
                                <td><?= installment_status_badge($inst['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-person-vcard me-1"></i> Customer</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted">Name</th><td><?= e($agreement['customer_name']) ?> (<?= e($agreement['customer_code']) ?>)</td></tr>
                    <tr><th class="text-muted">Guardian</th><td><?= e($agreement['guardian_name'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Mobile</th><td><?= e($agreement['mobile'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">CNIC</th><td><?= e($agreement['cnic'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Address</th><td><?= e($agreement['current_address'] ?: '-') ?></td></tr>
                </table>
                <a href="<?= e(base_url('customers/view.php?id=' . (int)$agreement['customer_id'])) ?>" class="btn btn-sm btn-outline-primary mt-2">View customer profile</a>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-calculator me-1"></i> Financial Summary</div>
            <div class="card-body">
                <table class="table table-sm doc-figure mb-0">
                    <tr><td class="label">Agreed Sale Price</td><td class="text-end"><?= e(money($agreement['agreed_sale_price'])) ?></td></tr>
                    <tr><td class="label">Additional Charges</td><td class="text-end"><?= e(money($agreement['additional_charges'])) ?></td></tr>
                    <tr><td class="label">Discount</td><td class="text-end"><?= e(money($agreement['discount'])) ?></td></tr>
                    <tr><td class="label">Advance Payment</td><td class="text-end"><?= e(money($agreement['advance_payment'])) ?></td></tr>
                    <tr><td class="label">Installments Collected</td><td class="text-end"><?= e(money($allocated)) ?></td></tr>
                    <tr class="table-light"><td class="label fw-bold">Total Collected</td><td class="text-end fw-bold"><?= e(money($collected)) ?></td></tr>
                    <tr><td class="label fw-bold">Outstanding Balance</td><td class="text-end fw-bold text-danger"><?= e(money($agreement['remaining_balance'])) ?></td></tr>
                </table>
                <div class="small text-muted mt-2">
                    <?= (int)$agreement['installment_count'] ?> x <?= e(ucfirst($agreement['frequency'])) ?> installments ·
                    First due <?= e(fmt_date($agreement['first_due_date']) ?: '-') ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-check me-1"></i> Guarantors</div>
            <div class="card-body">
                <?php if (empty($guarantors)): ?>
                    <p class="text-muted mb-0 small">No guarantors for this agreement.</p>
                <?php else: ?>
                    <?php foreach ($guarantors as $g): ?>
                        <div class="border rounded p-2 mb-2">
                            <strong><?= e($g['full_name']) ?></strong><br>
                            <span class="small text-muted">Father: <?= e($g['father_name'] ?: '-') ?> · CNIC: <?= e($g['cnic'] ?: '-') ?></span><br>
                            <span class="small text-muted">Mobile: <?= e($g['mobile'] ?: '-') ?> · <?= e($g['relationship'] ?: '') ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-1"></i> Payments</span>
        <?php if (can('record_payments') && $agreement['status'] === 'active' && (float)$agreement['remaining_balance'] > 0): ?>
            <a class="small" href="<?= e(base_url('payments/add.php?agreement_id=' . $id)) ?>">Record a payment</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <p class="text-muted mb-0 small">No payments recorded.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Receipt</th><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Method</th><th>Received By</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr class="<?= $p['status'] === 'void' ? 'table-danger' : '' ?>">
                            <td class="text-nowrap"><?= e($p['receipt_no']) ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($p['payment_date'])) ?></td>
                            <td><?= e(payment_type_label($p['payment_type'])) ?></td>
                            <td class="text-end"><?= e(money($p['amount'])) ?></td>
                            <td><?= e(payment_method_label($p['method'])) ?></td>
                            <td><?= e($p['received_by_name'] ?: '-') ?></td>
                            <td><?= $p['status'] === 'void' ? '<span class="badge text-bg-danger">Void</span>' : '<span class="badge text-bg-success">Active</span>' ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('payments/view.php?id=' . (int)$p['id'])) ?>"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($amendments)): ?>
<div class="card shadow-sm">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> Amendment History</div>
    <div class="card-body">
        <?php foreach ($amendments as $a): ?>
            <div class="border rounded p-2 mb-2">
                <div class="small text-muted"><?= e(fmt_datetime($a['created_at'])) ?> · <?= e($a['user_name'] ?: 'System') ?></div>
                <div><strong>Reason:</strong> <?= e($a['reason']) ?></div>
                <div class="small">
                    <span class="text-muted">Old:</span> <code><?= e($a['old_values']) ?></code><br>
                    <span class="text-muted">New:</span> <code><?= e($a['new_values']) ?></code>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
