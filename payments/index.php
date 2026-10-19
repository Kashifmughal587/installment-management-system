<?php
/**
 * Payments list - search, filters, pagination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment.php';
require_permission('view_payments');

$db = getDB();

$q        = get('q');
$method   = get('method');
$status   = get('status');
$agreementId = (int)get('agreement_id', 0);
$dateFrom = get('date_from');
$dateTo   = get('date_to');
$page     = max(1, (int)get('page', 1));
$perPage  = 20;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (p.receipt_no LIKE :q OR c.full_name LIKE :q OR c.customer_code LIKE :q OR p.transaction_ref LIKE :q OR g.agreement_no LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($method, ['cash', 'bank_transfer', 'mobile_wallet', 'cheque', 'other'], true)) {
    $where .= ' AND p.method = :method';
    $params[':method'] = $method;
}
if (in_array($status, ['active', 'void'], true)) {
    $where .= ' AND p.status = :status';
    $params[':status'] = $status;
}
if ($agreementId > 0) {
    $where .= ' AND p.agreement_id = :agreement_id';
    $params[':agreement_id'] = $agreementId;
}
if ($dateFrom !== '') {
    $where .= ' AND p.payment_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND p.payment_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$base = "FROM payments p
         JOIN customers c ON c.id = p.customer_id
         JOIN agreements g ON g.id = p.agreement_id
         WHERE $where";

$countStmt = $db->prepare("SELECT COUNT(*) $base");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$sumStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN p.status='active' THEN p.amount ELSE 0 END),0) $base");
$sumStmt->execute($params);
$sumActive = (float)$sumStmt->fetchColumn();

$sql = "SELECT p.*, c.full_name AS customer_name, c.customer_code, g.agreement_no $base
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'Payments';
$pageActions = can('record_payments')
    ? '<a href="' . e(base_url('payments/add.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Record Payment</a>'
    : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(base_url('payments/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Receipt, customer, ref…" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Method</label>
                <select name="method" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mobile_wallet' => 'Mobile Wallet', 'cheque' => 'Cheque', 'other' => 'Other'] as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $method === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="void" <?= $status === 'void' ? 'selected' : '' ?>>Void</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i> Filter</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('payments/index.php')) ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Payment Records</h6>
        <span class="text-muted small">
            <?= (int)$total ?> record(s) · Active total: <strong><?= e(money_pkr($sumActive)) ?></strong>
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <i class="bi bi-cash-coin"></i>
                No payments found.
                <?php if (can('record_payments')): ?>
                    <div class="mt-2"><a href="<?= e(base_url('payments/add.php')) ?>" class="btn btn-sm btn-primary">Record a payment</a></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <th>Receipt #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Agreement</th>
                            <th class="text-end">Amount</th>
                            <th>Method</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-nowrap"><a href="<?= e(base_url('payments/view.php?id=' . (int)$p['id'])) ?>"><?= e($p['receipt_no']) ?></a></td>
                            <td class="text-nowrap"><?= e(fmt_date($p['payment_date'])) ?></td>
                            <td>
                                <a href="<?= e(base_url('customers/view.php?id=' . (int)$p['customer_id'])) ?>"><?= e($p['customer_name']) ?></a>
                                <div class="small text-muted"><?= e($p['customer_code']) ?></div>
                            </td>
                            <td class="text-nowrap"><a href="<?= e(base_url('agreements/view.php?id=' . (int)$p['agreement_id'])) ?>"><?= e($p['agreement_no']) ?></a></td>
                            <td class="text-end text-nowrap fw-semibold <?= $p['status'] === 'void' ? 'text-muted text-decoration-line-through' : '' ?>"><?= e(money($p['amount'])) ?></td>
                            <td><?= e(payment_method_label($p['method'])) ?></td>
                            <td><?= e(payment_type_label($p['payment_type'])) ?></td>
                            <td>
                                <?php if ($p['status'] === 'void'): ?>
                                    <span class="badge text-bg-danger">Void</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-col">
                                <a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('payments/view.php?id=' . (int)$p['id'])) ?>" title="View"><i class="bi bi-eye"></i></a>
                                <a class="btn btn-sm btn-outline-dark py-0" href="<?= e(base_url('payments/receipt.php?id=' . (int)$p['id'])) ?>" title="Receipt" target="_blank"><i class="bi bi-receipt"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small">Page <?= (int)$pg['page'] ?> of <?= (int)$pg['pages'] ?></span>
                <?= pagination_links($pg, array_filter([
                    'q' => $q, 'method' => $method, 'status' => $status,
                    'date_from' => $dateFrom, 'date_to' => $dateTo,
                    'agreement_id' => $agreementId ?: null,
                ])) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
