<?php
require_once __DIR__ . '/functions.php';

$activeNav = $activeNav ?? 'explorer';
$role      = current_role();

// Extendable My Workspace sub-views & status filters
$isWsOpen     = ($activeNav === 'my-workspace');
$wsFilter     = $isWsOpen ? ($_GET['filter'] ?? 'all') : '';
$pendingBadge = $totalPending ?? count($hdrPendingReviews ?? []);
$wsItems = [
    ['all',       'My Documents',     'workspace', null,               $totalMyDocs ?? null],
    ['starred',   'Starred',          'star',      null,               $totalStarred ?? null],
    ['recent',    'Recently Opened',  'history',   null,               null],
    ['pending',   'Pending Review',   'tasks',     null,               $pendingBadge ?: null],
    ['active',    'Active',           null,        'var(--info-blue)', $statusCounts['active'] ?? null],
    ['hold',      'Hold',             null,        'var(--amber)',     $statusCounts['hold'] ?? null],
    ['rejected',  'Rejected',         null,        'var(--red)',       $statusCounts['rejected'] ?? null],
    ['published', 'Published',        null,        '#22c55e',          $statusCounts['published'] ?? null],
    ['suspend',   'Suspend',          null,        '#fca5a5',          $statusCounts['suspend'] ?? null],
    ['obsolete',  'Obsolete (Trash)', null,        '#94a3b8',          $statusCounts['obsolete'] ?? null],
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
        <!-- Extendable My Workspace Section -->
        <button type="button" class="nav-item nav-group-toggle <?php echo $isWsOpen ? 'open' : ''; ?>" data-group="my-workspace" onclick="toggleNavGroup(this)" aria-expanded="<?php echo $isWsOpen ? 'true' : 'false'; ?>">
            <div class="nav-icon"><?php echo render_icon('workspace'); ?></div>
            <span>My Workspace</span>
            <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>

        <div class="nav-group <?php echo $isWsOpen ? 'open' : ''; ?>">
            <?php foreach ($wsItems as [$fKey, $label, $iconKey, $dotColor, $count]): ?>
                <?php if ($fKey === 'active'): ?><div class="nav-subdivider"></div><?php endif; ?>
                <a href="<?php echo BASE_URL . '/pages/my-workspace.php?filter=' . $fKey; ?>" class="nav-item nav-subitem <?php echo $wsFilter === $fKey ? 'active' : ''; ?>" data-filter="<?php echo $fKey; ?>" onclick="if(window.filterWorkspace){event.preventDefault();window.filterWorkspace(this,'<?php echo $fKey; ?>');}">
                    <?php if ($iconKey): ?>
                        <div class="nav-icon"><?php echo render_icon($iconKey); ?></div>
                    <?php else: ?>
                        <span class="ws-status-dot" style="background: <?php echo $dotColor; ?>;"></span>
                    <?php endif; ?>
                    <span class="nav-sublabel"><?php echo $label; ?></span>
                    <?php if ($count !== null): ?>
                        <span class="nav-badge <?php echo ($fKey === 'pending' && $count > 0) ? 'is-pending' : ''; ?>"><?php echo (int)$count; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="<?php echo BASE_URL; ?>/pages/explorer.php" class="nav-item <?php echo $activeNav === 'explorer' ? 'active' : ''; ?>">
            <div class="nav-icon"><?php echo render_icon('explorer'); ?></div>
            <span>Document Explorer</span>
        </a>

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
