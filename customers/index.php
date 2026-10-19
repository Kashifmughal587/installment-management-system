<?php
/**
 * Customers list - search, filter, sort, pagination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_customers');

$db = getDB();

$q       = get('q');
$status  = get('status');
$sort    = get('sort', 'created');
$dir     = strtolower(get('dir', 'desc'));
$page    = max(1, (int)get('page', 1));
$perPage = 15;

$allowedSort = [
    'created' => 'c.created_at',
    'name'    => 'c.full_name',
    'code'    => 'c.customer_code',
    'city'    => 'c.city',
];
$sortCol = $allowedSort[$sort] ?? 'c.created_at';
$dir = ($dir === 'asc') ? 'ASC' : 'DESC';

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (c.full_name LIKE :q OR c.customer_code LIKE :q OR c.mobile LIKE :q OR c.cnic LIKE :q OR c.whatsapp LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($status, ['active', 'inactive', 'archived'], true)) {
    $where .= ' AND c.status = :status';
    $params[':status'] = $status;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$sql = "SELECT c.*,
        (SELECT COUNT(*) FROM agreements a WHERE a.customer_id = c.id) AS agreement_count,
        (SELECT COALESCE(SUM(a.remaining_balance),0) FROM agreements a WHERE a.customer_id = c.id AND a.status IN ('draft','active','completed')) AS outstanding
        FROM customers c
        WHERE $where
        ORDER BY $sortCol $dir
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
$sortLink = function (string $key) use ($sort, $dir, $q, $status) {
    $newDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    return '?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'sort' => $key, 'dir' => $newDir]));
};
$pageActions = can('manage_customers')
    ? '<a href="' . e(base_url('customers/add.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i> Add Customer</a>'
    : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(base_url('customers/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Name, code, phone or CNIC" value="<?= e($q) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1"></i> Search</button>
                <a class="btn btn-outline-secondary" href="<?= e(base_url('customers/index.php')) ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Customer List</h6>
        <span class="text-muted small"><?= (int)$total ?> record(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <i class="bi bi-people"></i>
                No customers found.
                <?php if (can('manage_customers')): ?>
                    <div class="mt-2"><a href="<?= e(base_url('customers/add.php')) ?>" class="btn btn-sm btn-primary">Add your first customer</a></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <th><a href="<?= e($sortLink('code')) ?>">Code</a></th>
                            <th><a href="<?= e($sortLink('name')) ?>">Name</a></th>
                            <th>Mobile</th>
                            <th>CNIC</th>
                            <th><a href="<?= e($sortLink('city')) ?>">City</a></th>
                            <th class="text-center">Agreements</th>
                            <th class="text-end">Outstanding</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($c['customer_code']) ?></td>
                            <td>
                                <a href="<?= e(base_url('customers/view.php?id=' . (int)$c['id'])) ?>"><?= e($c['full_name']) ?></a>
                                <?php if ($c['guardian_name']): ?><div class="small text-muted">s/o <?= e($c['guardian_name']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e($c['mobile'] ?: '-') ?></td>
                            <td class="text-nowrap"><?= e($c['cnic'] ?: '-') ?></td>
                            <td><?= e($c['city'] ?: '-') ?></td>
                            <td class="text-center"><?= (int)$c['agreement_count'] ?></td>
                            <td class="text-end text-nowrap <?= (float)$c['outstanding'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= e(money($c['outstanding'])) ?></td>
                            <td>
                                <?php
                                $sc = ['active' => 'success', 'inactive' => 'secondary', 'archived' => 'dark'][$c['status']] ?? 'secondary';
                                ?>
                                <span class="badge text-bg-<?= $sc ?>"><?= e(ucfirst($c['status'])) ?></span>
                            </td>
                            <td class="actions-col">
                                <a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('customers/view.php?id=' . (int)$c['id'])) ?>" title="View"><i class="bi bi-eye"></i></a>
                                <?php if (can('manage_customers')): ?>
                                    <a class="btn btn-sm btn-outline-secondary py-0" href="<?= e(base_url('customers/edit.php?id=' . (int)$c['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (can('manage_agreements')): ?>
                                    <a class="btn btn-sm btn-outline-success py-0" href="<?= e(base_url('agreements/add.php?customer_id=' . (int)$c['id'])) ?>" title="New agreement"><i class="bi bi-file-earmark-plus"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small">Page <?= (int)$pg['page'] ?> of <?= (int)$pg['pages'] ?></span>
                <?= pagination_links($pg, ['q' => $q, 'status' => $status, 'sort' => $sort, 'dir' => $dir]) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
