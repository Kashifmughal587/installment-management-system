<?php
/**
 * Authorized proxy for private uploaded files.
 * Files live under storage/uploads (denied to the web), and are streamed
 * here only for authenticated users. Path traversal is blocked.
 */

require_once __DIR__ . '/../includes/auth.php';

$relative = isset($_GET['f']) ? (string)$_GET['f'] : '';
$relative = str_replace('\\', '/', $relative);
$relative = ltrim($relative, '/');

if ($relative === '' || strpos($relative, '..') !== false) {
    http_response_code(400);
    exit('Invalid request.');
}

// Non-sensitive public branding (logo, etc.) may be shown on the login page.
$isBranding = strpos($relative, 'branding/') === 0;
if (!$isBranding) {
    require_login();
}

$root = realpath(UPLOAD_DIR);
$full = realpath(rtrim(UPLOAD_DIR, '/\\') . '/' . $relative);

if (!$root || !$full || strpos($full, $root) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];
if (!isset($types[$ext])) {
    http_response_code(403);
    exit('Forbidden.');
}

// Prevent MIME sniffing and force download for non-images.
$disposition = ($ext === 'pdf') ? 'attachment' : 'inline';

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($full);
exit;
