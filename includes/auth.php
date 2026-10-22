<?php
// Central Authentication Module for EDMS
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Return valid roles supported in the EDMS database.
 */
function available_roles(): array
{
    return ['viewer', 'technician', 'engineer', 'admin', 'super admin'];
}

/**
 * Determine if current user possesses a specific role.
 */
function has_role(string ...$roles): bool
{
    $currentRole = current_role();
    return in_array($currentRole, array_map('strtolower', $roles), true);
}

/**
 * Enforce role requirement or redirect back to explorer with an alert.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        log_activity('auth.access_denied', 'system', null, [
            'reason'         => 'Page role restriction',
            'required_roles' => $roles,
            'page'           => basename($_SERVER['PHP_SELF'] ?? '')
        ], 'failure');
        redirect_to('explorer', 'error', 'Access denied. You do not possess the required credentials to access this section.');
    }
}

