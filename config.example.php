<?php
/**
 * ---------------------------------------------------------------------
 *  Installment Management System - Configuration EXAMPLE
 * ---------------------------------------------------------------------
 *  1. Copy this file to `config.php`
 *  2. Fill in your real database credentials
 *  3. NEVER commit `config.php` to source control
 *
 *  On shared hosting, `config.php` is protected from direct web access
 *  by the `.htaccess` rules shipped with the project.
 * ---------------------------------------------------------------------
 */

/* ------------------------- Database ------------------------------- */
define('DB_HOST', 'localhost');            // Hostinger usually uses 'localhost'
define('DB_NAME', 'installment_db');       // your database name
define('DB_USER', 'root');                 // your database user
define('DB_PASS', '');                      // your database password
define('DB_CHARSET', 'utf8mb4');

/* ------------------------- Application ---------------------------- */
define('APP_NAME', 'Installment Management System');
// Absolute base URL WITHOUT trailing slash. Leave '' to auto-detect.
define('APP_URL', '');
// Set to true ONLY during development. Must be false in production.
define('APP_DEBUG', false);
// Default timezone for the business (Pakistan).
define('APP_TIMEZONE', 'Asia/Karachi');

/* ------------------------- Security ------------------------------- */
define('SESSION_NAME', 'IMSSESSID');
define('LOGIN_MAX_ATTEMPTS', 5);     // failed attempts allowed in the window
define('LOGIN_WINDOW_MINUTES', 15);  // window / lockout duration
define('MIN_PASSWORD_LENGTH', 10);

/* ------------------------- Uploads -------------------------------- */
// Absolute filesystem path to private storage (outside web root ideal).
define('UPLOAD_DIR', __DIR__ . '/storage/uploads');
define('MAX_UPLOAD_BYTES', 5242880); // 5 MB
define('ALLOWED_UPLOAD_EXT', 'jpg,jpeg,png,webp,pdf');

/* ------------------------- Documents ------------------------------ */
// Optional Urdu .ttf placed in assets/fonts/ (licensing is your duty).
define('URDU_FONT_FILE', 'jameel-noori-nastaleeq.ttf');
// Optional server-side PDF generation (NOT required for core operation).
define('PDF_LIBRARY_ENABLED', false);
