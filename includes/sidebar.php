<?php
require_once __DIR__ . '/functions.php';

$activeNav = $activeNav ?? 'explorer';
$role      = current_role();

// Primary workspace navigation items (Flat / Non-retractable)
$mainNavItems = [
    ['my-workspace', 'pages/my-workspace.php', 'My Workspace', 'workspace'],
    ['explorer',     'pages/explorer.php',     'Document Explorer', 'explorer'],
    ['sign-documents', 'pages/sign-documents.php', 'Sign Documents', 'sign-documents'],
];

// Administration items (Retractable group)
$adminNavItems = [];
if (in_array($role, ['super admin', 'admin'], true)) {
    $adminNavItems = [
        ['archives',    'pages/archives.php',     'Archives', 'archive'],
        ['users',       'pages/users.php',        'Users & Roles', 'users'],
        ['system-logs', 'pages/system-logs.php',  'System Logs', 'system-logs'],
    ];
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/pages/my-workspace.php" class="sidebar-brand-link">
            <img src="<?php echo BASE_URL; ?>/assets/NEC-Logo.png" alt="NEC EDMS" class="sidebar-brand-logo">
            <span class="sidebar-brand-subtitle">E-Document Management System</span>
        </a>
    </div>

    <div class="sidebar-content">
        <!-- Direct Main Workspace Links -->
        <?php foreach ($mainNavItems as [$key, $href, $text, $iconKey]): ?>
            <a href="<?php echo BASE_URL . '/' . $href; ?>" class="nav-item <?php echo $activeNav === $key ? 'active' : ''; ?>">
                <div class="nav-icon"><?php echo render_icon($iconKey); ?></div>
                <span><?php echo $text; ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Retractable Administration Section -->
        <?php if (!empty($adminNavItems)): ?>
            <?php $isAdminOpen = in_array($activeNav, array_column($adminNavItems, 0), true); ?>

            <button type="button" class="nav-group-toggle <?php echo $isAdminOpen ? 'open' : ''; ?>" data-group="administration" onclick="toggleNavGroup(this)" aria-expanded="<?php echo $isAdminOpen ? 'true' : 'false'; ?>">
                <span>Administration</span>
                <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>

            <div class="nav-group <?php echo $isAdminOpen ? 'open' : ''; ?>">
                <?php foreach ($adminNavItems as [$key, $href, $text, $iconKey]): ?>
                    <a href="<?php echo BASE_URL . '/' . $href; ?>" class="nav-item <?php echo $activeNav === $key ? 'active' : ''; ?>">
                        <div class="nav-icon"><?php echo render_icon($iconKey); ?></div>
                        <span><?php echo $text; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-version">
        <span>v<?php echo defined('APP_VERSION') ? APP_VERSION : '1.0.0'; ?></span>
        <span class="sidebar-version-sep">&bull;</span>
        <span id="sidebarServerClock" class="sidebar-server-clock" data-server-ts="<?php echo time(); ?>" title="Server Time (Asia/Manila)">
            <?php echo date('H:i:s'); ?>
        </span>
    </div>

</aside>
