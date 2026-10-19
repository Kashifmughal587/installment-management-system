<?php
/**
 * Agreement form partial (used by add.php).
 * Expects: $formAction, $agreement (assoc), $products (array), $guarantors (array),
 *          $errors (array), $mode, $customers (array)
 */
$v = function (string $key, $default = '') use ($agreement) {
    return e($agreement[$key] ?? $default);
};
if (empty($products)) {
    $products = [['product_name' => '', 'category' => '', 'brand' => '', 'model' => '', 'serial_no' => '', 'quantity' => 1, 'unit_cash_price' => '', 'notes' => '']];
}
if (empty($guarantors)) {
    $guarantors = [];
}
$useCustom = !empty($agreement['use_custom_schedule']);
?>
<style>
.prod-row input, .prod-row textarea { font-size: .85rem; }
.guar-row input { font-size: .85rem; }
.schedule-preview { max-height: 340px; overflow: auto; }
</style>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="<?= e($formAction) ?>" id="agreementForm" novalidate data-cnic-validate>
    <?= csrf_field() ?>
    <?php if (!empty($agreement['id'])): ?><input type="hidden" name="id" value="<?= (int)$agreement['id'] ?>"><?php endif; ?>
    <input type="hidden" name="use_custom_schedule" id="useCustomSchedule" value="<?= $useCustom ? '1' : '0' ?>">

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-file-earmark-text me-1"></i> Agreement Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Agreement Number</label>
                    <input type="text" class="form-control" value="<?= $v('agreement_no', $mode === 'add' ? 'Auto-generated' : '') ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Agreement Date <span class="text-danger">*</span></label>
                    <input type="date" name="agreement_date" class="form-control" required value="<?= $v('agreement_date', date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="draft" <?= ($agreement['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="active" <?= ($agreement['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Customer <span class="text-danger">*</span></label>
                    <select name="customer_id" class="form-select" required>
                        <option value="">- Select customer -</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($agreement['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['customer_code'] . ' - ' . $c['full_name'] . ($c['mobile'] ? ' (' . $c['mobile'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (can('manage_customers')): ?>
                        <div class="form-text">Customer missing? <a href="<?= e(base_url('customers/add.php')) ?>" target="_blank">Add a new customer</a>.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam me-1"></i> Products</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addProductBtn"><i class="bi bi-plus-lg"></i> Add Product</button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" id="productsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:150px">Product Name *</th>
                            <th>Category</th><th>Brand</th><th>Model</th><th>Serial / IMEI</th>
                            <th style="width:80px">Qty</th>
                            <th style="width:130px">Unit Cash Price</th>
                            <th style="width:120px" class="text-end">Line Total</th>
                            <th>Notes</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="productsBody">
                        <?php foreach ($products as $p): ?>
                        <tr class="prod-row">
                            <td><input type="text" name="product_name[]" class="form-control" value="<?= e($p['product_name']) ?>"></td>
                            <td><input type="text" name="category[]" class="form-control" value="<?= e($p['category']) ?>"></td>
                            <td><input type="text" name="brand[]" class="form-control" value="<?= e($p['brand']) ?>"></td>
                            <td><input type="text" name="model[]" class="form-control" value="<?= e($p['model']) ?>"></td>
                            <td><input type="text" name="serial_no[]" class="form-control" value="<?= e($p['serial_no']) ?>"></td>
                            <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control qty" value="<?= e($p['quantity']) ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="unit_cash_price[]" class="form-control unit" value="<?= e($p['unit_cash_price']) ?>"></td>
                            <td class="text-end line-total">0.00</td>
                            <td><input type="text" name="product_notes[]" class="form-control" value="<?= e($p['notes']) ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-product"><i class="bi bi-x-lg"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-calculator me-1"></i> Price &amp; Payment Terms</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Agreed Installment Sale Price <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" name="agreed_sale_price" id="agreedPrice" class="form-control" required value="<?= $v('agreed_sale_price') ?>">
                    <div class="form-text">Base installment price.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Additional Charges</label>
                    <input type="number" step="0.01" min="0" name="additional_charges" id="addCharges" class="form-control" value="<?= $v('additional_charges', '0.00') ?>">
                    <div class="form-text">Added to the financed amount.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Discount</label>
                    <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="<?= $v('discount', '0.00') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Advance / Down Payment</label>
                    <input type="number" step="0.01" min="0" name="advance_payment" id="advance" class="form-control" value="<?= $v('advance_payment', '0.00') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Financed Amount</label>
                    <input type="text" id="financedDisplay" class="form-control" value="0.00" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Number of Installments</label>
                    <input type="number" min="1" max="360" name="installment_count" id="installmentCount" class="form-control" value="<?= $v('installment_count', '12') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Frequency</label>
                    <select name="frequency" id="frequency" class="form-select">
                        <?php foreach (['monthly' => 'Monthly', 'weekly' => 'Weekly', 'biweekly' => 'Every 2 Weeks'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>" <?= ($agreement['frequency'] ?? 'monthly') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Due Date</label>
                    <input type="date" name="first_due_date" id="firstDue" class="form-control" value="<?= $v('first_due_date') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monthly Due Day</label>
                    <input type="number" min="1" max="31" name="due_day" id="dueDay" class="form-control" value="<?= $v('due_day', (int)setting('default_due_day', 5)) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Payment Method Preference</label>
                    <select name="payment_method_pref" class="form-select">
                        <option value="">- Any -</option>
                        <?php foreach (['cash', 'bank_transfer', 'mobile_wallet', 'cheque'] as $m): ?>
                            <option value="<?= e($m) ?>" <?= ($agreement['payment_method_pref'] ?? '') === $m ? 'selected' : '' ?>><?= e(payment_method_label($m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar3 me-1"></i> Installment Schedule Preview</span>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="customScheduleToggle" <?= $useCustom ? 'checked' : '' ?>>
                <label class="form-check-label small" for="customScheduleToggle">Use custom schedule</label>
            </div>
        </div>
        <div class="card-body">
            <div id="equalScheduleArea">
                <div class="schedule-preview table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th style="width:70px">#</th><th>Due Date</th><th class="text-end">Amount</th></tr></thead>
                        <tbody id="schedulePreview"></tbody>
                        <tfoot><tr><th colspan="2" class="text-end">Total</th><th class="text-end" id="scheduleTotal">0.00</th></tr></tfoot>
                    </table>
                </div>
                <div class="form-text">Amounts are rounded to 2 decimals; the final installment absorbs any rounding difference.</div>
            </div>

            <div id="customScheduleArea" class="d-none">
                <div class="d-flex justify-content-between mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="generateFromEqual">Copy from equal schedule</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addScheduleRow"><i class="bi bi-plus-lg"></i> Add Row</button>
                </div>
                <div class="schedule-preview table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th style="width:70px">#</th><th>Due Date</th><th class="text-end">Amount</th><th></th></tr></thead>
                        <tbody id="customScheduleBody">
                        <?php if (!empty($customSchedule)): foreach ($customSchedule as $i => $row): ?>
                            <tr>
                                <td class="row-no"><?= (int)($i + 1) ?></td>
                                <td><input type="date" name="schedule_due_date[]" class="form-control form-control-sm" value="<?= e($row['due_date']) ?>"></td>
                                <td><input type="number" step="0.01" min="0" name="schedule_amount[]" class="form-control form-control-sm sched-amt text-end" value="<?= e($row['amount']) ?>"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-schedule"><i class="bi bi-x-lg"></i></button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot><tr><th colspan="2" class="text-end">Total</th><th class="text-end" id="customScheduleTotal">0.00</th><th></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-shield-check me-1"></i> Guarantors <span class="text-muted small">(optional)</span></span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addGuarantorBtn"><i class="bi bi-plus-lg"></i> Add Guarantor</button>
        </div>
        <div class="card-body">
            <div id="guarantorEmpty" class="text-muted small">No guarantors added. Guarantors are optional unless you require one for this agreement.</div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 d-none" id="guarantorsTable">
                    <thead class="table-light">
                        <tr><th style="min-width:150px">Full Name</th><th>Father's Name</th><th>CNIC</th><th>Mobile</th><th>Address</th><th>Relationship</th><th></th></tr>
                    </thead>
                    <tbody id="guarantorsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-journal-text me-1"></i> Terms &amp; Notes</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Special Terms &amp; Conditions</label>
                <textarea name="terms" rows="5" class="form-control"><?= $v('terms', setting('default_terms', '')) ?></textarea>
                <div class="form-text">Leave as-is to use the default business terms.</div>
            </div>
            <div>
                <label class="form-label">Internal Notes</label>
                <textarea name="notes" rows="2" class="form-control"><?= $v('notes') ?></textarea>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $mode === 'add' ? 'Save Agreement' : 'Update Agreement' ?></button>
        <a href="<?= e(base_url('agreements/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<template id="productRowTemplate">
    <tr class="prod-row">
        <td><input type="text" name="product_name[]" class="form-control"></td>
        <td><input type="text" name="category[]" class="form-control"></td>
        <td><input type="text" name="brand[]" class="form-control"></td>
        <td><input type="text" name="model[]" class="form-control"></td>
        <td><input type="text" name="serial_no[]" class="form-control"></td>
        <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control qty" value="1"></td>
        <td><input type="number" step="0.01" min="0" name="unit_cash_price[]" class="form-control unit" value="0.00"></td>
        <td class="text-end line-total">0.00</td>
        <td><input type="text" name="product_notes[]" class="form-control"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-product"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>

<template id="guarantorRowTemplate">
    <tr class="guar-row">
        <td><input type="text" name="g_name[]" class="form-control"></td>
        <td><input type="text" name="g_father[]" class="form-control"></td>
        <td><input type="text" name="g_cnic[]" class="form-control" maxlength="15" inputmode="numeric" placeholder="35202-1234567-1" data-cnic-mask></td>
        <td><input type="text" name="g_mobile[]" class="form-control"></td>
        <td><input type="text" name="g_address[]" class="form-control"></td>
        <td><input type="text" name="g_relationship[]" class="form-control"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-guarantor"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>

<template id="scheduleRowTemplate">
    <tr>
        <td class="row-no"></td>
        <td><input type="date" name="schedule_due_date[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="schedule_amount[]" class="form-control form-control-sm sched-amt text-end" value="0.00"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-schedule"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>
