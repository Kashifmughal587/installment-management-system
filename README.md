# Installment Management System

A complete, secure, responsive **PHP + MySQL** web application for a local Pakistani retail business to manage customers, products, installment agreements, payments, receipts, and business reports from a single owner/staff dashboard.

The system is strictly an **owner/staff-side management system**. Customers do **not** have login accounts or portal access in Version 1.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Creating the MySQL Database](#2-creating-the-mysql-database)
3. [Importing the Schema](#3-importing-the-schema)
4. [Configuring Database Credentials](#4-configuring-database-credentials)
5. [Creating the Initial Owner Account](#5-creating-the-initial-owner-account)
6. [Uploading to Hostinger (Shared Hosting)](#6-uploading-to-hostinger-shared-hosting)
7. [PHP Session and Upload Settings](#7-php-session-and-upload-settings)
8. [Writable Upload Directories](#8-writable-upload-directories)
9. [Enabling HTTPS](#9-enabling-https)
10. [Logging In and Changing the Owner Password](#10-logging-in-and-changing-the-owner-password)
11. [Adding Additional Authorized Users](#11-adding-additional-authorized-users)
12. [Printing Agreements and Receipts](#12-printing-agreements-and-receipts)
13. [Configuring Urdu Fonts](#13-configuring-urdu-fonts)
14. [Common Issues and Troubleshooting](#14-common-issues-and-troubleshooting)
15. [Backup and Restore](#15-backup-and-restore)
16. [Accounting Rules and Metric Definitions](#16-accounting-rules-and-metric-definitions)
17. [Security Model](#17-security-model)
18. [Manual Testing Checklist](#18-manual-testing-checklist)
19. [Project Structure](#19-project-structure)
20. [Out of Scope for Version 1](#20-out-of-scope-for-version-1)

---

## 1. Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.1 | 8.2 / 8.3 |
| Extensions | PDO, PDO_Mbstring, PDO_Pdo, Mbstring, Fileinfo, Session, JSON, Tokenizer | - |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.4 | MySQL 8.0 / MariaDB 10.6 |
| Web Server | Apache 2.4 (shared hosting) | Apache with mod_rewrite |
| Browser | Any modern evergreen browser | Chrome / Edge / Firefox |

**Not required:** Laravel, Symfony, Node.js, npm, Composer, SSH, shell access, `exec()`, `shell_exec()`, external cloud services, or Linux binaries for core operation.

**Local development:** XAMPP, WAMP, Laragon, or MAMP all work. The application runs from the web root with no build step.

---

## 2. Creating the MySQL Database

### On Hostinger (hPanel)

1. Log in to **hPanel** → **Databases** → **MySQL Databases**.
2. Enter a database name (e.g. `installment_db`).
3. Create a database user with a strong password.
4. **Add the user to the database** and grant **All Privileges**.
5. Note down:
   - Hostname (usually `localhost` on Hostinger)
   - Database name
   - Username
   - Password

### Locally (phpMyAdmin)

1. Open `http://localhost/phpmyadmin`.
2. **New** → enter database name → set collation to `utf8mb4_general_ci` → **Create**.

> The schema file also creates the database if it does not exist, so you may import it while no database is selected (see next section).

---

## 3. Importing the Schema

1. Open **phpMyAdmin**.
2. Select your database (or leave unselected if importing the full schema file).
3. Click the **Import** tab.
4. Choose `sql/schema.sql` from this project.
5. Ensure format is **SQL** and character set is **utf8mb4**.
6. Click **Go**.

The schema creates all tables, foreign keys, indexes, seed sequences, development sample data, and a placeholder owner record.

**Expected tables:**

```
users, business_settings, customers, guarantors, agreements,
agreement_products, agreement_guarantors, installments, payments,
payment_allocations, agreement_amendments, document_sequences, audit_logs
```

**Development seed data** includes a demo owner account and fictional customers/agreements so the dashboard is not empty. **Delete or replace all seed rows before production use.** No production password is shipped in the SQL file.

---

## 4. Configuring Database Credentials

1. Copy `config.example.php` to `config.php`:

   ```bash
   cp config.example.php config.php
   ```

2. Edit `config.php` and fill in your credentials:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'u123456789_installment');
   define('DB_USER', 'u123456789_owner');
   define('DB_PASS', 'your-strong-password');
   ```

3. **Protect the file.** On shared hosting, keep `config.php` *outside* the public web root if your plan allows it, or rely on the provided `.htaccess` rules that deny direct HTTP access to `config.php`, `config.example.php`, and the `sql/` directory.

> `config.php` is listed in `.gitignore`. Never commit real credentials.

**Optional constants** in `config.php`:

| Constant | Purpose | Default |
|----------|---------|---------|
| `APP_NAME` | Display name in the UI | `Installment Management System` |
| `APP_URL` | Absolute base URL (no trailing slash) | auto-detected |
| `APP_DEBUG` | Show detailed errors | `false` in production |
| `UPLOAD_DIR` | Absolute path for private uploads | `__DIR__ . '/storage/uploads'` |
| `MAX_UPLOAD_BYTES` | Max upload size | `5242880` (5 MB) |
| `LOGIN_MAX_ATTEMPTS` | Failed logins per window | `5` |
| `LOGIN_LOCK_MINUTES` | Lockout duration | `15` |
| `SESSION_NAME` | Cookie name | `IMSSESSID` |

---

## 5. Creating the Initial Owner Account

The application ships with **no default production password**. Choose one of the following methods.

### Method A - Setup script (recommended)

1. Ensure `config.php` is configured and the schema is imported.
2. Visit `https://your-domain.com/setup.php`.
3. Enter full name, username, email, and a strong password (min 10 characters).
4. The script hashes the password with `password_hash()` and creates the `owner` account.
5. **The setup page self-disables** once any active user exists, and is also blocked when `config.php` still holds default/empty credentials.

### Method B - Generate the hash manually (no script)

1. On any PHP 8.1 machine (or via a one-time local file), run:

   ```php
   <?php
   echo password_hash('YourStrongPasswordHere', PASSWORD_DEFAULT);
   ```

2. In phpMyAdmin, open the `users` table → **Insert**:

   | Column | Value |
   |--------|-------|
   | `full_name` | Your Name |
   | `username` | `owner` |
   | `email` | `you@example.com` *(nullable)* |
   | `password_hash` | *(paste the `$2y$...` hash)* |
   | `role` | `owner` |
   | `status` | `active` |
   | `created_at` | `NOW()` |

3. Log in at `login.php` and change the password immediately.

### Method C - CLI one-liner (if you have PHP CLI locally)

```bash
php -r "echo password_hash('YourStrongPasswordHere', PASSWORD_DEFAULT), PHP_EOL;"
```

> **Never** store a plaintext password in the database, in SQL files, or in source control.

---

## 6. Uploading to Hostinger (Shared Hosting)

No SSH or Composer required.

1. **Build the package locally** - zip the project contents (the folder itself, not only its parent).
2. In hPanel → **Files** → **File Manager**, navigate to `public_html`.
3. Upload the zip and extract it so that `dashboard.php` ends up at `public_html/dashboard.php` (or in a subfolder such as `public_html/installment/`).
4. Import `sql/schema.sql` via **Databases → phpMyAdmin**.
5. Create `config.php` in the app root via File Manager → **New File**, then paste your credentials.
6. Set directory permissions (see next section).
7. Visit the site, run `setup.php` (or insert the owner manually), then log in.
8. Delete `setup.php` from the server after the owner account is created.

**PHP version:** hPanel → **PHP Configuration** → select **PHP 8.1 or newer**.

**Recommended PHP settings (hPanel → PHP Configuration):**

| Setting | Value |
|---------|-------|
| `memory_limit` | `256M` |
| `upload_max_filesize` | `8M` |
| `post_max_size` | `10M` |
| `max_execution_time` | `60` |
| `date.timezone` | `Asia/Karachi` |
| `default_charset` | `UTF-8` |

---

## 7. PHP Session and Upload Settings

Sessions use hardened defaults set by the application in `includes/auth.php`:

| Setting | Value |
|---------|-------|
| `session.use_strict_mode` | `1` |
| `session.use_only_cookies` | `1` |
| `session.cookie_httponly` | `1` |
| `session.cookie_samesite` | `Lax` |
| `session.cookie_secure` | `1` when the request is HTTPS |
| Session ID regeneration | On every successful login and on privilege change |
| Session lifetime | `session.gc_maxlifetime` ≥ 3600 (2 hours recommended) |

If your host provides a `php.ini` or **hPanel → PHP Configuration**:

```ini
session.gc_maxlifetime = 7200
session.cookie_secure  = 1      ; only after HTTPS is enabled
file_uploads           = On
upload_max_filesize    = 8M
post_max_size          = 10M
max_input_vars         = 3000   ; helps with long agreement forms
```

On Apache shared hosting you may alternatively place the same values in a `.user.ini` file in the app root (works on most Hostinger plans):

```ini
upload_max_filesize = 8M
post_max_size = 10M
max_input_vars = 3000
date.timezone = Asia/Karachi
```

---

## 8. Writable Upload Directories

Private uploads (customer photographs, CNIC images, payment attachments) are stored under:

```
storage/
├── uploads/
│   ├── customers/
│   ├── agreements/
│   └── payments/
└── .htaccess          ← denies all direct HTTP access
```

Set permissions after upload:

```bash
chmod 755 storage
chmod 755 storage/uploads
chmod 755 storage/uploads/customers storage/uploads/agreements storage/uploads/payments
```

If uploads fail with a permission error, temporarily use `775` on `storage/uploads` and its subfolders, then harden to `755` and confirm the web-server user owns the files.

**Why private?** Uploaded documents are served only through an authorized PHP proxy (`uploads/serve.php`) that verifies the session, the record ownership, and the file path. Direct URLs to uploads return `403`.

**Allowed file types:** `jpg`, `jpeg`, `png`, `webp`, `pdf`. Validation checks extension **and** the real MIME type via `finfo`, plus a size cap (`MAX_UPLOAD_BYTES`).

---

## 9. Enabling HTTPS

1. In hPanel → **Security → SSL**, install the free **Let's Encrypt** certificate for your domain.
2. Enable **Force HTTPS** (hPanel → **Domains → Force HTTPS**), or add to `.htaccess`:

   ```apache
   RewriteEngine On
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

3. Set the app URL in `config.php`:

   ```php
   define('APP_URL', 'https://your-domain.com');
   ```

4. Once HTTPS is active, the application automatically sends `Secure` cookies and marks login as secure-only.

**Local development over plain HTTP** works fine; cookies then omit the `Secure` flag automatically.

---

## 10. Logging In and Changing the Owner Password

1. Go to `https://your-domain.com/login.php` (the root `index.php` redirects there).
2. Enter your **username or email** and **password**.
3. Use the **show/hide password** toggle to verify typing.
4. After 5 failed attempts within 15 minutes, the account/IP is temporarily locked (configurable in `config.php`).
5. On success the session ID is regenerated and you land on the dashboard.

**Change your password:**

1. Top navigation → **Account → Change Password** (or `change_password.php`).
2. Enter current password, new password, confirm new password.
3. Minimum length is **10 characters**; the password must differ from the current one.
4. The session is regenerated and all other sessions for the user are invalidated.

**Logout:** top navigation → **Logout** (`logout.php`). The session is fully destroyed and cookies are cleared; protected pages then redirect to the login screen.

---

## 11. Adding Additional Authorized Users

Only an **owner** can manage users.

1. Log in as the owner.
2. Sidebar → **User Management** (`settings/users.php`).
3. **Add User** → full name, username, email (optional), role, status, initial password.
4. Use **Edit** to change profile details, reset a password, or change status.
5. **Activate / Deactivate** toggles access without deleting history.

### Roles and permissions

| Capability | `owner` | `staff` |
|------------|:-------:|:-------:|
| View dashboard & reports | ✅ | ✅ |
| Customers CRUD | ✅ | ✅ |
| Agreements create/edit (per policy) | ✅ | ✅ |
| Record payments | ✅ | ✅ (if granted) |
| Void payments | ✅ | ❌ |
| Cancel / amend agreements | ✅ | ❌ |
| Business settings | ✅ | ❌ |
| User management | ✅ | ❌ |
| Change own password | ✅ | ✅ |

Authorization is enforced **on the server** on every route and action (`require_login()`, `require_role()`, `require_permission()`), not merely by hiding menu items. Every financial record stores `created_by` referencing `users.id`.

There is **no public registration**.

---

## 12. Printing Agreements and Receipts

### Agreements

- `agreements/view.php?id=N` → **Print / PDF** button → opens `agreements/print.php?id=N`.
- The print template is a dedicated A4 HTML document with print CSS:
  - `@page { size: A4; margin: 12mm; }`
  - Bordered tables with **repeating headers** (`thead { display: table-header-group; }`)
  - `break-inside: avoid` on rows and signature blocks
  - Multi-page schedules continue cleanly without clipping rows
  - Application sidebar/topbar are hidden in print output
- Use the browser's **Print → Save as PDF** (baseline, always available).
- Reprinting an existing agreement **never** creates a duplicate agreement or number.

The print document includes: header with logo/business details, agreement number & date, owner details, customer details, guarantor details, product table, financial summary, full installment schedule, editable terms, acceptance statement, signature blocks (owner / customer / guarantor / witnesses), and page numbers.

### Receipts

- `payments/receipt.php?id=N` → business details, receipt number, date, customer, agreement, amount, method, reference, **previous balance / paid / remaining balance** at the time of payment, received-by, signature area, acknowledgement footer.
- Historical receipts are **snapshotted** at payment time; later transactions do not alter an already-printed receipt.

### Optional server-side PDF library

Server-side PDF generation is **optional** and not required for core operation. If you later add a pure-PHP library (e.g. a DOMPDF-style package uploaded without Composer):

1. Place it under `vendor/` (outside public access if possible).
2. Enable it in `config.php` with `define('PDF_LIBRARY_ENABLED', true);`.
3. The print pages then offer an extra **"Download PDF"** button; the HTML/CSS print route remains the fallback.

Because the application must run on shared hosting, **no dependency on a Linux binary (wkhtmltopdf, Chromium, `exec()`)** exists anywhere in the codebase.

---

## 13. Configuring Urdu Fonts

Documents support **English (LTR)** and **Urdu (RTL)** via a per-document language setting in **Business Settings**.

### What works out of the box

- UTF-8 / `utf8mb4` end to end (connection, tables, output escaping).
- `dir="rtl"` layout and `lang="ur"` attribute on agreement/receipt templates when Urdu is selected.
- System fallback stack: `"Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", "Nafees Nastaleeq", "Segoe UI", Arial`.

### Installing Jameel Noori Nastaleeq (or any licensed Nastaleeq font)

1. Obtain a **legally licensed** copy of the `.ttf` file (licensing is your responsibility).
2. Upload it to:

   ```
   assets/fonts/JameelNooriNastaleeq.ttf
   ```

3. Declare it in `assets/css/document-fonts.css` (the file already contains the `@font-face` rule; it degrades gracefully if the file is absent):

   ```css
   @font-face {
     font-family: "Jameel Noori Nastaleeq";
     src: url("../fonts/JameelNooriNastaleeq.ttf") format("truetype");
     font-display: swap;
   }
   ```

4. No system font installation is needed - the font is loaded by the browser, which also makes **Print → Save as PDF** embed it.

> **Important:** shared hosting does not let you install fonts at the OS level, so the browser-loaded `@font-face` approach is the supported method. Verify printed output for correct Urdu joining, direction, and glyph rendering before relying on it for signed documents. Test mixed English/Urdu strings (names, addresses, terms).

---

## 14. Common Issues and Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| `PDOConnectionException` on load | Wrong DB credentials / DB not created | Re-check `config.php`; confirm the DB exists and the user has privileges |
| Blank page | PHP fatal error with display_errors off | Set `APP_DEBUG` to `true` temporarily, check `logs/app.log` |
| `Permission denied` on upload | Storage not writable | `chmod 755 -R storage/uploads` or set owner to the web user |
| Upload rejected "invalid file" | MIME/extension mismatch or size over limit | Use jpg/png/webp/pdf ≤ 5 MB; raise `upload_max_filesize` |
| 403 on image URLs | Direct access blocked (intended) | Access documents through the app, not raw URLs |
| Redirect loop after enabling HTTPS | Mixed cookie settings | Set `APP_URL` to the `https://` URL; clear browser cookies |
| Login always fails after 5 tries | Rate limiter active | Wait 15 minutes or clear the `login_attempts` state; check server clock/timezone |
| Garbled Urdu text | Wrong DB/table collation | Import schema with `utf8mb4`; ensure `<meta charset="utf-8">` present |
| Tables missing after import | Partial import / SQL mode errors | Re-import `sql/schema.sql`; check phpMyAdmin import log for errors |
| `Setup page unavailable` | Owner already exists (intended) | Use `login.php`, or create users from **User Management** |
| Session lost randomly | Short GC / insecure cookie on HTTPS | Raise `session.gc_maxlifetime`; confirm HTTPS + `APP_URL` |
| Print layout broken | Browser print scaling | Use A4, 100% scale, enable "Background graphics", margins "Default" |
| `Max input vars` warnings on agreement form | Too many POST inputs | Set `max_input_vars = 3000` in `.user.ini` |
| Date shows wrong day | Timezone mismatch | Set `date.timezone = Asia/Karachi` |

**Error logging:** with `APP_DEBUG = false`, errors are written to `logs/app.log` (web-accessible only via PHP; `.htaccess` blocks direct reads). Database credentials are never printed to the screen.

---

## 15. Backup and Restore

### Database

**Backup (phpMyAdmin):**

1. Select the database → **Export**.
2. Method: **Quick**, format: **SQL**, encoding: **utf8mb4**.
3. **Go** → save `backup-YYYY-MM-DD.sql`.

**Backup (manual SQL):**

```sql
SELECT * INTO OUTFILE '/tmp/full_backup.csv' FROM agreements;
```

(or use your host's scheduled backup tool in hPanel → **Files → Backups**.)

**Restore:**

1. phpMyAdmin → **Import** → choose the `.sql` file → **Go**.
2. Or from a shell (if available): `mysql -u USER -p DBNAME < backup.sql`

**Recommended schedule:** weekly full exports + before every upgrade. Keep backups off the server.

### Uploaded files

Zip and download the `storage/uploads/` folder:

```bash
zip -r uploads-backup-YYYY-MM-DD.zip storage/uploads
```

On hPanel: File Manager → select `storage/uploads` → **Compress** → download.

**Restore:** upload and extract over `storage/uploads/`, preserving the folder structure (`customers/`, `agreements/`, `payments/`).

### Full restore checklist

1. Create a fresh database.
2. Import the schema (or your backup `.sql`).
3. Restore `storage/uploads/`.
4. Ensure `config.php` points at the restored database.
5. Log in and verify dashboard totals, an agreement print, and a receipt print.

---

## 16. Accounting Rules and Metric Definitions

All dashboard and report figures are computed from live database rows - no hardcoded values.

| Metric | Definition |
|--------|------------|
| **Total Agreed Installment Sales** | `SUM(agreed_sale_price)` of agreements where `status IN ('draft','active','completed')`. **Cancelled agreements excluded.** |
| **Total Advance Payments Collected** | `SUM(advance_payment)` on non-cancelled agreements **plus** payments flagged as `advance` at agreement level. Advances are recorded once - never double counted. |
| **Total Installment Payments Collected** | `SUM(payment_allocations.amount)` for allocations applied to installments of non-cancelled agreements, excluding voided payments. |
| **Total Amount Collected** | Advance payments + installment payments (excluding voided/reversed records). |
| **Total Outstanding Balance** | `SUM(remaining_balance)` where `remaining_balance = agreed_sale_price + additional_charges − discount − advance_payment − non-advance payments applied`. |
| **Overdue Installment Amount** | `SUM(remaining_amount)` of installments with `due_date < CURDATE()` and status in `pending/partially_paid`, on active agreements. |
| **Installments Due Today** | Installments with `due_date = CURDATE()` and unpaid remainder > 0. |
| **Installments Due Soon** | Unpaid installments with `due_date` between today and today + `DUE_SOON_DAYS` (default 7). |

**Core formula (documented and enforced server-side):**

```
Remaining Balance = Agreed Installment Sale Price − Advance Payment − Other Payments Applied
```

- The **agreed installment sale price** is the total amount payable for the product(s), including any charges already incorporated into it. Charges are never added twice.
- Cash price and installment sale price are **separate fields**. The system never invents a profit percentage or financing charge - the owner enters both values explicitly.
- Installment schedules must sum **exactly** to the scheduled balance; rounding differences are absorbed by the **last installment**.
- **Voided payments** remain in history with reason and user attribution and are excluded from collected totals; they are never hard-deleted.
- **Refunds/reversals** are recorded as negative-allocation correction entries handled by the same controlled process.
- Agreements: `draft` → `active` → `completed`, or `cancelled` (with recorded reason). Cancelled agreements retain historical payments and documents but are excluded from active sales totals.
- Editing financial terms of an **active** agreement that already has payments requires an **authorized amendment** (`agreement_amendments`), which preserves previous values and requires explicit confirmation before recalculating the schedule.

---

## 17. Security Model

| Control | Implementation |
|---------|----------------|
| SQL injection | PDO prepared statements everywhere; no string-interpolated SQL |
| XSS | `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` via `e()` helper on all output |
| CSRF | Per-session token on every state-changing form (`csrf_field()`, `csrf_verify()`) |
| Authentication | `password_hash()` / `password_verify()` (bcrypt) |
| Session fixation | `session_regenerate_id(true)` on login, logout, and privilege change |
| Brute force | `login_attempts` rate limiting (5 attempts / 15 minutes, configurable) |
| Authorization | `require_role()` / `require_permission()` on every protected route; owner-only actions server-side |
| Uploads | Extension + `finfo` MIME sniffing + size cap; stored outside web reach with `.htaccess` deny |
| File serving | Authorized proxy only; path traversal blocked with `realpath()` checks |
| Secrets | `config.php` denied by `.htaccess`; never echoed; not committed |
| Errors | `APP_DEBUG=false` in production → logged, generic message shown; no stack traces or credentials |
| Financial integrity | DB transactions with rollback for payments, allocations, voids, and amendments |
| Audit trail | `audit_logs` records user, action, entity, before/after values, IP, timestamp |
| Headers | `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` |
| Uploads on agreements | Active-agreement financial edits blocked after payments without amendment workflow |

---

## 18. Manual Testing Checklist

Mark each item only after the workflow is actually completed and verified.

### Authentication

- [ ] Run `setup.php`, create the owner, confirm the page disables itself afterwards.
- [ ] Log in with username; log in with email; wrong password shows a generic error.
- [ ] Show/hide password toggle works.
- [ ] 5 rapid failed attempts trigger the lockout message; waiting clears it.
- [ ] After login, `session_id()` differs from before login (regeneration works).
- [ ] Log out → pressing Back on a protected page redirects to login.
- [ ] Change password: wrong current password rejected; short password rejected; success regenerates session and old password stops working.

### Users & permissions

- [ ] Owner creates a `staff` user and logs in as them.
- [ ] Staff cannot open `settings/business.php` or `settings/users.php` (direct URL → 403/redirect).
- [ ] Staff cannot void payments or cancel agreements.
- [ ] Deactivated user cannot log in.

### Customers

- [ ] Add a customer with only mandatory fields → saved with generated Customer ID.
- [ ] Add optional fields (DOB, income, photo, CNIC front/back) → uploads accepted.
- [ ] Reject an `.exe`/`.php` upload and an oversized file.
- [ ] Search by name, customer ID, phone, CNIC.
- [ ] Pagination and sorting work; status filter works.
- [ ] Duplicate CNIC/mobile triggers a warning.
- [ ] Customer with an agreement cannot be hard-deleted; archive/deactivate works.
- [ ] Customer profile shows agreements, payment history, outstanding balance.

### Agreements

- [ ] Create a **single-product** draft → agreement number `AGR-2026-000001` style generated.
- [ ] Create a **multi-product** agreement → line totals sum correctly.
- [ ] `Remaining Balance = Agreed Sale Price − Advance` verified on screen and in DB.
- [ ] Installment schedule preview totals equal the outstanding balance exactly.
- [ ] Rounding test: balance `10000` over `3` installments → `3333.33, 3333.33, 3333.34`.
- [ ] Short-month test: due day `31` in a 30-day month → consistent fallback day used.
- [ ] Custom (non-equal) installment amounts and dates accepted and validated.
- [ ] Scheduled amounts cannot exceed outstanding balance without authorized adjustment.
- [ ] Draft → Active workflow; cancellation requires a reason.
- [ ] Editing financial terms of an active agreement with payments forces the amendment flow and preserves old values.
- [ ] Two browser tabs creating agreements simultaneously never produce duplicate numbers.

### Documents

- [ ] Print agreement → A4 layout, borders, repeating headers, no app navigation.
- [ ] Long schedule flows to page 2+ without clipped rows; page numbers correct.
- [ ] Signature blocks present for owner / customer / guarantor / witnesses.
- [ ] Reprint the same agreement → same number, no duplicate record.
- [ ] Urdu document language → RTL layout, correct joining, Nastaleeq font applied when the `.ttf` is present.
- [ ] Mixed English/Urdu customer name prints correctly in both languages.

### Payments

- [ ] Full payment on an installment → status `Paid`, agreement balance reduced.
- [ ] Partial payment → status `Partially Paid`, remaining amount correct.
- [ ] Advance payment before the first due date recorded correctly (not double counted).
- [ ] Payment to a customer/agreement mismatch is rejected server-side.
- [ ] Zero/negative amount rejected; overpayment blocked without authorization.
- [ ] Duplicate submit (double click) does not create two payments.
- [ ] Multi-installment allocation in one payment works; totals reconcile.
- [ ] Forced DB failure mid-payment rolls back both payment and allocations.
- [ ] Void with reason → excluded from collections, still visible in history.
- [ ] Receipt shows previous balance, paid, remaining balance as of that payment.
- [ ] Receipt printed now still shows original amounts after later payments.

### Dashboard & reports

- [ ] Every card value matches a manual SQL query on live data.
- [ ] Total agreed sales ≠ total collected; advances counted once.
- [ ] Date filters change the figures.
- [ ] Due today / due soon / overdue lists match the definitions above.
- [ ] Cancelled agreements excluded from active sales totals.
- [ ] CSV export opens correctly in Excel with UTF-8 Urdu text.
- [ ] Print-friendly report hides navigation.
- [ ] Empty database → friendly empty states, no PHP warnings.

### Security & robustness

- [ ] Accessing `dashboard.php` while logged out redirects to login.
- [ ] A crafted POST without a CSRF token is rejected.
- [ ] Direct URL to `storage/uploads/...` returns 403.
- [ ] Direct URL to `config.php` and `sql/schema.sql` returns 403.
- [ ] `APP_DEBUG=false` hides SQL errors and credentials.
- [ ] `audit_logs` rows created for login, user changes, payment void, agreement cancel, settings save.

---

## 19. Project Structure

```
installment-management/
├── config.php                  ← created by you (not committed)
├── config.example.php
├── index.php                   ← redirects to login/dashboard
├── setup.php                   ← one-time owner creation (self-disables)
├── login.php
├── logout.php
├── dashboard.php
├── change_password.php
├── .htaccess                   ← hardening + HTTPS redirect
├── customers/
│   ├── index.php               ← list, search, filters, pagination
│   ├── add.php
│   ├── edit.php
│   └── view.php                ← profile, agreements, payments, balance
├── agreements/
│   ├── index.php
│   ├── add.php                 ← form + schedule preview
│   ├── edit.php
│   ├── view.php                ← details, schedule, actions
│   ├── schedule.php
│   └── print.php               ← A4 printable agreement
├── payments/
│   ├── index.php
│   ├── add.php
│   ├── view.php
│   └── receipt.php             ← printable receipt
├── reports/
│   └── index.php               ← filters, CSV export, print view
├── settings/
│   ├── business.php            ← shop profile, terms, prefixes, language
│   └── users.php               ← owner-only user management
├── includes/
│   ├── db.php                  ← PDO singleton, utf8mb4, exception mode
│   ├── auth.php                ← login/logout/session/rate limit/ACL
│   ├── csrf.php                ← token generation & verification
│   ├── functions.php           ← e(), validation, money, dates, sequences
│   ├── header.php
│   ├── sidebar.php             ← permission-filtered menu
│   └── footer.php
├── uploads/
│   └── serve.php               ← authorized private file proxy
├── assets/
│   ├── css/
│   │   ├── app.css             ← dashboard UI
│   │   ├── document.css        ← A4 agreement/receipt print styles
│   │   └── document-fonts.css  ← @font-face (Urdu)
│   ├── js/
│   │   ├── app.js
│   │   ├── agreement.js        ← totals + schedule preview
│   │   └── password.js
│   ├── img/
│   └── fonts/                  ← place licensed .ttf here
├── storage/
│   ├── uploads/
│   │   ├── customers/
│   │   ├── agreements/
│   │   └── payments/
│   └── .htaccess               ← deny all
├── logs/
│   └── app.log
├── sql/
│   └── schema.sql
└── README.md
```

Compatible with plain PHP shared hosting; no build tooling, no framework, no CLI requirement.

---

## 20. Out of Scope for Version 1

Intentionally **not** implemented (the schema stays extensible for a future release):

- Customer login / portal / self-registration
- Customer-submitted payment receipts or approval workflows
- Online payment gateway
- Automated WhatsApp or SMS messaging
- Mandatory external cloud services
- Accounting, tax, and inventory modules

---

## License & Fonts

Application code: use and modify for your business. Third-party fonts (e.g. Jameel Noori Nastaleeq) require their own license - obtain and install them yourself under `assets/fonts/`.
