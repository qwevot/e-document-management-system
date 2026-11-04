<?php
require_once __DIR__ . '/functions.php';
require_login();

$activeNav = $activeNav ?? 'explorer';
$pageTitle = $pageTitle ?? 'Document Management System';

$currentUser = current_user();
$fullName    = $currentUser['full_name'] ?? $currentUser['username'] ?? 'User';
$roleLabel   = ucfirst($currentUser['role'] ?? 'User');
$initials    = get_initials($fullName);

$hdrUserId = (int)($currentUser['id'] ?? 0);
$hdrPendingReviews = $hdrUserId > 0 ? get_pending_reviews_for_user($hdrUserId) : [];
$hdrVerdictsStmt = db()->prepare("
    SELECT r.id AS review_id, r.version, r.status AS review_status, r.reviewed_at,
           d.id AS document_id, d.name AS document_name, d.file_extension,
           u.full_name AS reviewer_name
    FROM dms_document_reviews r
    JOIN dms_nodes d ON r.document_id = d.id
    LEFT JOIN users u ON r.reviewer_id = u.id
    WHERE r.requested_by = :uid AND r.status IN ('approved', 'rejected') AND d.is_deleted = 0
    ORDER BY r.reviewed_at DESC LIMIT 5
");
$hdrVerdictsStmt->execute(['uid' => $hdrUserId]);
$hdrRecentVerdicts = $hdrVerdictsStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter out notifications marked as read
$hdrDismissed = isset($_COOKIE['edms_read_notifs_' . $hdrUserId]) ? explode(',', (string)$_COOKIE['edms_read_notifs_' . $hdrUserId]) : [];
if (!empty($hdrDismissed)) {
    $hdrPendingReviews = array_values(array_filter($hdrPendingReviews, fn($r) => !in_array('rev_' . $r['review_id'], $hdrDismissed, true)));
    $hdrRecentVerdicts = array_values(array_filter($hdrRecentVerdicts, fn($v) => !in_array('ver_' . $v['review_id'], $hdrDismissed, true)));
}
$hdrNotifCount = count($hdrPendingReviews) + count($hdrRecentVerdicts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - EDMS</title>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <script>window.EDMS_CSRF_TOKEN = "<?php echo e(csrf_token()); ?>";</script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/responsive.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/katex.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/theme-customizer.css">
    <script>
        (function() {
            try {
                // Ensure default light theme
                document.documentElement.removeAttribute('data-theme');
                localStorage.removeItem('edms_theme_mode');

                const wp = localStorage.getItem('edms_sidebar_wallpaper') || 'default';
                const customWp = localStorage.getItem('edms_custom_wallpaper_data');
                const presets = {
                    "preset-forest": "https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=800&q=75",
                    "preset-tech": "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=75",
                    "preset-aurora": "https://images.unsplash.com/photo-1531366936337-7c912a4589a7?auto=format&fit=crop&w=800&q=75",
                    "preset-peaks": "https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=75",
                    "preset-carbon": "https://images.unsplash.com/photo-1550684848-fac1c5b4e853?auto=format&fit=crop&w=800&q=75"
                };
                const bg = (wp === 'custom' && customWp) ? customWp : (presets[wp] || null);
                if (bg) {
                    document.documentElement.style.setProperty('--custom-wallpaper', 'url("' + bg + '")');
                }
            } catch (e) {}
        })();
    </script>
    <?php if (!empty($extraCss)): ?>
        <?php foreach ((array)$extraCss as $cssFile): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo e($cssFile); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $parseIniBytes = static function (string $val): int {
        $val = trim($val);
        if ($val === '') return 0;
        $num = (float)$val;
        return match (strtolower(substr($val, -1))) {
            'g' => (int)($num * 1073741824),
            'm' => (int)($num * 1048576),
            'k' => (int)($num * 1024),
            default => (int)$num,
        };
    };
    $iniUploadBytes = $parseIniBytes((string)ini_get('upload_max_filesize'));
    $iniPostBytes   = $parseIniBytes((string)ini_get('post_max_size'));
    $maxUploadBytes = ($iniUploadBytes > 0 && $iniPostBytes > 0) ? min($iniUploadBytes, $iniPostBytes) : max($iniUploadBytes, $iniPostBytes);
    ?>
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.MAX_UPLOAD_BYTES = <?php echo $maxUploadBytes; ?>;
    </script>
</head>
<body>

<div class="app">

    <div class="mobile-nav-backdrop" id="mobileNavBackdrop" onclick="toggleSidebar()"></div>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main">

        <header class="topbar">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20">
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>

            <div class="user-area">
                <button type="button" class="sidebar-toggle theme-customizer-btn" onclick="openThemeModal()" title="Customize Wallpaper" aria-label="Wallpaper Settings" style="margin-right: 4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                </button>

                <details class="notif-dropdown" id="notifDropdown" style="position: relative;">
                    <summary class="sidebar-toggle notif-bell-btn" title="Notifications" aria-label="Notifications" style="list-style: none; cursor: pointer; position: relative;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <?php if ($hdrNotifCount > 0): ?>
                            <span id="notifBellBadge" style="position: absolute; top: -3px; right: -3px; background: var(--red); color: #fff; font-size: 9.5px; font-weight: 700; border-radius: 9px; min-width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 1.5px solid var(--green); line-height: 1;">
                                <?php echo $hdrNotifCount; ?>
                            </span>
                        <?php endif; ?>
                    </summary>

                    <div class="edms-action-popover notif-popover-menu" style="position: absolute; right: 0; top: calc(100% + 10px); width: 340px; max-height: 420px; overflow-y: auto; padding: 6px; background: var(--surface); box-shadow: var(--shadow-modal); z-index: 1000; border: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-bottom: 1px solid var(--surface-muted); margin-bottom: 4px;">
                            <span style="font-weight: 700; font-size: 12.5px; color: var(--ink);">Notifications</span>
                        </div>

                        <div id="notifEmptyState" style="<?php echo $hdrNotifCount === 0 ? '' : 'display: none; '; ?>padding: 24px 12px; text-align: center; color: var(--muted);">
                            <div style="margin-bottom: 4px; font-size: 12.5px; font-weight: 600; color: var(--ink);">All caught up</div>
                            <div style="font-size: 11px;">No pending reviews or alerts.</div>
                        </div>

                        <?php if ($hdrNotifCount > 0): ?>
                            <?php foreach ($hdrPendingReviews as $pr): ?>
                                <?php $ext = $pr['file_extension'] ?: 'pdf'; ?>
                                <a href="<?php echo BASE_URL; ?>/pages/view.php?id=<?php echo (int)$pr['document_id']; ?>" class="popover-item notif-item" data-notif-id="rev_<?php echo $pr['review_id']; ?>" style="text-decoration: none; padding: 8px 10px; gap: 8px; border-radius: 6px; margin-bottom: 2px;">
                                    <span style="color: var(--muted); display: inline-flex; flex-shrink: 0;"><?php echo render_icon($ext); ?></span>
                                    <span style="flex: 1; min-width: 0;">
                                        <span style="display: block; font-weight: 600; font-size: 12px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($pr['document_name']); ?></span>
                                        <span style="display: block; font-size: 10.5px; color: var(--muted);">Requested by <?php echo e($pr['requested_by_name']); ?></span>
                                    </span>
                                    <span class="status-badge badge-hold" style="font-size: 9px; flex-shrink: 0;">Review</span>
                                    <button type="button" title="Mark as read" onclick="dismissNotif(event, 'rev_<?php echo $pr['review_id']; ?>')" style="background: none; border: none; color: var(--muted); cursor: pointer; padding: 2px 5px; font-size: 13px; line-height: 1; border-radius: 4px;" onmouseover="this.style.color='var(--green)'; this.style.background='var(--surface-light)';" onmouseout="this.style.color='var(--muted)'; this.style.background='none';">&#10003;</button>
                                </a>
                            <?php endforeach; ?>

                            <?php foreach ($hdrRecentVerdicts as $vd): ?>
                                <?php 
                                $ext = $vd['file_extension'] ?: 'pdf';
                                $isApprove = ($vd['review_status'] === 'approved');
                                $badgeCls = $isApprove ? 'badge-publish' : 'badge-rejected';
                                $badgeText = $isApprove ? 'Approved' : 'Rejected';
                                ?>
                                <a href="<?php echo BASE_URL; ?>/pages/view.php?id=<?php echo (int)$vd['document_id']; ?>" class="popover-item notif-item" data-notif-id="ver_<?php echo $vd['review_id']; ?>" style="text-decoration: none; padding: 8px 10px; gap: 8px; border-radius: 6px; margin-bottom: 2px;">
                                    <span style="color: var(--muted); display: inline-flex; flex-shrink: 0;"><?php echo render_icon($ext); ?></span>
                                    <span style="flex: 1; min-width: 0;">
                                        <span style="display: block; font-weight: 600; font-size: 12px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($vd['document_name']); ?></span>
                                        <span style="display: block; font-size: 10.5px; color: var(--muted);"><?php echo $badgeText; ?> by <?php echo e($vd['reviewer_name'] ?? 'Reviewer'); ?></span>
                                    </span>
                                    <span class="status-badge <?php echo $badgeCls; ?>" style="font-size: 9px; flex-shrink: 0;"><?php echo $badgeText; ?></span>
                                    <button type="button" title="Mark as read" onclick="dismissNotif(event, 'ver_<?php echo $vd['review_id']; ?>')" style="background: none; border: none; color: var(--muted); cursor: pointer; padding: 2px 5px; font-size: 13px; line-height: 1; border-radius: 4px;" onmouseover="this.style.color='var(--green)'; this.style.background='var(--surface-light)';" onmouseout="this.style.color='var(--muted)'; this.style.background='none';">&#10003;</button>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div style="border-top: 1px solid var(--surface-muted); margin-top: 4px; padding-top: 6px; display: flex; align-items: center; justify-content: space-between; padding-inline: 6px;">
                            <?php if ($hdrNotifCount > 0): ?>
                                <button type="button" id="btnMarkAllNotifs" onclick="dismissNotif(event, 'all')" style="background: none; border: none; color: var(--muted); cursor: pointer; font-size: 11px; padding: 0; text-decoration: underline;">
                                    Mark all as read
                                </button>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/pages/my-workspace.php" class="popover-item" style="text-decoration: none; font-size: 11.5px; color: var(--green); font-weight: 600; width: auto; padding: 2px 0; margin-left: auto;">
                                Go to Workspace &rarr;
                            </a>
                        </div>
                    </div>
                    <script>
                    function dismissNotif(e, id) {
                        if (e) { e.preventDefault(); e.stopPropagation(); }
                        const cName = 'edms_read_notifs_<?php echo $hdrUserId; ?>';
                        const cur = (document.cookie.split('; ').find(r => r.startsWith(cName + '=')) || '').split('=')[1] || '';
                        let ids = cur ? cur.split(',') : [];
                        if (id === 'all') {
                            document.querySelectorAll('.notif-item').forEach(el => {
                                if (el.dataset.notifId) ids.push(el.dataset.notifId);
                                el.remove();
                            });
                        } else {
                            ids.push(id);
                            const item = document.querySelector(`.notif-item[data-notif-id="${id}"]`);
                            if (item) item.remove();
                        }
                        document.cookie = `${cName}=${[...new Set(ids)].join(',')}; path=/; max-age=2592000`;
                        const remaining = document.querySelectorAll('.notif-item').length;
                        const badge = document.getElementById('notifBellBadge');
                        if (badge) {
                            if (remaining > 0) badge.textContent = remaining;
                            else badge.remove();
                        }
                        if (remaining === 0) {
                            const empty = document.getElementById('notifEmptyState');
                            const btnAll = document.getElementById('btnMarkAllNotifs');
                            if (btnAll) btnAll.remove();
                            if (empty) empty.style.display = 'block';
                        }
                    }
                    </script>
                </details>
                <a href="<?php echo BASE_URL; ?>/pages/profile.php" class="user-profile-link" title="My Profile">
                    <div class="user-avatar">
                        <?php if (!empty($currentUser['avatar']) && is_file(__DIR__ . '/../' . $currentUser['avatar'])): ?>
                            <img src="<?php echo BASE_URL . '/' . e($currentUser['avatar']); ?>" alt="<?php echo e($fullName); ?>">
                        <?php else: ?>
                            <?php echo e($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="user-name"><?php echo e($fullName); ?></div>
                        <div class="user-role"><?php echo e($roleLabel); ?></div>
                    </div>
                </a>

                <a href="<?php echo BASE_URL; ?>/logout.php?token=<?php echo e(csrf_token()); ?>" class="logout-link">Log out</a>
            </div>
        </header>

        <div class="content">
