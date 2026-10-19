<?php
/**
 * Full installment schedule for an agreement (screen + print friendly).
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('view_agreements');

$db = getDB();
$id = (int)get('id', 0);
$stmt = $db->prepare('SELECT g.*, c.full_name AS customer_name, c.customer_code FROM agreements g JOIN customers c ON c.id = g.customer_id WHERE g.id = ?');
$stmt->execute([$id]);
$agreement = $stmt->fetch();
if (!$agreement) {
    http_response_code(404);
    flash_set('error', 'Agreement not found.');
    redirect('agreements/index.php');
}
$stmt = $db->prepare('SELECT * FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
$stmt->execute([$id]);
$installments = $stmt->fetchAll();

$pageTitle = 'Schedule - ' . $agreement['agreement_no'];
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= e(base_url('agreements/view.php?id=' . $id)) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to agreement</a>
        <h5 class="mt-2 mb-0">Installment Schedule - <?= e($agreement['agreement_no']) ?></h5>
        <span class="text-muted small"><?= e($agreement['customer_name']) ?> (<?= e($agreement['customer_code']) ?>)</span>
    </div>
    <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Due Date</th><th class="text-end">Scheduled</th><th class="text-end">Paid</th><th class="text-end">Remaining</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php $sc = 0; $sp = 0; $sr = 0; foreach ($installments as $inst): $sc += (float)$inst['scheduled_amount']; $sp += (float)$inst['paid_amount']; $sr += (float)$inst['remaining_amount']; ?>
                    <tr>
                        <td><?= (int)$inst['installment_no'] ?></td>
                        <td><?= e(fmt_date($inst['due_date'])) ?></td>
                        <td class="text-end"><?= e(money($inst['scheduled_amount'])) ?></td>
                        <td class="text-end text-success"><?= e(money($inst['paid_amount'])) ?></td>
                        <td class="text-end"><?= e(money($inst['remaining_amount'])) ?></td>
                        <td><?= installment_status_badge($inst['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td colspan="2" class="text-end">Totals</td>
                        <td class="text-end"><?= e(money($sc)) ?></td>
                        <td class="text-end"><?= e(money($sp)) ?></td>
                        <td class="text-end"><?= e(money($sr)) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
