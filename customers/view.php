<?php
/**
 * Customer profile: details, agreements, payment history, balances.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_customers');

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

/* ----- Status change (archive / activate / deactivate) ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') !== '') {
    csrf_require();
    $action = post('action');
    $hasAgreements = (int)$db->query('SELECT COUNT(*) FROM agreements WHERE customer_id = ' . $id)->fetchColumn() > 0;

    if ($action === 'archive') {
        if (!can('delete_customer')) {
            flash_set('error', 'Only an owner can archive customers.');
        } else {
            $db->prepare("UPDATE customers SET status = 'archived', updated_by = ? WHERE id = ?")
               ->execute([current_user_id(), $id]);
            audit_log(current_user_id(), 'customer_archived', 'customer', $id);
            flash_set('success', 'Customer archived. Financial records were preserved.');
        }
    } elseif ($action === 'activate') {
        $db->prepare("UPDATE customers SET status = 'active', updated_by = ? WHERE id = ?")
           ->execute([current_user_id(), $id]);
        audit_log(current_user_id(), 'customer_activated', 'customer', $id);
        flash_set('success', 'Customer activated.');
    } elseif ($action === 'deactivate') {
        $db->prepare("UPDATE customers SET status = 'inactive', updated_by = ? WHERE id = ?")
           ->execute([current_user_id(), $id]);
        audit_log(current_user_id(), 'customer_deactivated', 'customer', $id);
        flash_set('success', 'Customer deactivated.');
    }
    redirect('customers/view.php?id=' . $id);
}

/* ----- Data ----- */
$agreements = $db->prepare(
    "SELECT g.*, (SELECT CONCAT(p.product_name, CASE WHEN COUNT(*) > 1 THEN CONCAT(' +', COUNT(*)-1) ELSE '' END)
                  FROM agreement_products p WHERE p.agreement_id = g.id GROUP BY p.id ORDER BY p.id LIMIT 1) AS product_name
     FROM agreements g WHERE g.customer_id = ? ORDER BY g.id DESC"
);
$agreements->execute([$id]);
$agreements = $agreements->fetchAll();

$summary = $db->prepare(
    "SELECT
        COUNT(*) AS total_agreements,
        COALESCE(SUM(CASE WHEN status IN ('draft','active') THEN 1 ELSE 0 END),0) AS open_agreements,
        COALESCE(SUM(CASE WHEN status IN ('draft','active','completed') THEN agreed_sale_price ELSE 0 END),0) AS agreed_total,
        COALESCE(SUM(CASE WHEN status IN ('draft','active','completed') THEN remaining_balance ELSE 0 END),0) AS outstanding
     FROM agreements WHERE customer_id = ?"
);
$summary->execute([$id]);
$summary = $summary->fetch();

$paidStmt = $db->prepare(
    "SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a
     JOIN payments p ON p.id = a.payment_id
     WHERE p.customer_id = ? AND p.status = 'active'"
);
$paidStmt->execute([$id]);
$installmentPaid = (float)$paidStmt->fetchColumn();

$advStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ? AND status = 'active' AND payment_type = 'advance'");
$advStmt->execute([$id]);
$advancePaid = (float)$advStmt->fetchColumn();

$payments = $db->prepare(
    'SELECT p.*, u.full_name AS received_by_name
     FROM payments p LEFT JOIN users u ON u.id = p.received_by
     WHERE p.customer_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 50'
);
$payments->execute([$id]);
$payments = $payments->fetchAll();

$pageTitle = 'Customer - ' . $customer['full_name'];
$photoUrl = $customer['photo_path'] ? upload_url($customer['photo_path']) : '';
$frontUrl = $customer['cnic_front_path'] ? upload_url($customer['cnic_front_path']) : '';
$backUrl  = $customer['cnic_back_path'] ? upload_url($customer['cnic_back_path']) : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <a href="<?= e(base_url('customers/index.php')) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to customers</a>
        <h5 class="mt-2 mb-0"><?= e($customer['full_name']) ?> <span class="text-muted fs-6">(<?= e($customer['customer_code']) ?>)</span></h5>
    </div>
    <div class="d-flex gap-2">
        <?php if (can('manage_agreements')): ?>
            <a href="<?= e(base_url('agreements/add.php?customer_id=' . $id)) ?>" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-plus me-1"></i> New Agreement</a>
        <?php endif; ?>
        <?php if (can('manage_customers')): ?>
            <a href="<?= e(base_url('customers/edit.php?id=' . $id)) ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
        <?php if (can('delete_customer') && $customer['status'] !== 'archived'): ?>
            <form method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="archive">
                <button class="btn btn-sm btn-outline-danger" data-confirm="Archive this customer? Financial records will be preserved."><i class="bi bi-archive me-1"></i> Archive</button>
            </form>
        <?php elseif ($customer['status'] === 'archived'): ?>
            <form method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="activate">
                <button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-file-earmark-text"></i></div>
            <div><span class="stat-value"><?= (int)$summary['total_agreements'] ?></span><span class="stat-label">Agreements</span></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-cart"></i></div>
            <div><span class="stat-value"><?= e(money($summary['agreed_total'])) ?></span><span class="stat-label">Agreed Sales</span></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div>
            <div><span class="stat-value"><?= e(money($advancePaid + $installmentPaid)) ?></span><span class="stat-label">Total Collected</span></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-circle"></i></div>
            <div><span class="stat-value"><?= e(money($summary['outstanding'])) ?></span><span class="stat-label">Outstanding</span></div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-person-vcard me-1"></i> Customer Details</div>
            <div class="card-body">
                <?php if ($photoUrl): ?>
                    <a href="<?= e($photoUrl) ?>" data-lightbox data-lightbox-title="Customer Photograph" class="d-inline-block mb-3">
                        <img src="<?= e($photoUrl) ?>" alt="photo" style="max-height:120px;border-radius:8px;cursor:zoom-in;">
                    </a>
                <?php endif; ?>
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted" style="width:42%">Father's / Husband's Name</th><td><?= e($customer['guardian_name'] ?: '-') ?><?= $customer['guardian_relation'] ? ' (' . e($customer['guardian_relation']) . ')' : '' ?></td></tr>
                    <tr><th class="text-muted">CNIC</th><td><?= e($customer['cnic'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Date of Birth</th><td><?= e(fmt_date($customer['dob']) ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Gender</th><td><?= e($customer['gender'] ? ucfirst($customer['gender']) : '-') ?></td></tr>
                    <tr><th class="text-muted">Occupation</th><td><?= e($customer['occupation'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Monthly Income</th><td><?= $customer['monthly_income'] !== null ? e(money_pkr($customer['monthly_income'])) : '-' ?></td></tr>
                    <tr><th class="text-muted">Mobile</th><td><?= e($customer['mobile'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">WhatsApp</th><td><?= e($customer['whatsapp'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Email</th><td><?= e($customer['email'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Current Address</th><td><?= nl2br(e($customer['current_address'] ?: '-')) ?></td></tr>
                    <tr><th class="text-muted">Permanent Address</th><td><?= nl2br(e($customer['permanent_address'] ?: '-')) ?></td></tr>
                    <tr><th class="text-muted">City / District</th><td><?= e(trim(($customer['city'] ?: '-') . ' / ' . ($customer['district'] ?: '-'))) ?></td></tr>
                    <tr><th class="text-muted">Record Status</th><td>
                        <?php $sc = ['active' => 'success', 'inactive' => 'secondary', 'archived' => 'dark'][$customer['status']] ?? 'secondary'; ?>
                        <span class="badge text-bg-<?= $sc ?>"><?= e(ucfirst($customer['status'])) ?></span>
                    </td></tr>
                    <tr><th class="text-muted">Created</th><td><?= e(fmt_datetime($customer['created_at'])) ?></td></tr>
                </table>
                <?php if ($customer['notes']): ?>
                    <hr>
                    <div class="small text-muted">Internal Notes</div>
                    <div><?= nl2br(e($customer['notes'])) ?></div>
                <?php endif; ?>
                <?php if ($frontUrl || $backUrl): ?>
                    <hr>
                    <div class="d-flex gap-3">
                        <?php if ($frontUrl): ?><a href="<?= e($frontUrl) ?>" data-lightbox data-lightbox-title="CNIC Front" class="btn btn-sm btn-outline-secondary"><i class="bi bi-card-image me-1"></i> CNIC Front</a><?php endif; ?>
                        <?php if ($backUrl): ?><a href="<?= e($backUrl) ?>" data-lightbox data-lightbox-title="CNIC Back" class="btn btn-sm btn-outline-secondary"><i class="bi bi-card-image me-1"></i> CNIC Back</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-files me-1"></i> Agreements</div>
            <div class="card-body">
                <?php if (empty($agreements)): ?>
                    <p class="text-muted mb-0">No agreements for this customer yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Agreement</th><th>Product</th><th>Date</th><th class="text-end">Agreed</th><th class="text-end">Outstanding</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agreements as $g): ?>
                                <tr>
                                    <td class="text-nowrap"><?= e($g['agreement_no']) ?></td>
                                    <td><?= e($g['product_name'] ?: '-') ?></td>
                                    <td class="text-nowrap"><?= e(fmt_date($g['agreement_date'])) ?></td>
                                    <td class="text-end"><?= e(money($g['agreed_sale_price'])) ?></td>
                                    <td class="text-end <?= (float)$g['remaining_balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($g['remaining_balance'])) ?></td>
                                    <td><?= agreement_status_badge($g['status']) ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('agreements/view.php?id=' . (int)$g['id'])) ?>"><i class="bi bi-eye"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-receipt me-1"></i> Payment History <span class="text-muted small">(last 50)</span></div>
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <p class="text-muted mb-0">No payments recorded for this customer.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Receipt</th><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Method</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                <tr class="<?= $p['status'] === 'void' ? 'table-danger' : '' ?>">
                                    <td class="text-nowrap"><?= e($p['receipt_no']) ?></td>
                                    <td class="text-nowrap"><?= e(fmt_date($p['payment_date'])) ?></td>
                                    <td><?= e(payment_type_label($p['payment_type'])) ?></td>
                                    <td class="text-end"><?= e(money($p['amount'])) ?></td>
                                    <td><?= e(payment_method_label($p['method'])) ?></td>
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
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
