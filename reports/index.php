<?php
/**
 * Reports: collections, outstanding receivables, overdue installments and
 * agreement summaries, with CSV export of the currently selected report.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_reports');

$db = getDB();
mark_overdue_installments($db);

$report   = get('report', 'outstanding');
$dateFrom = get('date_from', date('Y-m-01'));
$dateTo   = get('date_to', date('Y-m-d'));
$customerId = (int)get('customer_id', 0);
$export   = get('export');

$reports = [
    'outstanding' => 'Outstanding Receivables',
    'collections' => 'Collections (Payments)',
    'overdue'     => 'Overdue Installments',
    'agreements'  => 'Agreement Summary',
];
if (!isset($reports[$report])) {
    $report = 'outstanding';
}

$params = [];
$customerFilter = $customerId > 0 ? ' AND g.customer_id = :cid' : '';
if ($customerId > 0) {
    $params[':cid'] = $customerId;
}

$columns = [];
$rows = [];
$totals = [];

switch ($report) {
    case 'collections':
        $columns = [
            'Receipt' => 'receipt_no', 'Date' => 'payment_date', 'Customer' => 'customer_name',
            'Code' => 'customer_code', 'Agreement' => 'agreement_no', 'Method' => 'method',
            'Type' => 'payment_type', 'Status' => 'status', 'Amount' => 'amount',
        ];
        $params[':from'] = $dateFrom;
        $params[':to'] = $dateTo;
        $sql = "SELECT p.receipt_no, p.payment_date, c.full_name AS customer_name, c.customer_code,
                       g.agreement_no, p.method, p.payment_type, p.status, p.amount, p.id
                FROM payments p
                JOIN customers c ON c.id = p.customer_id
                JOIN agreements g ON g.id = p.agreement_id
                WHERE p.payment_date BETWEEN :from AND :to $customerFilter
                ORDER BY p.payment_date DESC, p.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            if ($r['status'] === 'active') {
                $totals['Amount'] = ($totals['Amount'] ?? 0) + (float)$r['amount'];
            }
        }
        break;

    case 'overdue':
        $columns = [
            'Agreement' => 'agreement_no', 'Customer' => 'customer_name', 'Code' => 'customer_code',
            'Mobile' => 'mobile', 'Installment' => 'installment_no', 'Due Date' => 'due_date',
            'Scheduled' => 'scheduled_amount', 'Paid' => 'paid_amount', 'Remaining' => 'remaining_amount',
        ];
        $params[':from'] = $dateFrom;
        $params[':to'] = $dateTo;
        $sql = "SELECT g.agreement_no, c.full_name AS customer_name, c.customer_code, c.mobile,
                       i.installment_no, i.due_date, i.scheduled_amount, i.paid_amount, i.remaining_amount
                FROM installments i
                JOIN agreements g ON g.id = i.agreement_id
                JOIN customers c ON c.id = g.customer_id
                WHERE i.status = 'overdue' AND g.status = 'active'
                  AND i.due_date BETWEEN :from AND :to $customerFilter
                ORDER BY i.due_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $totals['Remaining'] = ($totals['Remaining'] ?? 0) + (float)$r['remaining_amount'];
        }
        break;

    case 'agreements':
        $columns = [
            'Agreement' => 'agreement_no', 'Date' => 'agreement_date', 'Customer' => 'customer_name',
            'Code' => 'customer_code', 'Agreed Price' => 'agreed_sale_price', 'Advance' => 'advance_payment',
            'Financed' => 'financed_amount', 'Remaining' => 'remaining_balance', 'Installments' => 'installment_count',
            'Status' => 'status',
        ];
        $params[':from'] = $dateFrom;
        $params[':to'] = $dateTo;
        $sql = "SELECT g.agreement_no, g.agreement_date, c.full_name AS customer_name, c.customer_code,
                       g.agreed_sale_price, g.advance_payment, g.financed_amount, g.remaining_balance,
                       g.installment_count, g.status
                FROM agreements g
                JOIN customers c ON c.id = g.customer_id
                WHERE g.agreement_date BETWEEN :from AND :to $customerFilter
                ORDER BY g.agreement_date DESC, g.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $totals['Agreed Price'] = ($totals['Agreed Price'] ?? 0) + (float)$r['agreed_sale_price'];
            $totals['Remaining'] = ($totals['Remaining'] ?? 0) + (float)$r['remaining_balance'];
        }
        break;

    case 'outstanding':
    default:
        $columns = [
            'Agreement' => 'agreement_no', 'Customer' => 'customer_name', 'Code' => 'customer_code',
            'Mobile' => 'mobile', 'Agreed Price' => 'agreed_sale_price', 'Advance' => 'advance_payment',
            'Collected' => 'collected', 'Outstanding' => 'remaining_balance', 'Status' => 'status',
        ];
        $sql = "SELECT g.agreement_no, c.full_name AS customer_name, c.customer_code, c.mobile,
                       g.agreed_sale_price, g.advance_payment, g.remaining_balance, g.status,
                       (SELECT COALESCE(SUM(al.amount),0) FROM payment_allocations al
                        JOIN payments p ON p.id = al.payment_id
                        WHERE p.agreement_id = g.id AND p.status = 'active') AS collected
                FROM agreements g
                JOIN customers c ON c.id = g.customer_id
                WHERE g.remaining_balance > 0 AND g.status = 'active' $customerFilter
                ORDER BY g.remaining_balance DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $totals['Collected'] = ($totals['Collected'] ?? 0) + (float)$r['collected'];
            $totals['Outstanding'] = ($totals['Outstanding'] ?? 0) + (float)$r['remaining_balance'];
        }
        break;
}

/* ---------------- CSV export ---------------- */
if ($export === 'csv') {
    $filename = 'report-' . $report . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, array_keys($columns));
    foreach ($rows as $r) {
        $line = [];
        foreach ($columns as $key => $col) {
            $val = $r[$col] ?? '';
            if ($col === 'method') { $val = payment_method_label($val); }
            if ($col === 'payment_type') { $val = payment_type_label($val); }
            if ($col === 'status') { $val = $report === 'collections' ? ucfirst($val) : agreement_status_label($val); }
            $line[] = $val;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    audit_log(current_user_id(), 'export_report', 'report', null, ['report' => $report, 'from' => $dateFrom, 'to' => $dateTo]);
    exit;
}

$customers = $db->query('SELECT id, full_name, customer_code FROM customers ORDER BY full_name')->fetchAll();

$pageTitle = 'Reports';
include __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(base_url('reports/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Report</label>
                <select name="report" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($reports as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $report === $v ? 'selected' : '' ?>><?= e($l) ?></option>
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
            <div class="col-md-3">
                <label class="form-label small mb-1">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">All customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['full_name']) ?> (<?= e($c['customer_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i> Apply</button>
                <a class="btn btn-success btn-sm" href="?<?= e(http_build_query(['report' => $report, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'customer_id' => $customerId ?: null, 'export' => 'csv'])) ?>"><i class="bi bi-download me-1"></i> CSV</a>
            </div>
        </form>
        <?php if (in_array($report, ['collections', 'overdue', 'agreements'], true)): ?>
            <div class="small text-muted mt-2">Period: <?= e(fmt_date($dateFrom)) ?> to <?= e(fmt_date($dateTo)) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><?= e($reports[$report]) ?></h6>
        <span class="text-muted small"><?= count($rows) ?> row(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="bi bi-clipboard-data"></i> No data for the selected filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($columns as $label => $col): ?>
                                <th class="<?= in_array($col, ['amount', 'scheduled_amount', 'paid_amount', 'remaining_amount', 'remaining_balance', 'agreed_sale_price', 'advance_payment', 'financed_amount', 'collected'], true) ? 'text-end' : '' ?>"><?= e($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <?php foreach ($columns as $label => $col): ?>
                                <?php
                                $val = $r[$col] ?? '';
                                $isMoney = in_array($col, ['amount', 'scheduled_amount', 'paid_amount', 'remaining_amount', 'remaining_balance', 'agreed_sale_price', 'advance_payment', 'financed_amount', 'collected'], true);
                                if ($col === 'method') { $val = payment_method_label($val); }
                                elseif ($col === 'payment_type') { $val = payment_type_label($val); }
                                elseif ($col === 'status') { $val = $report === 'collections' ? ucfirst($val) : agreement_status_label($val); }
                                elseif ($col === 'due_date' || $col === 'payment_date' || $col === 'agreement_date') { $val = fmt_date($val); }
                                elseif ($isMoney) { $val = money($val); }
                                ?>
                                <td class="<?= $isMoney ? 'text-end text-nowrap' : '' ?>"><?= e($val) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($totals)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <?php $first = true; foreach ($columns as $label => $col): ?>
                                    <?php if ($first): ?>
                                        <td colspan="1">Totals</td>
                                    <?php else: ?>
                                        <td class="text-end"><?= isset($totals[$label]) ? e(money($totals[$label])) : '' ?></td>
                                    <?php endif; $first = false; ?>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
