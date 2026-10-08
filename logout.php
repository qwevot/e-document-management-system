<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

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
