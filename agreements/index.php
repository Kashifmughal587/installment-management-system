<?php
/**
 * Agreements list - filters, search, pagination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_agreements');

$db = getDB();

$q          = get('q');
$status     = get('status');
$customerId = (int)get('customer_id', 0);
$dateFrom   = get('date_from');
$dateTo     = get('date_to');
$page       = max(1, (int)get('page', 1));
$perPage    = 15;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (g.agreement_no LIKE :q OR c.full_name LIKE :q OR c.customer_code LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($status, ['draft', 'active', 'completed', 'cancelled'], true)) {
    $where .= ' AND g.status = :status';
    $params[':status'] = $status;
}
if ($customerId > 0) {
    $where .= ' AND g.customer_id = :cid';
    $params[':cid'] = $customerId;
}
if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where .= ' AND g.agreement_date >= :df';
    $params[':df'] = $dateFrom;
}
if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where .= ' AND g.agreement_date <= :dt';
    $params[':dt'] = $dateTo;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM agreements g JOIN customers c ON c.id = g.customer_id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$sql = "SELECT g.*, c.full_name AS customer_name,
        (SELECT CONCAT(p.product_name, CASE WHEN COUNT(*) > 1 THEN CONCAT(' +', COUNT(*)-1) ELSE '' END)
         FROM agreement_products p WHERE p.agreement_id = g.id GROUP BY p.id ORDER BY p.id LIMIT 1) AS product_name
        FROM agreements g
        JOIN customers c ON c.id = g.customer_id
        WHERE $where
        ORDER BY g.id DESC
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$agreements = $stmt->fetchAll();

$customers = $db->query("SELECT id, customer_code, full_name FROM customers ORDER BY full_name ASC")->fetchAll();
$summary = $db->query("SELECT
    COALESCE(SUM(CASE WHEN status='active' THEN agreed_sale_price ELSE 0 END),0) AS active_sales,
    COALESCE(SUM(CASE WHEN status IN ('active','completed') THEN remaining_balance ELSE 0 END),0) AS outstanding
    FROM agreements")->fetch();

$pageTitle = 'Agreements';
$pageActions = can('manage_agreements')
    ? '<a href="' . e(base_url('agreements/add.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus me-1"></i> Create Agreement</a>'
    : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-file-earmark-text"></i></div>
            <div><span class="stat-value"><?= (int)$total ?></span><span class="stat-label">Matching Agreements</span></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-cart-check"></i></div>
            <div><span class="stat-value"><?= e(money($summary['active_sales'])) ?></span><span class="stat-label">Active Agreed Sales</span></div></div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="stat-card stat-wide"><span class="stat-label">Outstanding (active + completed)</span>
            <span class="stat-value text-danger"><?= e(money_pkr($summary['outstanding'])) ?></span></div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(base_url('agreements/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Agreement no, customer" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['draft', 'active', 'completed', 'cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(agreement_status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">All customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['customer_code'] . ' - ' . $c['full_name']) ?></option>
                    <?php endforeach; ?>
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
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a class="btn btn-outline-secondary" href="<?= e(base_url('agreements/index.php')) ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Agreements</h6>
        <span class="text-muted small"><?= (int)$total ?> record(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($agreements)): ?>
            <div class="empty-state"><i class="bi bi-files"></i>No agreements found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <th>Agreement No.</th><th>Customer</th><th>Product</th><th>Date</th>
                            <th class="text-end">Agreed</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th>
                            <th>Status</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agreements as $g):
                            $collected = (float)$g['advance_payment'] + agreement_paid_total($db, (int)$g['id']);
                        ?>
                        <tr>
                            <td class="text-nowrap"><a href="<?= e(base_url('agreements/view.php?id=' . (int)$g['id'])) ?>"><?= e($g['agreement_no']) ?></a></td>
                            <td><?= e($g['customer_name']) ?></td>
                            <td><?= e($g['product_name'] ?: '-') ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($g['agreement_date'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money($g['agreed_sale_price'])) ?></td>
                            <td class="text-end text-nowrap text-success"><?= e(money($collected)) ?></td>
                            <td class="text-end text-nowrap <?= (float)$g['remaining_balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($g['remaining_balance'])) ?></td>
                            <td><?= agreement_status_badge($g['status']) ?></td>
                            <td class="actions-col">
                                <a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('agreements/view.php?id=' . (int)$g['id'])) ?>" title="View"><i class="bi bi-eye"></i></a>
                                <?php if (can('manage_agreements') && !in_array($g['status'], ['completed', 'cancelled'], true)): ?>
                                    <a class="btn btn-sm btn-outline-secondary py-0" href="<?= e(base_url('agreements/edit.php?id=' . (int)$g['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-dark py-0" href="<?= e(base_url('agreements/print.php?id=' . (int)$g['id'])) ?>" target="_blank" title="Print"><i class="bi bi-printer"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small">Page <?= (int)$pg['page'] ?> of <?= (int)$pg['pages'] ?></span>
                <?= pagination_links($pg, ['q' => $q, 'status' => $status, 'customer_id' => $customerId, 'date_from' => $dateFrom, 'date_to' => $dateTo]) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
