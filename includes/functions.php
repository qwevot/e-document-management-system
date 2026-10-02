<?php
// Core Helper Functions

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/icons.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Escape HTML output safely.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Retrieve current logged in user data.
 */
function current_user(): array
{
    return $_SESSION['user'] ?? [
        'id'        => 1,
        'username'  => 'admin',
        'full_name' => 'System Administrator',
        'role'      => 'super admin',
        'avatar'    => null,
    ];
}

/**
 * Retrieve current user role.
 */
function current_role(): string
{
    $user = current_user();
    return strtolower($user['role'] ?? 'user');
}
