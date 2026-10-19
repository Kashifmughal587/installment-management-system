<?php
/**
 * Payment engine: record, allocate, void.
 *
 * remaining_balance = agreed_sale_price + additional_charges - discount - advance_payment - allocated_installment_payments
 * Voided payments are excluded from all calculations but retained for audit.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/agreement.php';

/**
 * Allocate a payment amount across installments (earliest-first, optionally
 * starting from a chosen installment).
 */
function payment_allocate(PDO $db, int $paymentId, int $agreementId, float $amount, ?int $startInstallmentId = null): void
{
    $stmt = $db->prepare('SELECT id, scheduled_amount, paid_amount FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
    $stmt->execute([$agreementId]);
    $installments = $stmt->fetchAll();
    if (!$installments) {
        return;
    }

    if ($startInstallmentId) {
        $ordered = [];
        $found = false;
        foreach ($installments as $idx => $inst) {
            if ((int)$inst['id'] === $startInstallmentId) {
                $ordered = array_merge(array_slice($installments, $idx), array_slice($installments, 0, $idx));
                $found = true;
                break;
            }
        }
        if ($found) {
            $installments = $ordered;
        }
    }

    $remaining = round_money($amount);
    $insert = $db->prepare('INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)');
    $update = $db->prepare('UPDATE installments SET paid_amount = ?, remaining_amount = ? WHERE id = ?');

    foreach ($installments as $inst) {
        if ($remaining <= 0.001) {
            break;
        }
        $scheduled = (float)$inst['scheduled_amount'];
        $paid = (float)$inst['paid_amount'];
        $capacity = round_money($scheduled - $paid);
        if ($capacity <= 0.001) {
            continue;
        }
        $alloc = round_money(min($capacity, $remaining));
        if ($alloc <= 0) {
            continue;
        }
        $insert->execute([$paymentId, (int)$inst['id'], $alloc]);
        $newPaid = round_money($paid + $alloc);
        $update->execute([$newPaid, round_money(max(0, $scheduled - $newPaid)), (int)$inst['id']]);
        $remaining = round_money($remaining - $alloc);
    }
}

/**
 * Record a payment and its allocations inside a single transaction.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function payment_apply(PDO $db, array $data, int $userId): array
{
    $amount = round_money($data['amount']);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Payment amount must be greater than zero.'];
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        // Lock the agreement row.
        $stmt = $db->prepare('SELECT * FROM agreements WHERE id = ? FOR UPDATE');
        $stmt->execute([(int)$data['agreement_id']]);
        $agreement = $stmt->fetch();
        if (!$agreement) {
            throw new RuntimeException('Agreement not found.');
        }
        if ((int)$agreement['customer_id'] !== (int)$data['customer_id']) {
            throw new RuntimeException('The selected agreement does not belong to the selected customer.');
        }
        if ($agreement['status'] === 'cancelled') {
            throw new RuntimeException('Cannot record payments on a cancelled agreement.');
        }
        if ($agreement['status'] === 'completed') {
            throw new RuntimeException('This agreement is already completed.');
        }

        $prevBalance = (float)$agreement['remaining_balance'];
        if ($amount > $prevBalance + 0.01) {
            throw new RuntimeException('Payment exceeds the outstanding balance of ' . money($prevBalance) . '.');
        }

        $receiptNo = next_document_number($db, 'receipt', setting('receipt_prefix', 'RCP'), (int)date('Y', strtotime($data['payment_date'])));

        $stmt = $db->prepare(
            'INSERT INTO payments
             (receipt_no, customer_id, agreement_id, installment_id, payment_date, amount, payment_type,
              method, transaction_ref, received_by, notes, attachment_path, prev_balance, new_balance, created_by)
             VALUES
             (:receipt_no, :customer_id, :agreement_id, :installment_id, :payment_date, :amount, :payment_type,
              :method, :transaction_ref, :received_by, :notes, :attachment_path, :prev_balance, 0, :created_by)'
        );
        $stmt->execute([
            ':receipt_no' => $receiptNo,
            ':customer_id' => (int)$data['customer_id'],
            ':agreement_id' => (int)$data['agreement_id'],
            ':installment_id' => $data['installment_id'] ?: null,
            ':payment_date' => $data['payment_date'],
            ':amount' => $amount,
            ':payment_type' => $data['payment_type'],
            ':method' => $data['method'],
            ':transaction_ref' => $data['transaction_ref'] ?: null,
            ':received_by' => $data['received_by'] ?: $userId,
            ':notes' => $data['notes'] ?: null,
            ':attachment_path' => $data['attachment_path'] ?? null,
            ':prev_balance' => $prevBalance,
            ':created_by' => $userId,
        ]);
        $paymentId = (int)$db->lastInsertId();

        payment_allocate($db, $paymentId, (int)$data['agreement_id'], $amount, $data['installment_id'] ? (int)$data['installment_id'] : null);

        refresh_installment_statuses($db, (int)$data['agreement_id']);
        agreement_recompute_balance($db, (int)$data['agreement_id']);

        $stmt = $db->prepare('SELECT remaining_balance FROM agreements WHERE id = ?');
        $stmt->execute([(int)$data['agreement_id']]);
        $newBalance = (float)$stmt->fetchColumn();
        $db->prepare('UPDATE payments SET new_balance = ? WHERE id = ?')->execute([$newBalance, $paymentId]);

        if ($ownTransaction) {
            $db->commit();
        }
        return ['ok' => true, 'id' => $paymentId];
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Void a payment (controlled correction). Reverses its allocations.
 */
function payment_void(PDO $db, int $paymentId, int $userId, string $reason): array
{
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        if ($payment['status'] === 'void') {
            throw new RuntimeException('This payment is already voided.');
        }

        $agreementId = (int)$payment['agreement_id'];

        // Remove allocations, then recompute affected installments from remaining active allocations.
        $stmt = $db->prepare('SELECT DISTINCT installment_id FROM payment_allocations WHERE payment_id = ? AND installment_id IS NOT NULL');
        $stmt->execute([$paymentId]);
        $affected = array_map(fn($r) => (int)$r['installment_id'], $stmt->fetchAll());

        $db->prepare('DELETE FROM payment_allocations WHERE payment_id = ?')->execute([$paymentId]);

        $recalc = $db->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
             FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
             WHERE a.installment_id = ? AND p.status = 'active'"
        );
        $upd = $db->prepare('UPDATE installments SET paid_amount = ?, remaining_amount = ? WHERE id = ?');
        foreach ($affected as $instId) {
            $recalc->execute([$instId]);
            $paid = (float)$recalc->fetchColumn();
            $stmt2 = $db->prepare('SELECT scheduled_amount FROM installments WHERE id = ?');
            $stmt2->execute([$instId]);
            $sched = (float)$stmt2->fetchColumn();
            $upd->execute([$paid, round_money(max(0, $sched - $paid)), $instId]);
        }

        $db->prepare("UPDATE payments SET status = 'void', void_reason = ?, void_by = ?, void_at = NOW() WHERE id = ?")
           ->execute([$reason, $userId, $paymentId]);

        refresh_installment_statuses($db, $agreementId);
        agreement_recompute_balance($db, $agreementId);

        if ($ownTransaction) {
            $db->commit();
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Detect a likely accidental duplicate payment.
 */
function payment_is_duplicate(PDO $db, int $agreementId, float $amount, string $date): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM payments
         WHERE agreement_id = ? AND amount = ? AND payment_date = ? AND status = 'active'
           AND created_at > (NOW() - INTERVAL 3 MINUTE)"
    );
    $stmt->execute([$agreementId, $amount, $date]);
    return (int)$stmt->fetchColumn() > 0;
}
