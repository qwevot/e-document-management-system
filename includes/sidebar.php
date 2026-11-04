<?php
require_once __DIR__ . '/functions.php';

$activeNav = $activeNav ?? 'explorer';
$role      = current_role();

// Extendable My Workspace (filter=all + status filters) & top-level feeds
$wsFilter     = ($activeNav === 'my-workspace') ? ($_GET['filter'] ?? 'all') : '';
$isWsOpen     = ($activeNav === 'my-workspace' && !in_array($wsFilter, ['starred', 'recent', 'pending'], true));
$pendingBadge = $totalPending ?? count($hdrPendingReviews ?? []);
$wsStatuses = [
    ['active',    'Active',           $statusCounts['active'] ?? null],
    ['hold',      'Hold',             $statusCounts['hold'] ?? null],
    ['rejected',  'Rejected',         $statusCounts['rejected'] ?? null],
    ['published', 'Published',        $statusCounts['published'] ?? null],
    ['suspend',   'Suspend',          $statusCounts['suspend'] ?? null],
    ['obsolete',  'Obsolete (Trash)', $statusCounts['obsolete'] ?? null],
];
$wsTopFeeds = [
    ['starred', 'Starred',         'star',    $totalStarred ?? null],
    ['recent',  'Recently Opened', 'history', null],
    ['pending', 'Pending Review',  'tasks',   $pendingBadge ?: null],
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
        <!-- Extendable My Workspace Section (Split Click: Label = filter=all, Chevron = toggle status list) -->
        <a href="<?php echo BASE_URL; ?>/pages/my-workspace.php?filter=all" class="nav-item nav-group-toggle <?php echo $wsFilter === 'all' ? 'active' : ''; ?> <?php echo $isWsOpen ? 'open' : ''; ?>" data-group="my-workspace" data-filter="all" onclick="if(window.filterWorkspace){event.preventDefault();window.filterWorkspace(this,'all');}">
            <div class="nav-icon"><?php echo render_icon('workspace'); ?></div>
            <span class="nav-sublabel">My Workspace</span>
            <?php if (isset($totalMyDocs)): ?>
                <span class="nav-badge"><?php echo (int)$totalMyDocs; ?></span>
            <?php endif; ?>
            <span class="nav-chevron-hit" onclick="event.preventDefault();event.stopPropagation();toggleNavGroup(this.parentElement);" title="Toggle status filters">
                <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </span>
        </a>

        <div class="nav-group <?php echo $isWsOpen ? 'open' : ''; ?>">
            <?php foreach ($wsStatuses as [$fKey, $label, $count]): ?>
                <a href="<?php echo BASE_URL . '/pages/my-workspace.php?filter=' . $fKey; ?>" class="nav-item nav-subitem <?php echo $wsFilter === $fKey ? 'active' : ''; ?>" data-filter="<?php echo $fKey; ?>" onclick="if(window.filterWorkspace){event.preventDefault();window.filterWorkspace(this,'<?php echo $fKey; ?>');}">
                    <span class="nav-sublabel"><?php echo $label; ?></span>
                    <?php if ($count !== null): ?>
                        <span class="nav-badge"><?php echo (int)$count; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($wsTopFeeds as [$fKey, $label, $iconKey, $count]): ?>
            <a href="<?php echo BASE_URL . '/pages/my-workspace.php?filter=' . $fKey; ?>" class="nav-item <?php echo $wsFilter === $fKey ? 'active' : ''; ?>" data-filter="<?php echo $fKey; ?>" onclick="if(window.filterWorkspace){event.preventDefault();window.filterWorkspace(this,'<?php echo $fKey; ?>');}">
                <div class="nav-icon"><?php echo render_icon($iconKey); ?></div>
                <span class="nav-sublabel"><?php echo $label; ?></span>
                <?php if ($count !== null): ?>
                    <span class="nav-badge <?php echo ($fKey === 'pending' && $count > 0) ? 'is-pending' : ''; ?>"><?php echo (int)$count; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

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
