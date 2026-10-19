<?php
/**
 * Record a payment against an agreement.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment.php';
require_permission('record_payments');

$db = getDB();

// Open agreements (active, with outstanding balance) for the selectors.
$agreements = $db->query(
    "SELECT g.id, g.agreement_no, g.customer_id, g.remaining_balance, g.status,
            c.full_name AS customer_name, c.customer_code
     FROM agreements g
     JOIN customers c ON c.id = g.customer_id
     WHERE g.status = 'active' AND g.remaining_balance > 0
     ORDER BY g.agreement_no DESC"
)->fetchAll();

$customerMap = [];
foreach ($agreements as $a) {
    $customerMap[(int)$a['id']] = (int)$a['customer_id'];
}

// Installments for the above agreements (for optional manual selection).
$instByAgreement = [];
if ($agreements) {
    $ids = array_map(fn($a) => (int)$a['id'], $agreements);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, agreement_id, installment_no, due_date, remaining_amount, status
                          FROM installments
                          WHERE agreement_id IN ($in) AND remaining_amount > 0
                          ORDER BY agreement_id, installment_no");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $instByAgreement[(int)$row['agreement_id']][] = $row;
    }
}

$errors  = [];
$old = [
    'customer_id'    => (int)get('customer_id', 0),
    'agreement_id'   => (int)get('agreement_id', 0),
    'installment_id' => 0,
    'payment_target' => 'auto',
    'payment_date'   => date('Y-m-d'),
    'amount'         => '',
    'payment_type'   => 'installment',
    'method'         => 'cash',
    'transaction_ref'=> '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old = [
        'customer_id'    => post_int('customer_id'),
        'agreement_id'   => post_int('agreement_id'),
        'installment_id' => post_int('installment_id'),
        'payment_target' => post('payment_target', 'auto'),
        'payment_date'   => post('payment_date'),
        'amount'         => post('amount'),
        'payment_type'   => post('payment_type', 'installment'),
        'method'         => post('method', 'cash'),
        'transaction_ref'=> post('transaction_ref'),
        'notes'          => post('notes'),
    ];

    if (!isset($customerMap[$old['agreement_id']])) {
        $errors['agreement_id'] = 'Please choose a valid open agreement.';
    } elseif ($customerMap[$old['agreement_id']] !== $old['customer_id']) {
        $errors['agreement_id'] = 'The selected agreement does not belong to the selected customer.';
    }
    if ($old['payment_date'] === '' || !strtotime($old['payment_date'])) {
        $errors['payment_date'] = 'Please enter a valid payment date.';
    }
    $amount = round_money(str_replace(',', '', (string)$old['amount']));
    if ($amount <= 0) {
        $errors['amount'] = 'Please enter an amount greater than zero.';
    } elseif (!isset($errors['agreement_id'])) {
        $remaining = (float)$db->query('SELECT remaining_balance FROM agreements WHERE id = ' . (int)$old['agreement_id'])->fetchColumn();
        if ($amount > $remaining + 0.01) {
            $errors['amount'] = 'Amount exceeds the outstanding balance of ' . money($remaining) . '.';
        }
    }
    if (!in_array($old['payment_type'], ['advance', 'installment', 'other'], true)) {
        $old['payment_type'] = 'installment';
    }
    if (!in_array($old['method'], ['cash', 'bank_transfer', 'mobile_wallet', 'cheque', 'other'], true)) {
        $old['method'] = 'cash';
    }

    // Attachment (optional).
    $attachmentPath = null;
    if (empty($errors) && !empty($_FILES['attachment']['name'])) {
        $up = handle_upload('attachment', 'payments');
        if (!$up['ok']) {
            $errors['attachment'] = $up['error'];
        } else {
            $attachmentPath = $up['path'];
        }
    }

    if (empty($errors) && payment_is_duplicate($db, $old['agreement_id'], $amount, $old['payment_date'])) {
        $errors['amount'] = 'A similar payment was just recorded. Please confirm this is not a duplicate.';
    }

    if (empty($errors)) {
        $result = payment_apply($db, [
            'customer_id'     => $old['customer_id'],
            'agreement_id'    => $old['agreement_id'],
            'installment_id'  => $old['installment_id'] ?: null,
            'payment_date'    => $old['payment_date'],
            'amount'          => $amount,
            'payment_type'    => $old['payment_type'],
            'method'          => $old['method'],
            'transaction_ref' => $old['transaction_ref'],
            'notes'           => $old['notes'],
            'attachment_path' => $attachmentPath,
            'received_by'     => current_user_id(),
        ], current_user_id());

        if ($result['ok']) {
            audit_log(current_user_id(), 'record_payment', 'payment', $result['id'], [
                'agreement_id' => $old['agreement_id'],
                'amount' => $amount,
            ]);
            flash_set('success', 'Payment recorded successfully.');
            redirect('payments/view.php?id=' . $result['id']);
        }
        $errors['general'] = $result['error'];
    }
}

$pageTitle = 'Record Payment';
$pageActions = '<a href="' . e(base_url('payments/index.php')) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>';
include __DIR__ . '/../includes/header.php';
?>
<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= e($errors['general']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= e(base_url('payments/add.php')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" id="customer_id" class="form-select <?= isset($errors['customer_id']) ? 'is-invalid' : '' ?>" required>
                            <option value="">- Select customer -</option>
                            <?php
                            $seenCustomers = [];
                            foreach ($agreements as $a):
                                $cid = (int)$a['customer_id'];
                                if (isset($seenCustomers[$cid])) { continue; }
                                $seenCustomers[$cid] = true;
                            ?>
                                <option value="<?= $cid ?>" <?= $old['customer_id'] === $cid ? 'selected' : '' ?>>
                                    <?= e($a['customer_name']) ?> (<?= e($a['customer_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['customer_id'])): ?><div class="invalid-feedback"><?= e($errors['customer_id']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Agreement <span class="text-danger">*</span></label>
                        <select name="agreement_id" id="agreement_id" class="form-select <?= isset($errors['agreement_id']) ? 'is-invalid' : '' ?>" required>
                            <option value="">- Select agreement -</option>
                            <?php foreach ($agreements as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" data-customer="<?= (int)$a['customer_id'] ?>"
                                        data-balance="<?= e(number_format((float)$a['remaining_balance'], 2, '.', '')) ?>"
                                        <?= $old['agreement_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['agreement_no']) ?> - <?= e($a['customer_name']) ?> (Bal: <?= e(money($a['remaining_balance'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="balanceHint"></div>
                        <?php if (isset($errors['agreement_id'])): ?><div class="invalid-feedback"><?= e($errors['agreement_id']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3" id="installmentWrap" style="display:none;">
                        <label class="form-label d-block">Apply payment to</label>
                        <div id="paymentTargets" class="payment-targets border rounded p-2" style="max-height:260px;overflow:auto;"></div>
                        <input type="hidden" name="installment_id" id="installment_id" value="">
                        <div class="form-text">Auto applies to the oldest unpaid installment. Choose a specific installment, or "Custom amount" to enter any amount (still applied oldest-first).</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control <?= isset($errors['payment_date']) ? 'is-invalid' : '' ?>" value="<?= e($old['payment_date']) ?>" required>
                            <?php if (isset($errors['payment_date'])): ?><div class="invalid-feedback"><?= e($errors['payment_date']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= e(currency()) ?></span>
                                <input type="text" inputmode="decimal" name="amount" id="amountInput" class="form-control <?= isset($errors['amount']) ? 'is-invalid' : '' ?>" value="<?= e($old['amount']) ?>" placeholder="0.00" required>
                                <?php if (isset($errors['amount'])): ?><div class="invalid-feedback"><?= e($errors['amount']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Payment Type</label>
                            <select name="payment_type" class="form-select">
                                <?php foreach (['installment' => 'Installment', 'advance' => 'Advance', 'other' => 'Other'] as $v => $l): ?>
                                    <option value="<?= e($v) ?>" <?= $old['payment_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Method</label>
                            <select name="method" class="form-select">
                                <?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mobile_wallet' => 'Mobile Wallet', 'cheque' => 'Cheque', 'other' => 'Other'] as $v => $l): ?>
                                    <option value="<?= e($v) ?>" <?= $old['method'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">Transaction / Reference No.</label>
                        <input type="text" name="transaction_ref" class="form-control" value="<?= e($old['transaction_ref']) ?>" maxlength="190" placeholder="Cheque no., bank ref, wallet TID…">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"><?= e($old['notes']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Attachment <span class="text-muted">(optional, image/PDF)</span></label>
                        <input type="file" name="attachment" class="form-control <?= isset($errors['attachment']) ? 'is-invalid' : '' ?>" accept=".jpg,.jpeg,.png,.webp,.pdf">
                        <?php if (isset($errors['attachment'])): ?><div class="invalid-feedback"><?= e($errors['attachment']) ?></div><?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Payment</button>
                        <a href="<?= e(base_url('payments/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i> Guidance</h6></div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li>Choose the customer first, then the open agreement.</li>
                    <li>The receipt number is generated automatically.</li>
                    <li>Pick "Auto" (oldest unpaid installment), a specific installment, or "Custom amount".</li>
                    <li>The amount prefills with the chosen installment's balance; "Custom amount" lets you enter any amount.</li>
                    <li>By default the amount is applied to the earliest unpaid installments.</li>
                    <li>The customer's remaining balance updates immediately.</li>
                    <li>Voiding a payment is restricted to owners and is fully audit-logged.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$balJson = [];
foreach ($agreements as $a) { $balJson[(int)$a['id']] = (float)$a['remaining_balance']; }
$instJson = [];
foreach ($instByAgreement as $agId => $rows) {
    foreach ($rows as $r) {
        $instJson[(int)$agId][] = [
            'id'      => (int)$r['id'],
            'no'      => (int)$r['installment_no'],
            'due'     => fmt_date($r['due_date']),
            'balance' => (float)$r['remaining_amount'],
        ];
    }
}
$paymentData = [
    'balances'     => $balJson,
    'installments' => $instJson,
    'old'          => [
        'target'      => (string)($old['payment_target'] ?? 'auto'),
        'installment' => (int)$old['installment_id'],
        'amount'      => (string)$old['amount'],
    ],
];
$pageScripts = '<script>window.PAYMENT_DATA=' . json_encode($paymentData) . ';</script>'
    . '<script src="' . e(asset('js/payments.js')) . '?v=3"></script>';
include __DIR__ . '/../includes/footer.php';
?>
