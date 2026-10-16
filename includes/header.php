<?php
require_once __DIR__ . '/functions.php';
require_login();

$activeNav = $activeNav ?? 'explorer';
$pageTitle = $pageTitle ?? 'Document Management System';

$currentUser = current_user();
$fullName    = $currentUser['full_name'] ?? $currentUser['username'] ?? 'User';
$roleLabel   = ucfirst($currentUser['role'] ?? 'User');

$words = preg_split('/\s+/', trim($fullName));
$initials = strtoupper(($words[0][0] ?? 'U') . ($words[1][0] ?? ''));

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
$hdrNotifCount = count($hdrPendingReviews) + count($hdrRecentVerdicts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - EDMS</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/responsive.css">
    <?php if (!empty($extraCss)): ?>
        <?php foreach ((array)$extraCss as $cssFile): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo e($cssFile); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.MAX_UPLOAD_BYTES = <?php echo (int)min((int)ini_get('upload_max_filesize'), (int)ini_get('post_max_size')) * 1048576; ?>;
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
                <details class="notif-dropdown" id="notifDropdown" style="position: relative;">
                    <summary class="sidebar-toggle notif-bell-btn" title="Notifications" aria-label="Notifications" style="list-style: none; cursor: pointer; position: relative;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <?php if ($hdrNotifCount > 0): ?>
                            <span style="position: absolute; top: -3px; right: -3px; background: var(--red); color: #fff; font-size: 9.5px; font-weight: 700; border-radius: 9px; min-width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 1.5px solid var(--green); line-height: 1;">
                                <?php echo $hdrNotifCount; ?>
                            </span>
                        <?php endif; ?>
                    </summary>

                    <div class="edms-action-popover" style="position: absolute; right: 0; top: calc(100% + 10px); width: 340px; max-height: 420px; overflow-y: auto; padding: 6px; box-shadow: var(--shadow-modal); z-index: 1000; border: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-bottom: 1px solid var(--surface-muted); margin-bottom: 4px;">
                            <span style="font-weight: 700; font-size: 12.5px; color: var(--ink);">Notifications</span>
                        </div>

                        <?php if ($hdrNotifCount === 0): ?>
                            <div style="padding: 24px 12px; text-align: center; color: var(--muted);">
                                <div style="margin-bottom: 4px; font-size: 12.5px; font-weight: 600; color: var(--ink);">All caught up</div>
                                <div style="font-size: 11px;">No pending reviews or alerts.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($hdrPendingReviews as $pr): ?>
                                <?php $ext = $pr['file_extension'] ?: 'pdf'; ?>
                                <a href="<?php echo BASE_URL; ?>/pages/view.php?id=<?php echo (int)$pr['document_id']; ?>" class="popover-item" style="text-decoration: none; padding: 8px 10px; gap: 10px; border-radius: 6px; margin-bottom: 2px;">
                                    <span style="color: var(--muted); display: inline-flex; flex-shrink: 0;"><?php echo render_icon($ext); ?></span>
                                    <span style="flex: 1; min-width: 0;">
                                        <span style="display: block; font-weight: 600; font-size: 12px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($pr['document_name']); ?></span>
                                        <span style="display: block; font-size: 10.5px; color: var(--muted);">Requested by <?php echo e($pr['requested_by_name']); ?></span>
                                    </span>
                                    <span class="status-badge badge-hold" style="font-size: 9px; flex-shrink: 0;">Review</span>
                                </a>
                            <?php endforeach; ?>

                            <?php foreach ($hdrRecentVerdicts as $vd): ?>
                                <?php 
                                $ext = $vd['file_extension'] ?: 'pdf';
                                $isApprove = ($vd['review_status'] === 'approved');
                                $badgeCls = $isApprove ? 'badge-publish' : 'badge-rejected';
                                $badgeText = $isApprove ? 'Approved' : 'Rejected';
                                ?>
                                <a href="<?php echo BASE_URL; ?>/pages/view.php?id=<?php echo (int)$vd['document_id']; ?>" class="popover-item" style="text-decoration: none; padding: 8px 10px; gap: 10px; border-radius: 6px; margin-bottom: 2px;">
                                    <span style="color: var(--muted); display: inline-flex; flex-shrink: 0;"><?php echo render_icon($ext); ?></span>
                                    <span style="flex: 1; min-width: 0;">
                                        <span style="display: block; font-weight: 600; font-size: 12px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($vd['document_name']); ?></span>
                                        <span style="display: block; font-size: 10.5px; color: var(--muted);"><?php echo $badgeText; ?> by <?php echo e($vd['reviewer_name'] ?? 'Reviewer'); ?></span>
                                    </span>
                                    <span class="status-badge <?php echo $badgeCls; ?>" style="font-size: 9px; flex-shrink: 0;"><?php echo $badgeText; ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div style="border-top: 1px solid var(--surface-muted); margin-top: 4px; padding-top: 4px;">
                            <a href="<?php echo BASE_URL; ?>/pages/my-workspace.php" class="popover-item" style="text-decoration: none; justify-content: center; font-size: 11.5px; color: var(--green); font-weight: 600;">
                                Go to Workspace →
                            </a>
                        </div>
                    </div>
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

                <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-link">Log out</a>
            </div>
        </header>

        <div class="content">
