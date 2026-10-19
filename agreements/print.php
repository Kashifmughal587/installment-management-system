<?php
/**
 * Printable A4 installment agreement.
 * Uses browser Print → Save as PDF (no shell/exec required).
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_agreements');

$db = getDB();
$id = (int)get('id', 0);
$stmt = $db->prepare('SELECT g.*, c.full_name AS customer_name, c.customer_code, c.mobile, c.cnic,
                             c.guardian_name, c.guardian_relation, c.current_address, c.permanent_address,
                             c.city, c.district
                      FROM agreements g JOIN customers c ON c.id = g.customer_id WHERE g.id = ?');
$stmt->execute([$id]);
$agreement = $stmt->fetch();
if (!$agreement) {
    http_response_code(404);
    exit('Agreement not found.');
}

$stmt = $db->prepare('SELECT * FROM agreement_products WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$products = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM guarantors WHERE agreement_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$guarantors = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
$stmt->execute([$id]);
$installments = $stmt->fetchAll();

$bs = business_settings();
$lang = $bs['document_language'] ?? 'en';
$dir = ($lang === 'ur') ? 'rtl' : 'ltr';
$logoUrl = !empty($bs['logo_path']) ? upload_url($bs['logo_path']) : '';
$sigUrl  = !empty($bs['signature_path']) ? upload_url($bs['signature_path']) : '';
$currency = $bs['currency'] ?? 'PKR';
$terms = $agreement['terms'] ?: ($bs['default_terms'] ?? '');
$fontFile = base_url('assets/fonts/' . URDU_FONT_FILE);
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agreement <?= e($agreement['agreement_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?= e(base_url('assets/css/document.css')) ?>?v=1" rel="stylesheet">
    <link href="<?= e(base_url('assets/css/document-fonts.css')) ?>?v=1" rel="stylesheet">
    <style>
        @font-face {
            font-family: "Jameel Noori Nastaleeq";
            src: url("<?= e($fontFile) ?>") format("truetype");
            font-display: swap;
        }
    </style>
</head>
<body>
<div class="document-toolbar no-print">
    <button class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    <button class="btn btn-outline-light btn-sm" onclick="window.close()">Close</button>
</div>

<div class="document">
    <!-- Header -->
    <div class="doc-header">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo" class="doc-logo"><?php else: ?><span class="doc-logo-mark brand-initials"><?= e(shop_initials($bs['shop_name'] ?? null)) ?></span><?php endif; ?>
        <div class="doc-header-text">
            <h1 class="doc-shop-name"><?= e($bs['shop_name'] ?? APP_NAME) ?></h1>
            <p class="doc-shop-meta">
                <?php
                $meta = array_filter([
                    $bs['address'] ?? '',
                    trim(($bs['city'] ?? '') . ($bs['district'] ? ', ' . $bs['district'] : '')),
                    $bs['phone'] ? 'Tel: ' . $bs['phone'] : '',
                    $bs['whatsapp'] ? 'WhatsApp: ' . $bs['whatsapp'] : '',
                    $bs['email'] ?? '',
                ]);
                echo e(implode(' · ', $meta));
                ?>
            </p>
            <?php if (!empty($bs['cnic'])): ?><p class="doc-shop-meta">Owner CNIC: <?= e($bs['cnic']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="doc-title">Installment Sale Agreement</div>

    <table class="doc-table">
        <tr>
            <td width="25%"><strong>Agreement No.</strong></td>
            <td width="25%"><?= e($agreement['agreement_no']) ?></td>
            <td width="25%"><strong>Agreement Date</strong></td>
            <td width="25%"><?= e(fmt_date($agreement['agreement_date'])) ?></td>
        </tr>
        <tr>
            <td><strong>Status</strong></td>
            <td><?= e(agreement_status_label($agreement['status'])) ?></td>
            <td><strong>Currency</strong></td>
            <td><?= e($currency) ?></td>
        </tr>
    </table>

    <?php if (!empty($bs['agreement_header'])): ?>
        <p class="doc-terms"><?= nl2br(e($bs['agreement_header'])) ?></p>
    <?php endif; ?>

    <!-- Owner & Customer -->
    <div class="doc-section-title">Seller / Business</div>
    <table class="doc-table doc-kv">
        <tr><td class="k">Business Name</td><td><?= e($bs['shop_name'] ?? '') ?></td>
            <td class="k">Owner</td><td><?= e($bs['owner_name'] ?? '') ?></td></tr>
        <tr><td class="k">Contact</td><td><?= e(trim(($bs['phone'] ?? '') . ' ' . ($bs['whatsapp'] ?? ''))) ?></td>
            <td class="k">Address</td><td><?= e(trim(($bs['address'] ?? '') . ' ' . ($bs['city'] ?? ''))) ?></td></tr>
    </table>

    <div class="doc-section-title">Customer Details</div>
    <table class="doc-table doc-kv">
        <tr><td class="k">Name</td><td><?= e($agreement['customer_name']) ?></td>
            <td class="k">Customer Code</td><td><?= e($agreement['customer_code']) ?></td></tr>
        <tr><td class="k"><?= $agreement['guardian_relation'] === 'Husband' ? "Husband's" : "Father's" ?> Name</td><td><?= e($agreement['guardian_name'] ?: '-') ?></td>
            <td class="k">CNIC</td><td><?= e($agreement['cnic'] ?: '-') ?></td></tr>
        <tr><td class="k">Mobile</td><td><?= e($agreement['mobile'] ?: '-') ?></td>
            <td class="k">City / District</td><td><?= e(trim(($agreement['city'] ?: '') . ' / ' . ($agreement['district'] ?: ''))) ?></td></tr>
        <tr><td class="k">Address</td><td colspan="3"><?= e($agreement['current_address'] ?: '-') ?></td></tr>
    </table>

    <?php if (!empty($guarantors)): ?>
        <div class="doc-section-title">Guarantor Details</div>
        <table class="doc-table">
            <thead><tr><th>Name</th><th>Father's Name</th><th>CNIC</th><th>Mobile</th><th>Address</th><th>Relationship</th></tr></thead>
            <tbody>
            <?php foreach ($guarantors as $g): ?>
                <tr>
                    <td><?= e($g['full_name']) ?></td>
                    <td><?= e($g['father_name'] ?: '-') ?></td>
                    <td><?= e($g['cnic'] ?: '-') ?></td>
                    <td><?= e($g['mobile'] ?: '-') ?></td>
                    <td><?= e($g['address'] ?: '-') ?></td>
                    <td><?= e($g['relationship'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Products -->
    <div class="doc-section-title">Products</div>
    <table class="doc-table">
        <thead>
            <tr><th style="width:36px">#</th><th>Product</th><th>Brand / Model</th><th>Serial / IMEI</th><th style="width:50px">Qty</th><th style="width:90px" class="doc-right">Cash Price</th><th style="width:95px" class="doc-right">Line Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $i => $p): ?>
            <tr>
                <td><?= (int)($i + 1) ?></td>
                <td><?= e($p['product_name']) ?><?= $p['category'] ? ' (' . e($p['category']) . ')' : '' ?></td>
                <td><?= e(trim(($p['brand'] ?: '') . ' ' . ($p['model'] ?: '')) ?: '-') ?></td>
                <td><?= e($p['serial_no'] ?: '-') ?></td>
                <td class="doc-center"><?= e(rtrim(rtrim(number_format((float)$p['quantity'], 2), '0'), '.')) ?></td>
                <td class="doc-right doc-mono"><?= e(money($p['unit_cash_price'])) ?></td>
                <td class="doc-right doc-mono"><?= e(money($p['line_total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Financial summary -->
    <div class="doc-section-title">Financial Summary</div>
    <table class="doc-table doc-kv">
        <tr><td class="k">Agreed Installment Sale Price</td><td class="doc-right doc-mono"><?= e($currency . ' ' . money($agreement['agreed_sale_price'])) ?></td>
            <td class="k">Additional Charges</td><td class="doc-right doc-mono"><?= e(money($agreement['additional_charges'])) ?></td></tr>
        <tr><td class="k">Discount</td><td class="doc-right doc-mono"><?= e(money($agreement['discount'])) ?></td>
            <td class="k">Advance Payment</td><td class="doc-right doc-mono"><?= e(money($agreement['advance_payment'])) ?></td></tr>
        <tr><td class="k">Financed Amount</td><td class="doc-right doc-mono"><?= e(money($agreement['financed_amount'])) ?></td>
            <td class="k">Number of Installments</td><td><?= (int)$agreement['installment_count'] ?></td></tr>
        <tr><td class="k">Frequency</td><td><?= e(ucfirst($agreement['frequency'])) ?></td>
            <td class="k">Installment Amount</td><td class="doc-right doc-mono"><?= e(money($agreement['installment_amount'])) ?></td></tr>
        <tr><td class="k">First Due Date</td><td><?= e(fmt_date($agreement['first_due_date']) ?: '-') ?></td>
            <td class="k"><strong>Remaining Balance</strong></td><td class="doc-right doc-mono"><strong><?= e($currency . ' ' . money($agreement['remaining_balance'])) ?></strong></td></tr>
        <tr><td class="k">Payment Method</td><td><?= e($agreement['payment_method_pref'] ? payment_method_label($agreement['payment_method_pref']) : '-') ?></td>
            <td class="k"></td><td></td></tr>
    </table>

    <!-- Schedule -->
    <div class="doc-section-title">Installment Payment Schedule</div>
    <table class="doc-table">
        <thead>
            <tr><th style="width:60px">Installment #</th><th>Due Date</th><th class="doc-right">Scheduled Amount</th><th class="doc-right">Paid</th><th class="doc-right">Remaining</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($installments as $inst): ?>
            <tr>
                <td class="doc-center"><?= (int)$inst['installment_no'] ?></td>
                <td><?= e(fmt_date($inst['due_date'])) ?></td>
                <td class="doc-right doc-mono"><?= e(money($inst['scheduled_amount'])) ?></td>
                <td class="doc-right doc-mono"><?= e(money($inst['paid_amount'])) ?></td>
                <td class="doc-right doc-mono"><?= e(money($inst['remaining_amount'])) ?></td>
                <td><?= e(installment_status_label($inst['status'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Terms -->
    <?php if ($terms): ?>
        <div class="doc-section-title">Terms &amp; Conditions</div>
        <div class="doc-terms"><?= e($terms) ?></div>
    <?php endif; ?>

    <?php if (!empty($bs['agreement_footer'])): ?>
        <p class="doc-footer-note"><?= nl2br(e($bs['agreement_footer'])) ?></p>
    <?php endif; ?>

    <!-- Acceptance & signatures -->
    <div class="avoid-break">
        <p style="margin-top:14px;font-size:9.5pt;">
            I, <strong><?= e($agreement['customer_name']) ?></strong>, confirm that I have read and understood the terms of this
            agreement, have received the product(s) listed above, and agree to pay the scheduled installments on or before their due dates.
            Document date: <strong><?= e(fmt_date($agreement['agreement_date'])) ?></strong>.
        </p>
        <div class="sig-grid">
            <div class="sig-box">
                <?php if ($sigUrl): ?><img src="<?= e($sigUrl) ?>" alt="signature" class="sig-img"><?php endif; ?>
                <div class="sig-line">Owner / Authorized Representative</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">Customer Signature<br><?= e($agreement['customer_name']) ?></div>
            </div>
            <?php if (!empty($guarantors)): ?>
                <div class="sig-box">
                    <div class="sig-line">Guarantor Signature<br><?= e($guarantors[0]['full_name']) ?></div>
                </div>
            <?php endif; ?>
            <div class="sig-box">
                <div class="sig-line">Witness 1</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">Witness 2</div>
            </div>
        </div>
    </div>

    <div class="doc-page-number" style="margin-top:16px;">
        <?= e($bs['shop_name'] ?? '') ?> - Agreement <?= e($agreement['agreement_no']) ?>
    </div>
</div>
</body>
</html>
