<?php
require_once __DIR__ . '/includes/auth.php';

// Best-effort audit of the logout before the session is destroyed.
if (is_logged_in()) {
    audit_log(current_user_id(), 'logout', 'user', current_user_id());
}

logout_user();
redirect('login.php');
