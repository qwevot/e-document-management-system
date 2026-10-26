<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$valid = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    $valid = true;
} elseif (isset($_GET['token']) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_GET['token'])) {
    $valid = true;
}

if (!$valid) {
    redirect_to('explorer', 'error', 'Security validation failed (CSRF token invalid or expired).');
}

$currentUser = current_user();
if ($currentUser) {
    log_activity('auth.logout', 'user', (int)($currentUser['id'] ?? 0) ?: null, ['username' => $currentUser['username'] ?? ''], 'success');
}

$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . BASE_URL . '/login.php');
exit;
