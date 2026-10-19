<?php
/**
 * Printable payment receipt (A5/A4, screen-print friendly).
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_payments');

$db = getDB();
$id = (int)get('id', 0);

$stmt = $db->prepare(
    'SELECT p.*, c.full_name AS customer_name, c.customer_code, c.mobile, c.cnic,
            g.agreement_no,
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
    exit('Payment not found.');
}

$allocStmt = $db->prepare(
    'SELECT a.amount, i.installment_no, i.due_date
     FROM payment_allocations a
     LEFT JOIN installments i ON i.id = a.installment_id
     WHERE a.payment_id = ?
     ORDER BY i.installment_no ASC'
);
$allocStmt->execute([$id]);
$allocations = $allocStmt->fetchAll();

$bs = business_settings();
$logoUrl = !empty($bs['logo_path']) ? upload_url($bs['logo_path']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= e($payment['receipt_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?= e(base_url('assets/css/document.css')) ?>?v=1" rel="stylesheet">
</head>
<body>
<div class="document-toolbar no-print">
    <button class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    <button class="btn btn-outline-light btn-sm" onclick="window.close()">Close</button>
</div>

<div class="document receipt-doc">
    <div class="doc-header">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo" class="doc-logo"><?php else: ?><span class="doc-logo-mark brand-initials"><?= e(shop_initials($bs['shop_name'] ?? null)) ?></span><?php endif; ?>
        <div class="doc-header-text">
            <h1 class="doc-shop-name"><?= e($bs['shop_name'] ?? APP_NAME) ?></h1>
            <p class="doc-shop-meta">
                <?php
                echo e(implode(' · ', array_filter([
                    $bs['address'] ?? '',
                    $bs['city'] ?? '',
                    $bs['phone'] ? 'Tel: ' . $bs['phone'] : '',
                    $bs['email'] ?? '',
                ])));
                ?>
            </p>
        </div>
    </div>

    <div class="doc-title">Payment Receipt</div>

    <?php if ($payment['status'] === 'void'): ?>
        <div class="alert alert-danger text-center fw-bold">VOIDED<?= $payment['void_reason'] ? ' - ' . e($payment['void_reason']) : '' ?></div>
    <?php endif; ?>

    <table class="doc-table doc-kv">
        <tr>
            <td class="k">Receipt No.</td><td><strong><?= e($payment['receipt_no']) ?></strong></td>
            <td class="k">Date</td><td><?= e(fmt_date($payment['payment_date'])) ?></td>
        </tr>
        <tr>
            <td class="k">Customer</td><td><?= e($payment['customer_name']) ?> (<?= e($payment['customer_code']) ?>)</td>
            <td class="k">Mobile</td><td><?= e($payment['mobile'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="k">Agreement No.</td><td><?= e($payment['agreement_no']) ?></td>
            <td class="k">Method</td><td><?= e(payment_method_label($payment['method'])) ?></td>
        </tr>
        <?php if ($payment['transaction_ref']): ?>
        <tr>
            <td class="k">Reference</td><td colspan="3"><?= e($payment['transaction_ref']) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="doc-table">
        <tr>
            <td class="k" style="width:40%">Amount Received</td>
            <td class="doc-right" style="font-size:14pt;font-weight:700;"><?= e(currency()) ?> <?= e(money($payment['amount'])) ?></td>
        </tr>
        <tr>
            <td class="k">In words</td>
            <td><?= e(amount_in_words($payment['amount'])) ?></td>
        </tr>
        <tr>
            <td class="k">Type</td>
            <td><?= e(payment_type_label($payment['payment_type'])) ?></td>
        </tr>
        <tr>
            <td class="k">Previous Balance</td>
            <td class="doc-right doc-mono"><?= e(money($payment['prev_balance'])) ?></td>
        </tr>
        <tr>
            <td class="k"><strong>Remaining Balance</strong></td>
            <td class="doc-right doc-mono"><strong><?= e(money($payment['new_balance'])) ?></strong></td>
        </tr>
    </table>

    <?php if (!empty($allocations)): ?>
        <div class="doc-section-title">Applied To</div>
        <table class="doc-table">
            <thead><tr><th>Installment #</th><th>Due Date</th><th class="doc-right">Amount Applied</th></tr></thead>
            <tbody>
            <?php foreach ($allocations as $a): ?>
                <tr>
                    <td>#<?= (int)$a['installment_no'] ?></td>
                    <td><?= e(fmt_date($a['due_date'])) ?></td>
                    <td class="doc-right doc-mono"><?= e(money($a['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($payment['notes']): ?>
        <p class="doc-footer-note"><strong>Notes:</strong> <?= e($payment['notes']) ?></p>
    <?php endif; ?>

    <div class="sig-grid" style="margin-top:26px;">
        <div class="sig-box">
            <div class="sig-line">Received by: <?= e($payment['received_by_name'] ?: 'Authorized') ?></div>
        </div>
        <div class="sig-box">
            <div class="sig-line">Customer Signature</div>
        </div>
    </div>

    <p class="doc-footer-note text-center" style="margin-top:14px;">
        This is a computer-generated receipt. Thank you for your payment.
    </p>
</div>
</body>
</html>
