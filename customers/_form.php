<?php
/**
 * Customer form partial.
 * Expects: $formAction, $customer (assoc), $errors (array), $mode ('add'|'edit')
 */
$v = function (string $key, $default = '') use ($customer) {
    return e($customer[$key] ?? $default);
};
$photoUrl = !empty($customer['photo_path']) ? upload_url($customer['photo_path']) : '';
$frontUrl = !empty($customer['cnic_front_path']) ? upload_url($customer['cnic_front_path']) : '';
$backUrl  = !empty($customer['cnic_back_path']) ? upload_url($customer['cnic_back_path']) : '';
?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" action="<?= e($formAction) ?>" enctype="multipart/form-data" novalidate id="customerForm" data-cnic-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <?php if (!empty($customer['id'])): ?>
        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
    <?php endif; ?>
    <?php if (!empty($confirmDuplicate)): ?>
        <input type="hidden" name="confirm_duplicate" value="1">
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-person me-1"></i> Personal Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Customer Code</label>
                    <input type="text" class="form-control" value="<?= $v('customer_code', $mode === 'add' ? 'Auto-generated' : '') ?>" disabled>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Customer Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required maxlength="190" value="<?= $v('full_name') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Father's / Husband's Name</label>
                    <input type="text" name="guardian_name" class="form-control" maxlength="190" value="<?= $v('guardian_name') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Relationship</label>
                    <select name="guardian_relation" class="form-select">
                        <option value="">- Select -</option>
                        <?php foreach (['Father', 'Husband', 'Brother', 'Guardian', 'Other'] as $rel): ?>
                            <option value="<?= e($rel) ?>" <?= ($customer['guardian_relation'] ?? '') === $rel ? 'selected' : '' ?>><?= e($rel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">CNIC Number <span class="text-danger">*</span></label>
                    <input type="text" name="cnic" id="cnicInput" class="form-control" required maxlength="15" inputmode="numeric" placeholder="35202-1234567-1" value="<?= $v('cnic') ?>" data-cnic-mask>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control" value="<?= $v('dob') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">- Select -</option>
                        <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $g => $lbl): ?>
                            <option value="<?= e($g) ?>" <?= ($customer['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Occupation</label>
                    <input type="text" name="occupation" class="form-control" maxlength="120" value="<?= $v('occupation') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Monthly Income (<?= e(currency()) ?>)</label>
                    <input type="number" step="0.01" min="0" name="monthly_income" class="form-control" value="<?= $v('monthly_income') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer Photograph</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                    <?php if ($photoUrl): ?>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhoto">
                            <label class="form-check-label small" for="removePhoto">Remove current photo</label>
                        </div>
                        <a href="<?= e($photoUrl) ?>" data-lightbox data-lightbox-title="Customer Photograph" class="d-inline-block mt-2">
                            <img src="<?= e($photoUrl) ?>" alt="photo" style="max-height:80px;border-radius:6px;cursor:zoom-in;">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-telephone me-1"></i> Contact Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Primary Mobile Number <span class="text-danger">*</span></label>
                    <input type="text" name="mobile" class="form-control" required maxlength="30" value="<?= $v('mobile') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">WhatsApp Number</label>
                    <input type="text" name="whatsapp" id="whatsappInput" class="form-control" maxlength="30" value="<?= $v('whatsapp') ?>">
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="whatsapp_same" value="1" id="whatsappSame">
                        <label class="form-check-label small" for="whatsappSame">Same as primary mobile number</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" maxlength="190" value="<?= $v('email') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Current Address</label>
                    <textarea name="current_address" id="currentAddressInput" rows="2" class="form-control" maxlength="500"><?= $v('current_address') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Permanent Address</label>
                    <textarea name="permanent_address" id="permanentAddressInput" rows="2" class="form-control" maxlength="500"><?= $v('permanent_address') ?></textarea>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="permanent_same" value="1" id="permanentSame">
                        <label class="form-check-label small" for="permanentSame">Same as current address</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control" maxlength="100" value="<?= $v('city') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">District</label>
                    <input type="text" name="district" class="form-control" maxlength="100" value="<?= $v('district') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><i class="bi bi-card-image me-1"></i> CNIC Documents &amp; Notes</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">CNIC Front Image</label>
                    <input type="file" name="cnic_front" class="form-control" accept="image/*,application/pdf">
                    <?php if ($frontUrl): ?>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="remove_cnic_front" value="1" id="removeFront">
                            <label class="form-check-label small" for="removeFront">Remove</label>
                        </div>
                        <a href="<?= e($frontUrl) ?>" data-lightbox data-lightbox-title="CNIC Front" class="small">View current</a>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">CNIC Back Image</label>
                    <input type="file" name="cnic_back" class="form-control" accept="image/*,application/pdf">
                    <?php if ($backUrl): ?>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="remove_cnic_back" value="1" id="removeBack">
                            <label class="form-check-label small" for="removeBack">Remove</label>
                        </div>
                        <a href="<?= e($backUrl) ?>" data-lightbox data-lightbox-title="CNIC Back" class="small">View current</a>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Record Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= ($customer['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Internal Notes</label>
                    <textarea name="notes" rows="3" class="form-control" maxlength="2000"><?= $v('notes') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $mode === 'add' ? 'Save Customer' : 'Update Customer' ?></button>
        <a href="<?= e(base_url('customers/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<script>
(function () {
    const form = document.getElementById('customerForm');
    if (!form) return;

    const mobile = form.querySelector('[name="mobile"]');
    const whatsapp = document.getElementById('whatsappInput');
    const whatsappSame = document.getElementById('whatsappSame');
    const currentAddress = document.getElementById('currentAddressInput');
    const permanentAddress = document.getElementById('permanentAddressInput');
    const permanentSame = document.getElementById('permanentSame');

    function syncWhatsapp() {
        if (whatsappSame.checked) {
            whatsapp.value = mobile.value;
            whatsapp.readOnly = true;
        } else {
            whatsapp.readOnly = false;
        }
    }
    whatsappSame.addEventListener('change', syncWhatsapp);
    mobile.addEventListener('input', function () {
        if (whatsappSame.checked) whatsapp.value = mobile.value;
    });
    syncWhatsapp();

    function syncPermanent() {
        if (permanentSame.checked) {
            permanentAddress.value = currentAddress.value;
            permanentAddress.readOnly = true;
        } else {
            permanentAddress.readOnly = false;
        }
    }
    permanentSame.addEventListener('change', syncPermanent);
    currentAddress.addEventListener('input', function () {
        if (permanentSame.checked) permanentAddress.value = currentAddress.value;
    });
    syncPermanent();
})();
</script>
