<?php
/**
 * Business settings (single active row).
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('manage_settings');

$db = getDB();
$current = business_settings();

if (!$current) {
    // Create a default row if somehow missing.
    $db->exec("INSERT INTO business_settings (shop_name, is_active) VALUES ('My Shop', 1)");
    $current = business_settings();
}
$settingsId = (int)$current['id'];

$errors = [];
$form = $current;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $form = [
        'branch_name'       => post('branch_name', 'Main Branch'),
        'shop_name'         => post('shop_name'),
        'owner_name'        => post('owner_name'),
        'father_name'       => post('father_name'),
        'cnic'              => post('cnic'),
        'phone'             => post('phone'),
        'whatsapp'          => post('whatsapp'),
        'email'             => post('email'),
        'address'           => post('address'),
        'city'              => post('city'),
        'district'          => post('district'),
        'agreement_header'  => post('agreement_header'),
        'agreement_footer'  => post('agreement_footer'),
        'default_terms'     => post('default_terms'),
        'currency'          => post('currency', 'PKR'),
        'default_due_day'   => post_int('default_due_day', 5),
        'document_language' => post('document_language', 'en'),
        'receipt_prefix'    => strtoupper(post('receipt_prefix', 'RCP')),
        'agreement_prefix'  => strtoupper(post('agreement_prefix', 'AGR')),
        'logo_path'         => $current['logo_path'] ?? null,
        'signature_path'    => $current['signature_path'] ?? null,
    ];

    if ($form['shop_name'] === '') {
        $errors['shop_name'] = 'Business name is required.';
    }
    if (!preg_match('/^[A-Z0-9]{1,20}$/', $form['receipt_prefix'] ?: 'RCP')) {
        $errors['receipt_prefix'] = 'Use 1-20 letters/numbers.';
    }
    if (!preg_match('/^[A-Z0-9]{1,20}$/', $form['agreement_prefix'] ?: 'AGR')) {
        $errors['agreement_prefix'] = 'Use 1-20 letters/numbers.';
    }
    if (!in_array($form['document_language'], ['en', 'ur'], true)) {
        $form['document_language'] = 'en';
    }
    $form['default_due_day'] = min(28, max(1, (int)$form['default_due_day']));
    if (mb_strlen($form['currency']) < 1 || mb_strlen($form['currency']) > 10) {
        $form['currency'] = 'PKR';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    $cnicDigits = preg_replace('/\D/', '', $form['cnic']);
    if ($form['cnic'] !== '' && strlen($cnicDigits) !== 13) {
        $errors['cnic'] = 'CNIC number must be exactly 13 digits.';
    } else {
        $form['cnic'] = $cnicDigits === '' ? '' : substr($cnicDigits, 0, 5) . '-' . substr($cnicDigits, 5, 7) . '-' . substr($cnicDigits, 12, 1);
    }

    // Logo upload / removal.
    $logoRemove = !empty($_POST['logo_remove']);
    $logo = handle_upload('logo', 'branding', ['path' => $current['logo_path'] ?? null, 'remove' => $logoRemove]);
    if (!$logo['ok']) {
        $errors['logo'] = $logo['error'];
    } else {
        $form['logo_path'] = $logo['path'];
    }

    // Signature upload / removal.
    $sigRemove = !empty($_POST['signature_remove']);
    $sig = handle_upload('signature', 'branding', ['path' => $current['signature_path'] ?? null, 'remove' => $sigRemove]);
    if (!$sig['ok']) {
        $errors['signature'] = $sig['error'];
    } else {
        $form['signature_path'] = $sig['path'];
    }

    if (empty($errors)) {
        $stmt = $db->prepare(
            'UPDATE business_settings SET
                branch_name = :branch_name, shop_name = :shop_name, owner_name = :owner_name,
                father_name = :father_name, cnic = :cnic, phone = :phone, whatsapp = :whatsapp,
                email = :email, address = :address, city = :city, district = :district,
                logo_path = :logo_path, signature_path = :signature_path,
                agreement_header = :agreement_header, agreement_footer = :agreement_footer,
                default_terms = :default_terms, currency = :currency, default_due_day = :default_due_day,
                document_language = :document_language, receipt_prefix = :receipt_prefix,
                agreement_prefix = :agreement_prefix, updated_by = :updated_by
             WHERE id = :id'
        );
        $stmt->execute([
            ':branch_name' => $form['branch_name'], ':shop_name' => $form['shop_name'],
            ':owner_name' => $form['owner_name'], ':father_name' => $form['father_name'],
            ':cnic' => $form['cnic'], ':phone' => $form['phone'], ':whatsapp' => $form['whatsapp'],
            ':email' => $form['email'] ?: null, ':address' => $form['address'],
            ':city' => $form['city'], ':district' => $form['district'],
            ':logo_path' => $form['logo_path'], ':signature_path' => $form['signature_path'],
            ':agreement_header' => $form['agreement_header'], ':agreement_footer' => $form['agreement_footer'],
            ':default_terms' => $form['default_terms'], ':currency' => $form['currency'],
            ':default_due_day' => $form['default_due_day'], ':document_language' => $form['document_language'],
            ':receipt_prefix' => $form['receipt_prefix'], ':agreement_prefix' => $form['agreement_prefix'],
            ':updated_by' => current_user_id(), ':id' => $settingsId,
        ]);
        audit_log(current_user_id(), 'update_business_settings', 'business_settings', $settingsId);
        flash_set('success', 'Business settings saved.');
        redirect('settings/business.php');
    }
}

$pageTitle = 'Business Settings';
include __DIR__ . '/../includes/header.php';
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('settings/business.php')) ?>" enctype="multipart/form-data" novalidate data-cnic-validate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-shop me-1"></i> Business Identity</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Branch Name</label>
                            <input type="text" name="branch_name" class="form-control" value="<?= e($form['branch_name'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business / Shop Name <span class="text-danger">*</span></label>
                            <input type="text" name="shop_name" class="form-control <?= isset($errors['shop_name']) ? 'is-invalid' : '' ?>" value="<?= e($form['shop_name'] ?? '') ?>" maxlength="190" required>
                            <?php if (isset($errors['shop_name'])): ?><div class="invalid-feedback"><?= e($errors['shop_name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Owner Name</label>
                            <input type="text" name="owner_name" class="form-control" value="<?= e($form['owner_name'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Father / Husband Name</label>
                            <input type="text" name="father_name" class="form-control" value="<?= e($form['father_name'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CNIC</label>
                            <input type="text" name="cnic" class="form-control <?= isset($errors['cnic']) ? 'is-invalid' : '' ?>" value="<?= e($form['cnic'] ?? '') ?>" maxlength="15" inputmode="numeric" placeholder="35202-1234567-1" data-cnic-mask>
                            <?php if (isset($errors['cnic'])): ?><div class="invalid-feedback"><?= e($errors['cnic']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($form['email'] ?? '') ?>" maxlength="190">
                            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($form['phone'] ?? '') ?>" maxlength="30">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-control" value="<?= e($form['whatsapp'] ?? '') ?>" maxlength="30">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="<?= e($form['address'] ?? '') ?>" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control" value="<?= e($form['city'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">District</label>
                            <input type="text" name="district" class="form-control" value="<?= e($form['district'] ?? '') ?>" maxlength="100">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-file-earmark-text me-1"></i> Document Text</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Agreement Header Note</label>
                        <textarea name="agreement_header" class="form-control" rows="2" maxlength="2000"><?= e($form['agreement_header'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Terms &amp; Conditions</label>
                        <textarea name="default_terms" class="form-control" rows="5" maxlength="5000"><?= e($form['default_terms'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Agreement Footer Note</label>
                        <textarea name="agreement_footer" class="form-control" rows="2" maxlength="2000"><?= e($form['agreement_footer'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-gear me-1"></i> Preferences</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Currency Code</label>
                        <input type="text" name="currency" class="form-control" value="<?= e($form['currency'] ?? 'PKR') ?>" maxlength="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Due Day (1-28)</label>
                        <input type="number" name="default_due_day" class="form-control" min="1" max="28" value="<?= (int)($form['default_due_day'] ?? 5) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Document Language</label>
                        <select name="document_language" class="form-select">
                            <option value="en" <?= ($form['document_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English (LTR)</option>
                            <option value="ur" <?= ($form['document_language'] ?? '') === 'ur' ? 'selected' : '' ?>>Urdu (RTL)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Agreement No. Prefix</label>
                        <input type="text" name="agreement_prefix" class="form-control <?= isset($errors['agreement_prefix']) ? 'is-invalid' : '' ?>" value="<?= e($form['agreement_prefix'] ?? 'AGR') ?>" maxlength="20">
                        <?php if (isset($errors['agreement_prefix'])): ?><div class="invalid-feedback"><?= e($errors['agreement_prefix']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Receipt No. Prefix</label>
                        <input type="text" name="receipt_prefix" class="form-control <?= isset($errors['receipt_prefix']) ? 'is-invalid' : '' ?>" value="<?= e($form['receipt_prefix'] ?? 'RCP') ?>" maxlength="20">
                        <?php if (isset($errors['receipt_prefix'])): ?><div class="invalid-feedback"><?= e($errors['receipt_prefix']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-image me-1"></i> Branding</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <?php if (!empty($form['logo_path'])): ?>
                            <div class="mb-2"><img src="<?= e(upload_url($form['logo_path'])) ?>" alt="Logo" style="max-height:60px;max-width:140px" class="border rounded p-1"></div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="logo_remove" id="logo_remove" value="1">
                                <label class="form-check-label small" for="logo_remove">Remove current logo</label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="logo" class="form-control <?= isset($errors['logo']) ? 'is-invalid' : '' ?>" accept=".jpg,.jpeg,.png,.webp">
                        <?php if (isset($errors['logo'])): ?><div class="invalid-feedback"><?= e($errors['logo']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Signature</label>
                        <?php if (!empty($form['signature_path'])): ?>
                            <div class="mb-2"><img src="<?= e(upload_url($form['signature_path'])) ?>" alt="Signature" style="max-height:50px;max-width:160px" class="border rounded p-1"></div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="signature_remove" id="signature_remove" value="1">
                                <label class="form-check-label small" for="signature_remove">Remove current signature</label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="signature" class="form-control <?= isset($errors['signature']) ? 'is-invalid' : '' ?>" accept=".jpg,.jpeg,.png,.webp">
                        <?php if (isset($errors['signature'])): ?><div class="invalid-feedback"><?= e($errors['signature']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Settings</button>
            </div>
        </div>
    </div>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
