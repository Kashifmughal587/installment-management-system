<?php
/**
 * Shared helper functions used across the application.
 * Contains NO database access at include-time so it can be safely
 * required from db.php, auth.php and any page.
 */

/* ------------------------------------------------------------------ */
/*  Output escaping                                                    */
/* ------------------------------------------------------------------ */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------ */
/*  Error handling / logging                                          */
/* ------------------------------------------------------------------ */
function app_log(string $message): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function fatal_error(string $publicMessage): void
{
    if (defined('APP_DEBUG') && APP_DEBUG) {
        // In debug we still avoid dumping credentials; show a generic trace hint.
        http_response_code(500);
        echo '<div style="font:14px/1.5 monospace;padding:20px;color:#900;">'
            . e($publicMessage) . '<br><small>See logs/app.log for details.</small></div>';
    } else {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<div style="font-family:sans-serif;padding:40px;text-align:center;">'
            . '<h2>Something went wrong</h2><p>Please try again later or contact the administrator.</p></div>';
    }
    exit;
}

/* ------------------------------------------------------------------ */
/*  URL / routing helpers                                             */
/* ------------------------------------------------------------------ */
function app_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (defined('APP_URL') && APP_URL !== '') {
        $base = rtrim(APP_URL, '/');
        return $base;
    }
    $root = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    if ($docRoot !== '' && strpos($root, $docRoot) === 0) {
        $base = rtrim(substr($root, strlen($docRoot)), '/');
    } else {
        $base = '';
    }
    return $base;
}

function base_url(string $path = ''): string
{
    $b = app_base();
    if ($path === '') {
        return $b === '' ? '/' : $b;
    }
    return $b . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : base_url($path)));
    exit;
}

function current_url_path(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

/* ------------------------------------------------------------------ */
/*  Flash messages                                                    */
/* ------------------------------------------------------------------ */
function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/* ------------------------------------------------------------------ */
/*  Money & number formatting                                         */
/* ------------------------------------------------------------------ */
function currency(): string
{
    return defined('CURRENCY_CODE') ? CURRENCY_CODE : 'PKR';
}

function money($amount): string
{
    return number_format((float)$amount, 2);
}

function money_pkr($amount): string
{
    return currency() . ' ' . money($amount);
}

function round_money($amount): float
{
    return round((float)$amount, 2);
}

/**
 * Convert an amount to words using the Pakistani/Indian numbering system
 * (crore, lakh, thousand). Returns e.g. "One Hundred Twenty-Three Rupees and Fifty Paisa Only".
 */
function amount_in_words($amount, ?string $currencyName = null): string
{
    $amount = round_money($amount);
    $currencyName = $currencyName ?: (defined('CURRENCY_CODE') ? CURRENCY_CODE : 'PKR');
    $rupeeName = ['PKR' => 'Rupees', 'INR' => 'Rupees', 'USD' => 'Dollars', 'AED' => 'Dirhams'][$currencyName] ?? $currencyName;
    $subName = ['PKR' => 'Paisa', 'INR' => 'Paise', 'USD' => 'Cents', 'AED' => 'Fils'][$currencyName] ?? 'Cents';

    $negative = $amount < 0;
    $amount = abs($amount);
    $rupees = (int)floor($amount + 0.00001);
    $paisa = (int)round(($amount - $rupees) * 100);
    if ($paisa >= 100) {
        $rupees += intdiv($paisa, 100);
        $paisa %= 100;
    }

    if ($rupees === 0 && $paisa === 0) {
        return 'Zero ' . $rupeeName . ' Only';
    }

    $words = ($negative ? 'Minus ' : '') . number_to_words_pk($rupees) . ' ' . $rupeeName;
    if ($paisa > 0) {
        $words .= ' and ' . number_to_words_pk($paisa) . ' ' . $subName;
    }
    return trim($words) . ' Only';
}

/**
 * Spell an integer using the Pakistani/Indian system (crore/lakh/thousand).
 */
function number_to_words_pk(int $number): string
{
    if ($number === 0) {
        return 'Zero';
    }
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $twoDigits = function (int $n) use ($ones, $tens): string {
        if ($n < 20) {
            return $ones[$n];
        }
        return trim($tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : ''));
    };

    $convert = function (int $n) use ($twoDigits): string {
        if ($n < 100) {
            return $twoDigits($n);
        }
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        return trim($twoDigits($hundreds) . ' Hundred' . ($rest ? ' ' . $twoDigits($rest) : ''));
    };

    $result = '';
    $crore = intdiv($number, 10000000);
    $number %= 10000000;
    $lakh = intdiv($number, 100000);
    $number %= 100000;
    $thousand = intdiv($number, 1000);
    $number %= 1000;

    if ($crore) {
        $result .= $convert($crore) . ' Crore ';
    }
    if ($lakh) {
        $result .= $convert($lakh) . ' Lakh ';
    }
    if ($thousand) {
        $result .= $convert($thousand) . ' Thousand ';
    }
    if ($number) {
        $result .= $convert($number);
    }
    return trim($result);
}

/* ------------------------------------------------------------------ */
/*  Date formatting                                                   */
/* ------------------------------------------------------------------ */
function fmt_date(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : '';
}

function fmt_datetime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('d-m-Y h:i A', $ts) : '';
}

/* ------------------------------------------------------------------ */
/*  Input helpers                                                     */
/* ------------------------------------------------------------------ */
function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function post_float(string $key): float
{
    return round_money(str_replace(',', '', (string)post($key, '0')));
}

function post_int(string $key, int $default = 0): int
{
    return (int)post($key, $default);
}

/* ------------------------------------------------------------------ */
/*  Client IP                                                         */
/* ------------------------------------------------------------------ */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ------------------------------------------------------------------ */
/*  Audit logging                                                     */
/* ------------------------------------------------------------------ */
function audit_log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, $details = null): void
{
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        app_log('audit_log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/*  Concurrency-safe document numbering                               */
/* ------------------------------------------------------------------ */
function next_document_number(?PDO $db, string $docType, string $prefix, ?int $year = null): string
{
    $db = $db ?: getDB();
    $year = $year ?: (int)date('Y');
    $ownTransaction = !$db->inTransaction();

    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        // Ensure the sequence row exists (race-safe), then lock it.
        $db->prepare(
            'INSERT INTO document_sequences (doc_type, seq_year, prefix, last_number)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE doc_type = doc_type'
        )->execute([$docType, $year, $prefix]);

        $stmt = $db->prepare('SELECT last_number FROM document_sequences WHERE doc_type = ? AND seq_year = ? FOR UPDATE');
        $stmt->execute([$docType, $year]);
        $last = (int)$stmt->fetchColumn();
        $next = $last + 1;

        $db->prepare('UPDATE document_sequences SET last_number = ? WHERE doc_type = ? AND seq_year = ?')
           ->execute([$next, $docType, $year]);

        if ($ownTransaction) {
            $db->commit();
        }
        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Sequential customer code: CUS-000001 (year-independent).
 */
function next_customer_code(?PDO $db = null): string
{
    $db = $db ?: getDB();
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $db->prepare(
            "INSERT INTO document_sequences (doc_type, seq_year, prefix, last_number)
             VALUES ('customer', 0, 'CUS', 0)
             ON DUPLICATE KEY UPDATE doc_type = doc_type"
        )->execute();
        $stmt = $db->prepare("SELECT last_number FROM document_sequences WHERE doc_type = 'customer' AND seq_year = 0 FOR UPDATE");
        $stmt->execute();
        $next = ((int)$stmt->fetchColumn()) + 1;
        // Defensively skip any code that already exists (e.g. seeded rows).
        $check = $db->prepare('SELECT COUNT(*) FROM customers WHERE customer_code = ?');
        while (true) {
            $code = sprintf('CUS-%06d', $next);
            $check->execute([$code]);
            if ((int)$check->fetchColumn() === 0) {
                break;
            }
            $next++;
        }
        $db->prepare("UPDATE document_sequences SET last_number = ? WHERE doc_type = 'customer' AND seq_year = 0")
           ->execute([$next]);
        if ($ownTransaction) {
            $db->commit();
        }
        return $code;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/*  Settings (cached)                                                 */
/* ------------------------------------------------------------------ */
function business_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    try {
        $db = getDB();
        $row = $db->query('SELECT * FROM business_settings WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch();
        $settings = $row ?: [];
    } catch (Throwable $e) {
        $settings = [];
    }
    return $settings;
}

function setting(string $key, $default = '')
{
    $s = business_settings();
    $val = $s[$key] ?? $default;
    return ($val === null || $val === '') ? $default : $val;
}

/* ------------------------------------------------------------------ */
/*  Brand / text logo                                                  */
/* ------------------------------------------------------------------ */
/**
 * Initials derived from the shop name (e.g. "Mughal Mobile Shop" -> "MMS").
 */
function shop_initials(?string $name = null): string
{
    $name = $name ?: setting('shop_name', APP_NAME);
    $name = trim(preg_replace('/[^A-Za-z0-9 ]/', ' ', (string)$name));
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 3) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'IMS';
}

/**
 * Render the shop name as a text logo mark (initials badge).
 */
function shop_logo_mark(string $class = 'app-brand-mark'): string
{
    return '<span class="' . e($class) . ' brand-initials" aria-hidden="true">' . e(shop_initials()) . '</span>';
}

/* ------------------------------------------------------------------ */
/*  Status labels / badges                                            */
/* ------------------------------------------------------------------ */
function agreement_status_label(string $s): string
{
    return [
        'draft'     => 'Draft',
        'active'    => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$s] ?? ucfirst($s);
}

function agreement_status_badge(string $s): string
{
    $map = [
        'draft'     => 'secondary',
        'active'    => 'success',
        'completed' => 'primary',
        'cancelled' => 'danger',
    ];
    $cls = $map[$s] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(agreement_status_label($s)) . '</span>';
}

function installment_status_label(string $s): string
{
    return [
        'pending'        => 'Pending',
        'partially_paid' => 'Partially Paid',
        'paid'           => 'Paid',
        'overdue'        => 'Overdue',
    ][$s] ?? ucfirst($s);
}

function installment_status_badge(string $s): string
{
    $map = [
        'pending'        => 'secondary',
        'partially_paid' => 'warning',
        'paid'           => 'success',
        'overdue'        => 'danger',
    ];
    $cls = $map[$s] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(installment_status_label($s)) . '</span>';
}

function payment_method_label(string $m): string
{
    return [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'mobile_wallet' => 'Mobile Wallet',
        'cheque'        => 'Cheque',
        'other'         => 'Other',
    ][$m] ?? ucfirst($m);
}

function payment_type_label(string $t): string
{
    return [
        'advance'     => 'Advance',
        'installment' => 'Installment',
        'other'       => 'Other',
    ][$t] ?? ucfirst($t);
}

/* ------------------------------------------------------------------ */
/*  Pagination helper                                                 */
/* ------------------------------------------------------------------ */
function paginate(int $total, int $perPage, int $page): array
{
    $perPage = max(1, $perPage);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
    ];
}

function pagination_links(array $p, array $queryParams = []): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $build = function (int $page) use ($queryParams) {
        $queryParams['page'] = $page;
        return '?' . http_build_query($queryParams);
    };
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($p['page'] <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($build($p['page'] - 1)) . '">Prev</a></li>';
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($build(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item' . ($i === $p['page'] ? ' active' : '') . '"><a class="page-link" href="' . e($build($i)) . '">' . $i . '</a></li>';
    }
    if ($end < $p['pages']) {
        if ($end < $p['pages'] - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e($build($p['pages'])) . '">' . $p['pages'] . '</a></li>';
    }
    $html .= '<li class="page-item' . ($p['page'] >= $p['pages'] ? ' disabled' : '') . '"><a class="page-link" href="' . e($build($p['page'] + 1)) . '">Next</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

/* ------------------------------------------------------------------ */
/*  Upload handling                                                   */
/* ------------------------------------------------------------------ */
function upload_error_message(int $code): string
{
    return [
        UPLOAD_ERR_INI_SIZE   => 'File is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File is larger than the allowed size.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION  => 'An extension stopped the upload.',
    ][$code] ?? 'Unknown upload error.';
}

/**
 * Handle an uploaded file securely.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function handle_upload(string $field, string $subDir, array $existing = []): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if (isset($existing['remove']) && $existing['remove']) {
            if (!empty($existing['path'])) {
                delete_upload($existing['path']);
            }
            return ['ok' => true, 'path' => null];
        }
        return ['ok' => true, 'path' => $existing['path'] ?? null];
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => upload_error_message((int)$file['error'])];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'File exceeds the maximum allowed size of ' . round(MAX_UPLOAD_BYTES / 1048576, 1) . ' MB.'];
    }

    $allowedExt = array_filter(array_map('trim', explode(',', strtolower(ALLOWED_UPLOAD_EXT))));
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', $allowedExt) . '.'];
    }

    // Verify the real MIME type.
    $mimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
    ];
    $realMime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $realMime = (string)finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        }
    }
    if ($realMime !== '' && isset($mimeMap[$ext]) && !in_array($realMime, $mimeMap[$ext], true)) {
        return ['ok' => false, 'error' => 'File content does not match its extension.'];
    }

    $subDir = trim(str_replace(['..', '\\'], ['', '/'], $subDir), '/');
    $targetDir = rtrim(UPLOAD_DIR, '/\\') . '/' . $subDir;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create the upload directory.'];
    }
    @chmod($targetDir, 0775);

    $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $target = $targetDir . '/' . $name;

    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Failed to store the uploaded file.'];
    }
    @chmod($target, 0644);

    if (!empty($existing['path'])) {
        delete_upload($existing['path']);
    }

    return ['ok' => true, 'path' => $subDir . '/' . $name];
}

function delete_upload(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $relativePath = str_replace(['..', '\\'], ['', '/'], $relativePath);
    $full = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($relativePath, '/');
    $real = realpath($full);
    $root = realpath(UPLOAD_DIR);
    if ($real && $root && strpos($real, $root) === 0 && is_file($real)) {
        @unlink($real);
    }
}

function upload_url(?string $relativePath): string
{
    if (!$relativePath) {
        return '';
    }
    return base_url('uploads/serve.php?f=' . urlencode($relativePath));
}

/* ------------------------------------------------------------------ */
/*  Financial calculations                                            */
/* ------------------------------------------------------------------ */
/**
 * Build an equal installment schedule.
 * The last installment absorbs any rounding difference so the schedule
 * total exactly matches the financed balance.
 *
 * @return array<int, array{due_date:string, amount:float}>
 */
function build_equal_schedule(float $balance, int $count, string $firstDueDate, string $frequency = 'monthly', int $dueDay = 0): array
{
    $schedule = [];
    if ($count <= 0) {
        return $schedule;
    }
    $balance = round_money($balance);
    $base = round_money($balance / $count);
    $accumulated = 0.0;

    for ($i = 1; $i <= $count; $i++) {
        if ($i < $count) {
            $amount = $base;
        } else {
            $amount = round_money($balance - $accumulated); // absorbs rounding difference
        }
        $accumulated = round_money($accumulated + $amount);
        $schedule[] = [
            'due_date' => add_frequency($firstDueDate, $i - 1, $frequency, $dueDay),
            'amount'   => $amount,
        ];
    }
    return $schedule;
}

/**
 * Add $n periods to a date, honouring a fixed day-of-month for monthly.
 */
function add_frequency(string $startDate, int $n, string $frequency, int $dueDay = 0): string
{
    $ts = strtotime($startDate);
    if ($ts === false) {
        $ts = time();
    }

    if ($frequency === 'weekly') {
        return date('Y-m-d', strtotime("+{$n} week", $ts));
    }
    if ($frequency === 'biweekly') {
        return date('Y-m-d', strtotime('+' . ($n * 2) . ' week', $ts));
    }

    // Monthly: keep the requested due day, clamping to the last valid day.
    $day = $dueDay > 0 ? $dueDay : (int)date('j', $ts);
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts) + $n;
    while ($month > 12) {
        $month -= 12;
        $year++;
    }
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $useDay = min($day, $lastDay);
    return sprintf('%04d-%02d-%02d', $year, $month, $useDay);
}

/**
 * Recalculate an agreement's statuses/remaining from its installments and
 * non-void payments. Returns [paid_total, remaining_balance].
 */
function recalculate_agreement(PDO $db, int $agreementId): array
{
    $stmt = $db->prepare('SELECT agreed_sale_price, additional_charges, discount, advance_payment FROM agreements WHERE id = ?');
    $stmt->execute([$agreementId]);
    $ag = $stmt->fetch();
    if (!$ag) {
        return [0.0, 0.0];
    }

    $paid = (float)$db->query(
        'SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a
         JOIN payments p ON p.id = a.payment_id
         WHERE p.agreement_id = ' . (int)$agreementId . " AND p.status = 'active'"
    )->fetchColumn();

    $advance = (float)$ag['advance_payment'];
    $salePrice = (float)$ag['agreed_sale_price'];
    $charges = (float)$ag['additional_charges'];
    $discount = (float)$ag['discount'];
    // Advance is a separate, agreed upfront amount recorded on the agreement.
    $remaining = round_money($salePrice + $charges - $discount - $advance - $paid);
    if ($remaining < 0) {
        $remaining = 0.0;
    }

    $db->prepare('UPDATE agreements SET remaining_balance = ? WHERE id = ?')->execute([$remaining, $agreementId]);

    return [round_money($paid), $remaining];
}

/**
 * Recompute statuses for all installments of an agreement based on paid_amount.
 */
function refresh_installment_statuses(PDO $db, int $agreementId): void
{
    $today = date('Y-m-d');
    $stmt = $db->prepare('SELECT id, scheduled_amount, paid_amount, due_date, status FROM installments WHERE agreement_id = ?');
    $stmt->execute([$agreementId]);
    $rows = $stmt->fetchAll();
    $upd = $db->prepare('UPDATE installments SET remaining_amount = ?, status = ?, paid_at = ? WHERE id = ?');

    foreach ($rows as $r) {
        $scheduled = (float)$r['scheduled_amount'];
        $paid = (float)$r['paid_amount'];
        $remaining = round_money(max(0, $scheduled - $paid));

        if ($paid >= $scheduled && $scheduled > 0) {
            $status = 'paid';
            $paidAt = $r['status'] === 'paid' ? null : date('Y-m-d H:i:s');
        } elseif ($paid > 0) {
            $status = 'partially_paid';
            $paidAt = null;
        } elseif ($r['due_date'] < $today) {
            $status = 'overdue';
            $paidAt = null;
        } else {
            $status = 'pending';
            $paidAt = null;
        }
        $upd->execute([$remaining, $status, $paidAt, $r['id']]);
    }
}

/**
 * Mark overdue installments across the database. Safe to call often.
 */
function mark_overdue_installments(PDO $db): void
{
    $db->exec(
        "UPDATE installments
         SET status = 'overdue'
         WHERE status IN ('pending','partially_paid')
           AND due_date < CURDATE()
           AND agreement_id IN (SELECT id FROM agreements WHERE status = 'active')"
    );
}

/**
 * Total paid (allocated) on an agreement, excluding voided payments.
 */
function agreement_paid_total(PDO $db, int $agreementId): float
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(a.amount),0)
         FROM payment_allocations a
         JOIN payments p ON p.id = a.payment_id
         WHERE p.agreement_id = ? AND p.status = 'active'"
    );
    $stmt->execute([$agreementId]);
    return (float)$stmt->fetchColumn();
}

/* ------------------------------------------------------------------ */
/*  CSRF-safe CSV output                                              */
/* ------------------------------------------------------------------ */
function csv_cell($value): string
{
    $value = (string)$value;
    // Prevent CSV formula injection.
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}
