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

/**
 * Generate 1-2 character initials for user avatars.
 */
function get_initials(?string $name): string
{
    $name = trim($name ?? '');
    if ($name === '') {
        return 'U';
    }
    $parts = preg_split('/\s+/', $name);
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
    }
    return $initials !== '' ? $initials : 'U';
}

