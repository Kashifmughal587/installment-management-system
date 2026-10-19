<?php
/**
 * Agreement business logic: input collection, validation, persistence,
 * installment schedule generation and payment (re)allocation.
 *
 * Accounting rules:
 *   financed_amount   = agreed_sale_price + additional_charges - discount - advance_payment
 *   remaining_balance = agreed_sale_price + additional_charges - discount - advance_payment - allocated_installment_payments
 * Advance payments are netted through the agreement's advance_payment field
 * and are NOT allocated to installments, so they are never counted twice.
 */

require_once __DIR__ . '/functions.php';

/* ------------------------------------------------------------------ */
/*  Collect POST input                                                */
/* ------------------------------------------------------------------ */
function agreement_collect_post(): array
{
    $products = [];
    $names = $_POST['product_name'] ?? [];
    foreach ((array)$names as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $qty = round_money($_POST['quantity'][$i] ?? 0);
        $unit = round_money($_POST['unit_cash_price'][$i] ?? 0);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $products[] = [
            'product_name'    => $name,
            'category'        => trim((string)($_POST['category'][$i] ?? '')),
            'brand'           => trim((string)($_POST['brand'][$i] ?? '')),
            'model'           => trim((string)($_POST['model'][$i] ?? '')),
            'serial_no'       => trim((string)($_POST['serial_no'][$i] ?? '')),
            'quantity'        => $qty,
            'unit_cash_price' => $unit,
            'line_total'      => round_money($qty * $unit),
            'notes'           => trim((string)($_POST['product_notes'][$i] ?? '')),
        ];
    }

    $guarantors = [];
    $gNames = $_POST['g_name'] ?? [];
    foreach ((array)$gNames as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $cnic = preg_replace('/\D/', '', (string)($_POST['g_cnic'][$i] ?? ''));
        $guarantors[] = [
            'full_name'    => $name,
            'father_name'  => trim((string)($_POST['g_father'][$i] ?? '')),
            'cnic'         => $cnic === '' ? '' : substr($cnic, 0, 5) . '-' . substr($cnic, 5, 7) . '-' . substr($cnic, 12, 1),
            'mobile'       => trim((string)($_POST['g_mobile'][$i] ?? '')),
            'address'      => trim((string)($_POST['g_address'][$i] ?? '')),
            'relationship' => trim((string)($_POST['g_relationship'][$i] ?? '')),
            '_cnic_digits' => $cnic,
        ];
    }

    $customSchedule = [];
    $schedDates = $_POST['schedule_due_date'] ?? [];
    foreach ((array)$schedDates as $i => $d) {
        $d = trim((string)$d);
        if ($d === '') {
            continue;
        }
        $customSchedule[] = [
            'due_date' => $d,
            'amount'   => round_money($_POST['schedule_amount'][$i] ?? 0),
        ];
    }

    return [
        'customer_id'         => (int)($_POST['customer_id'] ?? 0),
        'agreement_date'      => trim((string)($_POST['agreement_date'] ?? '')),
        'status'              => in_array($_POST['status'] ?? '', ['draft', 'active'], true) ? $_POST['status'] : 'draft',
        'agreed_sale_price'   => round_money($_POST['agreed_sale_price'] ?? 0),
        'additional_charges'  => round_money($_POST['additional_charges'] ?? 0),
        'discount'            => round_money($_POST['discount'] ?? 0),
        'advance_payment'     => round_money($_POST['advance_payment'] ?? 0),
        'installment_count'   => max(0, (int)($_POST['installment_count'] ?? 0)),
        'frequency'           => in_array($_POST['frequency'] ?? '', ['monthly', 'weekly', 'biweekly'], true) ? $_POST['frequency'] : 'monthly',
        'first_due_date'      => trim((string)($_POST['first_due_date'] ?? '')),
        'due_day'             => max(0, min(31, (int)($_POST['due_day'] ?? 0))),
        'payment_method_pref' => trim((string)($_POST['payment_method_pref'] ?? '')),
        'terms'               => trim((string)($_POST['terms'] ?? '')),
        'notes'               => trim((string)($_POST['notes'] ?? '')),
        'products'            => $products,
        'guarantors'          => $guarantors,
        'custom_schedule'     => $customSchedule,
        'use_custom_schedule' => (($_POST['use_custom_schedule'] ?? '0') === '1'),
    ];
}

/* ------------------------------------------------------------------ */
/*  Validation                                                        */
/* ------------------------------------------------------------------ */
function agreement_validate(array $in, ?PDO $db = null): array
{
    $db = $db ?: getDB();
    $errors = [];

    $stmt = $db->prepare('SELECT id, status FROM customers WHERE id = ?');
    $stmt->execute([$in['customer_id']]);
    $cust = $stmt->fetch();
    if (!$cust) {
        $errors[] = 'Please select a valid customer.';
    } elseif ($cust['status'] === 'archived') {
        $errors[] = 'The selected customer is archived. Restore the customer before creating an agreement.';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['agreement_date'])) {
        $errors[] = 'Please enter a valid agreement date.';
    }
    if (empty($in['products'])) {
        $errors[] = 'Add at least one product with a name.';
    }
    if ($in['agreed_sale_price'] <= 0) {
        $errors[] = 'Agreed installment sale price must be greater than zero.';
    }
    foreach ($in['products'] as $p) {
        if ($p['unit_cash_price'] < 0 || $p['quantity'] <= 0) {
            $errors[] = 'Product quantities and prices must be positive.';
            break;
        }
    }
    $totalPayable = round_money($in['agreed_sale_price'] + $in['additional_charges'] - $in['discount']);
    if ($in['advance_payment'] < 0) {
        $errors[] = 'Advance payment cannot be negative.';
    }
    if ($in['advance_payment'] > $totalPayable) {
        $errors[] = 'Advance payment cannot exceed the total payable amount.';
    }
    foreach ($in['guarantors'] as $gi => $g) {
        if ($g['_cnic_digits'] !== '' && strlen($g['_cnic_digits']) !== 13) {
            $errors[] = 'Guarantor ' . ($gi + 1) . ': CNIC number must be exactly 13 digits.';
            break;
        }
    }
    if ($in['use_custom_schedule']) {
        if (empty($in['custom_schedule'])) {
            $errors[] = 'Custom schedule is enabled but no rows were provided.';
        } else {
            $financed = round_money($in['agreed_sale_price'] + $in['additional_charges'] - $in['discount'] - $in['advance_payment']);
            $sum = 0.0;
            foreach ($in['custom_schedule'] as $row) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['due_date']) || $row['amount'] <= 0) {
                    $errors[] = 'Each custom schedule row needs a valid date and a positive amount.';
                    break;
                }
                $sum = round_money($sum + $row['amount']);
            }
            if (abs($sum - $financed) > 0.01) {
                $errors[] = 'Custom schedule total (' . money($sum) . ') must equal the financed amount (' . money($financed) . ').';
            }
        }
    } else {
        if ($in['installment_count'] < 1) {
            $errors[] = 'Number of installments must be at least 1.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['first_due_date'])) {
            $errors[] = 'Please enter a valid first due date.';
        }
        $financed = round_money($in['agreed_sale_price'] + $in['additional_charges'] - $in['discount'] - $in['advance_payment']);
        if ($financed < 0) {
            $errors[] = 'Advance payment cannot exceed the total payable amount.';
        }
    }

    return $errors;
}

/* ------------------------------------------------------------------ */
/*  Installment generation + allocation                              */
/* ------------------------------------------------------------------ */
/**
 * Rebuild all installments for an agreement and re-allocate existing
 * installment payments across the new schedule (greedy, earliest first).
 */
function agreement_rebuild_schedule(PDO $db, int $agreementId, array $schedule): void
{
    // Remove old installments (allocations are set NULL by the FK).
    $db->prepare('DELETE FROM installments WHERE agreement_id = ?')->execute([$agreementId]);

    $ins = $db->prepare(
        'INSERT INTO installments (agreement_id, installment_no, due_date, scheduled_amount, paid_amount, remaining_amount, status)
         VALUES (?, ?, ?, ?, 0, ?, ?)'
    );
    $no = 1;
    $today = date('Y-m-d');
    foreach ($schedule as $row) {
        $status = ($row['due_date'] < $today) ? 'overdue' : 'pending';
        $ins->execute([$agreementId, $no, $row['due_date'], $row['amount'], $row['amount'], $status]);
        $no++;
    }

    // Clear allocations for this agreement's installment payments, then re-apply.
    $db->prepare(
        "DELETE a FROM payment_allocations a
         JOIN payments p ON p.id = a.payment_id
         WHERE p.agreement_id = ? AND p.status = 'active'"
    )->execute([$agreementId]);

    $payStmt = $db->prepare(
        "SELECT id, amount FROM payments
         WHERE agreement_id = ? AND status = 'active' AND payment_type IN ('installment','other')
         ORDER BY payment_date ASC, id ASC"
    );
    $payStmt->execute([$agreementId]);
    $payments = $payStmt->fetchAll();

    $instStmt = $db->prepare('SELECT id, scheduled_amount, paid_amount FROM installments WHERE agreement_id = ? ORDER BY installment_no ASC');
    $instStmt->execute([$agreementId]);
    $installments = $instStmt->fetchAll();

    $allocIns = $db->prepare('INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)');
    $instUpdate = $db->prepare('UPDATE installments SET paid_amount = ?, remaining_amount = ? WHERE id = ?');

    // Reset paid amounts.
    $paid = [];
    foreach ($installments as $inst) {
        $paid[(int)$inst['id']] = 0.0;
    }

    $instIndex = 0;
    foreach ($payments as $p) {
        $remainingPayment = (float)$p['amount'];
        while ($remainingPayment > 0.001 && $instIndex < count($installments)) {
            $inst = $installments[$instIndex];
            $instId = (int)$inst['id'];
            $capacity = round_money((float)$inst['scheduled_amount'] - $paid[$instId]);
            if ($capacity <= 0.001) {
                $instIndex++;
                continue;
            }
            $alloc = round_money(min($capacity, $remainingPayment));
            if ($alloc <= 0) {
                break;
            }
            $allocIns->execute([(int)$p['id'], $instId, $alloc]);
            $paid[$instId] = round_money($paid[$instId] + $alloc);
            $remainingPayment = round_money($remainingPayment - $alloc);
            if ($paid[$instId] >= (float)$inst['scheduled_amount'] - 0.001) {
                $instIndex++;
            }
        }
    }

    foreach ($installments as $inst) {
        $instId = (int)$inst['id'];
        $scheduled = (float)$inst['scheduled_amount'];
        $paidAmount = $paid[$instId];
        $remaining = round_money(max(0, $scheduled - $paidAmount));
        $instUpdate->execute([$paidAmount, $remaining, $instId]);
    }

    refresh_installment_statuses($db, $agreementId);
}

/* ------------------------------------------------------------------ */
/*  Recalculate agreement balance                                    */
/* ------------------------------------------------------------------ */
function agreement_recompute_balance(PDO $db, int $agreementId): void
{
    $stmt = $db->prepare('SELECT agreed_sale_price, additional_charges, discount, advance_payment FROM agreements WHERE id = ?');
    $stmt->execute([$agreementId]);
    $ag = $stmt->fetch();
    if (!$ag) {
        return;
    }
    $allocated = agreement_paid_total($db, $agreementId);
    $remaining = round_money(max(0, (float)$ag['agreed_sale_price'] + (float)$ag['additional_charges'] - (float)$ag['discount'] - (float)$ag['advance_payment'] - $allocated));
    $db->prepare('UPDATE agreements SET remaining_balance = ? WHERE id = ?')->execute([$remaining, $agreementId]);

    // Auto-complete an active agreement once its balance reaches zero, and
    // reactivate a completed agreement if a payment is later voided.
    $stmt = $db->prepare('SELECT status FROM agreements WHERE id = ?');
    $stmt->execute([$agreementId]);
    $status = (string)$stmt->fetchColumn();
    if ($status === 'active' && $remaining <= 0.0) {
        $db->prepare("UPDATE agreements SET status = 'completed', completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")
           ->execute([$agreementId]);
    } elseif ($status === 'completed' && $remaining > 0.0) {
        $db->prepare("UPDATE agreements SET status = 'active', completed_at = NULL WHERE id = ?")
           ->execute([$agreementId]);
    }
}

/* ------------------------------------------------------------------ */
/*  Persist agreement                                                */
/* ------------------------------------------------------------------ */
/**
 * @return int agreement id
 */
function agreement_save(PDO $db, array $in, ?int $id, int $userId): int
{
    $totalCash = 0.0;
    foreach ($in['products'] as $p) {
        $totalCash = round_money($totalCash + $p['line_total']);
    }
    $financed = round_money($in['agreed_sale_price'] + $in['additional_charges'] - $in['discount'] - $in['advance_payment']);

    // Determine schedule.
    if ($in['use_custom_schedule']) {
        $schedule = $in['custom_schedule'];
        $count = count($schedule);
        $firstDue = $schedule[0]['due_date'] ?? $in['first_due_date'];
        $installmentAmount = 0.0;
    } else {
        $count = $in['installment_count'];
        $firstDue = $in['first_due_date'];
        $schedule = build_equal_schedule($financed, $count, $firstDue, $in['frequency'], $in['due_day']);
        $installmentAmount = $schedule ? $schedule[0]['amount'] : 0.0;
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        $prefix = setting('agreement_prefix', 'AGR');
        $year = (int)date('Y', strtotime($in['agreement_date']));

        if ($id) {
            $stmt = $db->prepare(
                'UPDATE agreements SET
                    customer_id = :customer_id, agreement_date = :agreement_date, status = :status,
                    total_cash_price = :total_cash_price, agreed_sale_price = :agreed_sale_price,
                    additional_charges = :additional_charges, discount = :discount,
                    advance_payment = :advance_payment, financed_amount = :financed_amount,
                    installment_count = :installment_count, frequency = :frequency,
                    installment_amount = :installment_amount, installment_start_date = :start_date,
                    first_due_date = :first_due_date, due_day = :due_day,
                    payment_method_pref = :pm, terms = :terms, notes = :notes,
                    updated_by = :updated_by
                 WHERE id = :id'
            );
            $stmt->execute([
                ':customer_id' => $in['customer_id'],
                ':agreement_date' => $in['agreement_date'],
                ':status' => $in['status'],
                ':total_cash_price' => $totalCash,
                ':agreed_sale_price' => $in['agreed_sale_price'],
                ':additional_charges' => $in['additional_charges'],
                ':discount' => $in['discount'],
                ':advance_payment' => $in['advance_payment'],
                ':financed_amount' => $financed,
                ':installment_count' => $count,
                ':frequency' => $in['frequency'],
                ':installment_amount' => $installmentAmount,
                ':start_date' => $firstDue ?: null,
                ':first_due_date' => $firstDue ?: null,
                ':due_day' => $in['due_day'] ?: null,
                ':pm' => $in['payment_method_pref'] ?: null,
                ':terms' => $in['terms'] ?: null,
                ':notes' => $in['notes'] ?: null,
                ':updated_by' => $userId,
                ':id' => $id,
            ]);
            $agreementId = $id;
            if ($in['status'] === 'active') {
                $db->prepare('UPDATE agreements SET activated_at = COALESCE(activated_at, NOW()) WHERE id = ?')->execute([$agreementId]);
            }
        } else {
            $agreementNo = next_document_number($db, 'agreement', $prefix, $year);
            $stmt = $db->prepare(
                'INSERT INTO agreements
                 (agreement_no, customer_id, agreement_date, status,
                  total_cash_price, agreed_sale_price, additional_charges, discount,
                  advance_payment, financed_amount, remaining_balance,
                  installment_count, frequency, installment_amount,
                  installment_start_date, first_due_date, due_day,
                  payment_method_pref, terms, notes, created_by, updated_by, activated_at)
                 VALUES
                 (:agreement_no, :customer_id, :agreement_date, :status,
                  :total_cash_price, :agreed_sale_price, :additional_charges, :discount,
                  :advance_payment, :financed_amount, :remaining_balance,
                  :installment_count, :frequency, :installment_amount,
                  :start_date, :first_due_date, :due_day,
                  :pm, :terms, :notes, :created_by, :updated_by, :activated_at)'
            );
            $stmt->execute([
                ':agreement_no' => $agreementNo,
                ':customer_id' => $in['customer_id'],
                ':agreement_date' => $in['agreement_date'],
                ':status' => $in['status'],
                ':total_cash_price' => $totalCash,
                ':agreed_sale_price' => $in['agreed_sale_price'],
                ':additional_charges' => $in['additional_charges'],
                ':discount' => $in['discount'],
                ':advance_payment' => $in['advance_payment'],
                ':financed_amount' => $financed,
                ':remaining_balance' => $financed,
                ':installment_count' => $count,
                ':frequency' => $in['frequency'],
                ':installment_amount' => $installmentAmount,
                ':start_date' => $firstDue ?: null,
                ':first_due_date' => $firstDue ?: null,
                ':due_day' => $in['due_day'] ?: null,
                ':pm' => $in['payment_method_pref'] ?: null,
                ':terms' => $in['terms'] ?: null,
                ':notes' => $in['notes'] ?: null,
                ':created_by' => $userId,
                ':updated_by' => $userId,
                ':activated_at' => $in['status'] === 'active' ? date('Y-m-d H:i:s') : null,
            ]);
            $agreementId = (int)$db->lastInsertId();
        }

        // Products: replace.
        $db->prepare('DELETE FROM agreement_products WHERE agreement_id = ?')->execute([$agreementId]);
        $pIns = $db->prepare(
            'INSERT INTO agreement_products
             (agreement_id, product_name, category, brand, model, serial_no, quantity, unit_cash_price, line_total, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($in['products'] as $p) {
            $pIns->execute([$agreementId, $p['product_name'], $p['category'] ?: null, $p['brand'] ?: null,
                $p['model'] ?: null, $p['serial_no'] ?: null, $p['quantity'], $p['unit_cash_price'],
                $p['line_total'], $p['notes'] ?: null]);
        }

        // Guarantors: replace.
        $db->prepare('DELETE FROM guarantors WHERE agreement_id = ?')->execute([$agreementId]);
        $gIns = $db->prepare(
            'INSERT INTO guarantors (agreement_id, full_name, father_name, cnic, mobile, address, relationship)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($in['guarantors'] as $g) {
            $gIns->execute([$agreementId, $g['full_name'], $g['father_name'] ?: null, $g['cnic'] ?: null,
                $g['mobile'] ?: null, $g['address'] ?: null, $g['relationship'] ?: null]);
        }

        // Advance payment ledger (auto-record on create / adjust on edit).
        agreement_sync_advance_payment($db, $agreementId, $in['customer_id'], $in['advance_payment'], $in['agreement_date'], $userId);

        // Installments + allocations.
        agreement_rebuild_schedule($db, $agreementId, $schedule);
        agreement_recompute_balance($db, $agreementId);

        if ($ownTransaction) {
            $db->commit();
        }
        return $agreementId;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Keep the advance payment ledger in sync with the agreement's advance field.
 */
function agreement_sync_advance_payment(PDO $db, int $agreementId, int $customerId, float $advance, string $agreementDate, int $userId): void
{
    $stmt = $db->prepare("SELECT id, amount FROM payments WHERE agreement_id = ? AND payment_type = 'advance' AND status = 'active' ORDER BY id ASC LIMIT 1");
    $stmt->execute([$agreementId]);
    $existing = $stmt->fetch();

    if ($advance <= 0) {
        if ($existing) {
            $db->prepare("UPDATE payments SET status = 'void', void_reason = 'Advance removed on agreement edit', void_by = ?, void_at = NOW() WHERE id = ?")
               ->execute([$userId, (int)$existing['id']]);
        }
        return;
    }

    if ($existing) {
        if (abs((float)$existing['amount'] - $advance) > 0.001) {
            $db->prepare('UPDATE payments SET amount = ?, payment_date = ?, prev_balance = ?, new_balance = ? WHERE id = ?')
               ->execute([$advance, $agreementDate, $advance, $advance, (int)$existing['id']]);
        }
        return;
    }

    $prefix = setting('receipt_prefix', 'RCP');
    $year = (int)date('Y', strtotime($agreementDate));
    $receiptNo = next_document_number($db, 'receipt', $prefix, $year);
    $db->prepare(
        "INSERT INTO payments
         (receipt_no, customer_id, agreement_id, payment_date, amount, payment_type, method,
          received_by, notes, prev_balance, new_balance, created_by)
         VALUES (?, ?, ?, ?, ?, 'advance', 'cash', ?, 'Advance recorded at agreement creation', ?, ?, ?)"
    )->execute([$receiptNo, $customerId, $agreementId, $agreementDate, $advance, $userId, $advance, $advance, $userId]);
}
