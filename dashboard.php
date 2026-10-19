<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$db = getDB();
mark_overdue_installments($db);

/* ---------------------------------------------------------------- */
/*  Filters                                                          */
/* ---------------------------------------------------------------- */
$from = get('from');
$to   = get('to');
if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = ''; }
if ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))     { $to = ''; }

$agreementDateClause = '';
$agreementParams = [];
if ($from) { $agreementDateClause .= ' AND agreement_date >= ?'; $agreementParams[] = $from; }
if ($to)   { $agreementDateClause .= ' AND agreement_date <= ?'; $agreementParams[] = $to; }

$paymentDateClause = '';
$paymentParams = [];
if ($from) { $paymentDateClause .= ' AND p.payment_date >= ?'; $paymentParams[] = $from; }
if ($to)   { $paymentDateClause .= ' AND p.payment_date <= ?'; $paymentParams[] = $to; }

/* ---------------------------------------------------------------- */
/*  Summary metrics                                                  */
/* ---------------------------------------------------------------- */
$totalCustomers = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$activeAgreements = (int)$db->query("SELECT COUNT(*) FROM agreements WHERE status = 'active'")->fetchColumn();
$completedAgreements = (int)$db->query("SELECT COUNT(*) FROM agreements WHERE status = 'completed'")->fetchColumn();

$saleSql = "SELECT COALESCE(SUM(agreed_sale_price),0)
            FROM agreements
            WHERE status IN ('draft','active','completed')" . $agreementDateClause;
$stmt = $db->prepare($saleSql);
$stmt->execute($agreementParams);
$totalAgreedSales = (float)$stmt->fetchColumn();

// Advances actually collected (money received as advance).
$stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p
                      WHERE p.status = 'active' AND p.payment_type = 'advance'" . $paymentDateClause);
$stmt->execute($paymentParams);
$totalAdvances = (float)$stmt->fetchColumn();

// Installment payments collected (allocations to installments).
$stmt = $db->prepare("SELECT COALESCE(SUM(a.amount),0)
                      FROM payment_allocations a
                      JOIN payments p ON p.id = a.payment_id
                      WHERE p.status = 'active'" . $paymentDateClause);
$stmt->execute($paymentParams);
$totalInstallmentPayments = (float)$stmt->fetchColumn();

$totalCollected = (float)$totalAdvances + $totalInstallmentPayments;

// Outstanding balance on non-cancelled agreements.
$stmt = $db->prepare("SELECT COALESCE(SUM(remaining_balance),0) FROM agreements
                      WHERE status IN ('draft','active','completed')" . $agreementDateClause);
$stmt->execute($agreementParams);
$totalOutstanding = (float)$stmt->fetchColumn();

// Overdue amount.
$stmt = $db->prepare("SELECT COALESCE(SUM(i.remaining_amount),0)
                      FROM installments i
                      JOIN agreements g ON g.id = i.agreement_id
                      WHERE g.status = 'active'
                        AND i.due_date < CURDATE()
                        AND i.status IN ('pending','partially_paid','overdue')");
$stmt->execute();
$overdueAmount = (float)$stmt->fetchColumn();

// Due today.
$stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(i.remaining_amount),0)
                      FROM installments i
                      JOIN agreements g ON g.id = i.agreement_id
                      WHERE g.status = 'active' AND i.due_date = CURDATE() AND i.remaining_amount > 0");
$stmt->execute();
[$dueTodayCount, $dueTodayAmount] = $stmt->fetch(PDO::FETCH_NUM);

// Due soon (next 7 days).
$stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(i.remaining_amount),0)
                      FROM installments i
                      JOIN agreements g ON g.id = i.agreement_id
                      WHERE g.status = 'active'
                        AND i.due_date > CURDATE()
                        AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                        AND i.remaining_amount > 0");
$stmt->execute();
[$dueSoonCount, $dueSoonAmount] = $stmt->fetch(PDO::FETCH_NUM);

/* ---------------------------------------------------------------- */
/*  Recent agreements                                                */
/* ---------------------------------------------------------------- */
$recent = $db->query(
    "SELECT g.id, g.agreement_no, g.agreement_date, g.status,
            g.agreed_sale_price, g.remaining_balance,
            c.full_name AS customer_name,
            (SELECT CONCAT(p.product_name, CASE WHEN COUNT(*) > 1 THEN CONCAT(' +', COUNT(*)-1) ELSE '' END)
             FROM agreement_products p WHERE p.agreement_id = g.id
             GROUP BY p.id ORDER BY p.id LIMIT 1) AS product_name
     FROM agreements g
     JOIN customers c ON c.id = g.customer_id
     ORDER BY g.id DESC
     LIMIT 10"
)->fetchAll();

/* Quick-action counts for the quick links */
$pendingPayments = (int)$db->query(
    "SELECT COUNT(*) FROM installments i JOIN agreements g ON g.id = i.agreement_id
     WHERE g.status = 'active' AND i.status IN ('pending','overdue','partially_paid')"
)->fetchColumn();

$pageTitle = 'Dashboard';
$balanceClass = $totalOutstanding > 0 ? 'text-danger' : 'text-success';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="GET" action="<?= e(base_url('dashboard.php')) ?>">
        <div>
            <label class="form-label small mb-1">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="form-label small mb-1">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
        </div>
        <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
        <?php if ($from || $to): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('dashboard.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <span class="text-muted small">
        Period: <strong><?= e($from ? fmt_date($from) : 'All time') ?></strong>
        to <strong><?= e($to ? fmt_date($to) : 'Today') ?></strong>
    </span>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></div>
            <div><span class="stat-value"><?= (int)$totalCustomers ?></span><span class="stat-label">Total Customers</span></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-file-earmark-text"></i></div>
            <div><span class="stat-value"><?= (int)$activeAgreements ?></span><span class="stat-label">Active Agreements</span></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-check2-circle"></i></div>
            <div><span class="stat-value"><?= (int)$completedAgreements ?></span><span class="stat-label">Completed Agreements</span></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-exclamation-octagon"></i></div>
            <div><span class="stat-value"><?= (int)$overdueAmount > 0 ? number_format($overdueAmount, 0) : '0' ?></span><span class="stat-label">Overdue Amount</span></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Total Agreed Installment Sales</span>
            <span class="stat-value"><?= e(money_pkr($totalAgreedSales)) ?></span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Total Advance Payments Collected</span>
            <span class="stat-value text-success"><?= e(money_pkr($totalAdvances)) ?></span>
            <span class="stat-sub">Recorded as advance payments</span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Total Installment Payments Collected</span>
            <span class="stat-value text-success"><?= e(money_pkr($totalInstallmentPayments)) ?></span>
            <span class="stat-sub">Allocated to installments</span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Total Amount Collected</span>
            <span class="stat-value text-success"><?= e(money_pkr($totalCollected)) ?></span>
            <span class="stat-sub">Advances + installments</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide border-danger">
            <span class="stat-label">Total Outstanding Balance</span>
            <span class="stat-value <?= e($balanceClass) ?>"><?= e(money_pkr($totalOutstanding)) ?></span>
            <span class="stat-sub">Agreed price − advances − payments</span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Installments Due Today</span>
            <span class="stat-value"><?= (int)$dueTodayCount ?></span>
            <span class="stat-sub"><?= e(money_pkr($dueTodayAmount)) ?></span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Installments Due Soon (7 days)</span>
            <span class="stat-value"><?= (int)$dueSoonCount ?></span>
            <span class="stat-sub"><?= e(money_pkr($dueSoonAmount)) ?></span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-wide">
            <span class="stat-label">Awaiting Collection</span>
            <span class="stat-value"><?= (int)$pendingPayments ?></span>
            <span class="stat-sub">Installments not yet fully paid</span>
        </div>
    </div>
</div>

<!-- Quick actions -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-lightning me-1"></i> Quick Actions</h6></div>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">
            <?php if (can('manage_customers')): ?>
                <a href="<?= e(base_url('customers/add.php')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i> Add Customer</a>
            <?php endif; ?>
            <?php if (can('manage_agreements')): ?>
                <a href="<?= e(base_url('agreements/add.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-plus me-1"></i> Create Agreement</a>
            <?php endif; ?>
            <?php if (can('record_payments')): ?>
                <a href="<?= e(base_url('payments/add.php')) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cash-coin me-1"></i> Record Payment</a>
            <?php endif; ?>
            <a href="<?= e(base_url('customers/index.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-people me-1"></i> View Customers</a>
            <a href="<?= e(base_url('agreements/index.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-files me-1"></i> View Agreements</a>
            <a href="<?= e(base_url('reports/index.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart me-1"></i> View Reports</a>
        </div>
    </div>
</div>

<!-- Recent agreements -->
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Recent Agreements</h6>
        <a href="<?= e(base_url('agreements/index.php')) ?>" class="small">View all</a>
    </div>
    <div class="card-body">
        <?php if (empty($recent)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                No agreements yet.
                <?php if (can('manage_agreements')): ?>
                    <a href="<?= e(base_url('agreements/add.php')) ?>">Create the first agreement</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Agreement No.</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Date</th>
                            <th class="text-end">Agreed Price</th>
                            <th class="text-end">Outstanding</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="text-nowrap"><a href="<?= e(base_url('agreements/view.php?id=' . (int)$r['id'])) ?>"><?= e($r['agreement_no']) ?></a></td>
                            <td><?= e($r['customer_name']) ?></td>
                            <td><?= e($r['product_name'] ?: '-') ?></td>
                            <td class="text-nowrap"><?= e(fmt_date($r['agreement_date'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money($r['agreed_sale_price'])) ?></td>
                            <td class="text-end text-nowrap <?= (float)$r['remaining_balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($r['remaining_balance'])) ?></td>
                            <td><?= agreement_status_badge($r['status']) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary py-0" href="<?= e(base_url('agreements/view.php?id=' . (int)$r['id'])) ?>" title="View"><i class="bi bi-eye"></i></a>
                                <?php if (can('manage_agreements')): ?>
                                <a class="btn btn-sm btn-outline-secondary py-0" href="<?= e(base_url('agreements/edit.php?id=' . (int)$r['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-dark py-0" href="<?= e(base_url('agreements/print.php?id=' . (int)$r['id'])) ?>" target="_blank" title="Print"><i class="bi bi-printer"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
