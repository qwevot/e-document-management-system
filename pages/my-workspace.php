<?php
$pageTitle = 'My Workspace';
$activeNav = 'my-workspace';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/header.php';

$pdo = db();
$user = current_user() ?? ['id' => 1, 'full_name' => 'Dr. Ruan Mei', 'role' => 'admin'];
$userId = (int)($user['id'] ?? 1);
$userRole = strtolower($user['role'] ?? 'viewer');

// 1. Fetch pending review tasks assigned to current user
$pendingReviews = get_pending_reviews_for_user($userId);
$pendingReviewCount = count($pendingReviews);

// 2. Fetch user's own submissions currently under review (Hold)
$mySubmissions = get_user_review_submissions($userId);
$mySubmissionsCount = count($mySubmissions);

// 3. Fetch summary stats
$statDrafts = (int)$pdo->query("SELECT COUNT(*) FROM dms_nodes WHERE created_by = {$userId} AND node_type = 'document' AND status = 'active' AND is_deleted = 0")->fetchColumn();
$statPublished = (int)$pdo->query("SELECT COUNT(*) FROM dms_nodes WHERE created_by = {$userId} AND node_type = 'document' AND status = 'published' AND is_deleted = 0")->fetchColumn();
$statTotal = (int)$pdo->query("SELECT COUNT(*) FROM dms_nodes WHERE created_by = {$userId} AND node_type = 'document' AND is_deleted = 0")->fetchColumn();

// 4. Fetch recent draft documents authored by user
$recentDocsStmt = $pdo->prepare("
    SELECT d.*, p.name AS parent_folder_name 
    FROM dms_nodes d
    LEFT JOIN dms_nodes p ON d.parent_id = p.id
    WHERE d.created_by = :uid AND d.node_type = 'document' AND d.is_deleted = 0
    ORDER BY d.updated_at DESC 
    LIMIT 6
");
$recentDocsStmt->execute(['uid' => $userId]);
$recentDocs = $recentDocsStmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Fetch recent user audit activity
$recentLogsStmt = $pdo->prepare("
    SELECT * FROM system_logs 
    WHERE user_id = :uid 
    ORDER BY created_at DESC 
    LIMIT 8
");
$recentLogsStmt->execute(['uid' => $userId]);
$recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for status badge class
function get_ws_status_badge(string $status): string {
    return match ($status) {
        'published' => 'badge-publish',
        'active'    => 'badge-active',
        'hold'      => 'badge-hold',
        'suspend'   => 'badge-suspend',
        'obsolete'  => 'badge-obsolete',
        default     => 'badge-default',
    };
}
?>

<div class="explorer-wrapper">

    <!-- Workspace Welcome Banner Card -->
    <div class="explorer-toolbar-card" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="presence-avatar is-self" style="width: 44px; height: 44px; font-size: 16px;">
                    <?php echo e(get_initials($user['full_name'])); ?>
                </div>
                <div>
                    <h2 style="font-size: 17px; font-weight: 700; color: var(--ink); margin: 0;">Welcome back, <?php echo e($user['full_name']); ?></h2>
                    <span style="font-size: 12.5px; color: var(--muted);">Role: <strong><?php echo ucfirst(e($user['role'])); ?></strong> &bull; Dept: <strong><?php echo e($user['department'] ?? 'Engineering'); ?></strong></span>
                </div>
            </div>

            <div class="toolbar-actions">
                <a href="explorer.php" class="btn btn-secondary">
                    <?php echo render_icon('explorer'); ?>
                    <span>Document Explorer</span>
                </a>
                <a href="explorer.php?action=upload" class="btn btn-primary" id="btnWsUpload">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>New Document</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px;">
        <!-- Card 1: Awaiting My Review -->
        <div class="explorer-filter-card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between; <?php echo $pendingReviewCount > 0 ? 'border-left: 3px solid var(--amber);' : ''; ?>">
            <div>
                <span style="font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase;">Awaiting My Review</span>
                <h3 style="font-size: 24px; font-weight: 800; color: <?php echo $pendingReviewCount > 0 ? 'var(--amber-ink)' : 'var(--ink)'; ?>; margin: 4px 0 0;"><?php echo $pendingReviewCount; ?></h3>
            </div>
            <div style="width: 38px; height: 38px; border-radius: 8px; background: var(--amber-soft); display: flex; align-items: center; justify-content: center; color: var(--amber-ink);">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
        </div>

        <!-- Card 2: My Submissions On Hold -->
        <div class="explorer-filter-card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase;">My Submissions in Review</span>
                <h3 style="font-size: 24px; font-weight: 800; color: var(--ink); margin: 4px 0 0;"><?php echo $mySubmissionsCount; ?></h3>
            </div>
            <div style="width: 38px; height: 38px; border-radius: 8px; background: var(--info-blue-soft); display: flex; align-items: center; justify-content: center; color: var(--info-blue-dark);">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 11 17 15 13"></polyline></svg>
            </div>
        </div>

        <!-- Card 3: Active Working Drafts -->
        <div class="explorer-filter-card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase;">Active Drafts</span>
                <h3 style="font-size: 24px; font-weight: 800; color: var(--ink); margin: 4px 0 0;"><?php echo $statDrafts; ?></h3>
            </div>
            <div style="width: 38px; height: 38px; border-radius: 8px; background: var(--slate-soft); display: flex; align-items: center; justify-content: center; color: var(--slate-ink);">
                <?php echo render_icon('edit'); ?>
            </div>
        </div>

        <!-- Card 4: Published Documents -->
        <div class="explorer-filter-card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase;">Published Standards</span>
                <h3 style="font-size: 24px; font-weight: 800; color: var(--success-ink); margin: 4px 0 0;"><?php echo $statPublished; ?></h3>
            </div>
            <div style="width: 38px; height: 38px; border-radius: 8px; background: var(--success-soft); display: flex; align-items: center; justify-content: center; color: var(--success-ink);">
                <?php echo render_icon('check'); ?>
            </div>
        </div>
    </div>

    <!-- Section 1: Awaiting My Review (Primary Action Queue) -->
    <?php if (!empty($pendingReviews)): ?>
        <div class="explorer-filter-card" style="margin-bottom: 24px; padding: 18px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: var(--amber); display: inline-block;"></span>
                    <h3 style="font-size: 15px; font-weight: 700; color: var(--ink); margin: 0;">Documents Awaiting Your Review (<?php echo $pendingReviewCount; ?>)</h3>
                </div>
                <span style="font-size: 12px; color: var(--muted);">Immediate verification requested</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($pendingReviews as $pr): ?>
                    <div class="explorer-item-card is-document" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; background: var(--surface);">
                        <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                            <span class="workspace-file-icon icon-<?php echo e($pr['file_extension'] ?: 'pdf'); ?>" style="font-size: 18px;">
                                <?php echo render_icon($pr['file_extension'] ?: 'pdf'); ?>
                            </span>
                            <div style="min-width: 0;">
                                <a href="view.php?id=<?php echo $pr['document_id']; ?>" style="font-size: 13.5px; font-weight: 600; color: var(--ink); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo e($pr['document_name']); ?>
                                </a>
                                <div style="display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                    <span>Target: <strong><?php echo e($pr['target_version']); ?></strong></span>
                                    <span>&bull;</span>
                                    <span>Submitted by <strong><?php echo e($pr['requested_by_name']); ?></strong> on <?php echo date('M d, H:i', strtotime($pr['review_requested_at'])); ?></span>
                                    <?php if ($pr['parent_folder_name']): ?>
                                        <span>&bull;</span>
                                        <span>Folder: <?php echo e($pr['parent_folder_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                            <span class="status-badge badge-hold">Hold</span>
                            <a href="view.php?id=<?php echo $pr['document_id']; ?>" class="btn btn-primary" style="padding: 5px 12px; font-size: 12px;">
                                <?php echo render_icon('view'); ?>
                                <span>Inspect &amp; Review</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Section 2: Two Column Split: My Submissions & Recent Documents -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 24px;">
        
        <!-- Left: My Submissions Under Review -->
        <div class="explorer-filter-card" style="padding: 18px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: var(--ink); margin: 0;">My Submissions on Hold</h3>
                <span style="font-size: 12px; color: var(--muted);"><?php echo $mySubmissionsCount; ?> active</span>
            </div>

            <?php if (empty($mySubmissions)): ?>
                <div style="text-align: center; padding: 24px 12px; color: var(--muted); font-size: 13px;">
                    You have no submissions currently on hold for review.
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($mySubmissions as $ms): ?>
                        <div style="padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); display: flex; align-items: center; justify-content: space-between;">
                            <div style="min-width: 0;">
                                <a href="view.php?id=<?php echo $ms['document_id']; ?>" style="font-size: 13px; font-weight: 600; color: var(--ink); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo e($ms['document_name']); ?>
                                </a>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 2px;">
                                    <span>Version: <?php echo e($ms['current_version']); ?></span>
                                    <span>&bull;</span>
                                    <span style="color: var(--amber-ink); font-weight: 600;"><?php echo (int)$ms['approved_count']; ?>/<?php echo (int)$ms['total_reviewers']; ?> Approved</span>
                                </div>
                            </div>
                            <a href="view.php?id=<?php echo $ms['document_id']; ?>" class="btn-action" title="View Document">
                                <?php echo render_icon('view'); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: My Recent Documents -->
        <div class="explorer-filter-card" style="padding: 18px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: var(--ink); margin: 0;">My Recent Documents</h3>
                <a href="explorer.php" style="font-size: 12px; color: var(--primary); text-decoration: none;">View all &rarr;</a>
            </div>

            <?php if (empty($recentDocs)): ?>
                <div style="text-align: center; padding: 24px 12px; color: var(--muted); font-size: 13px;">
                    No recent documents found.
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($recentDocs as $rd): ?>
                        <div style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                <span class="workspace-file-icon icon-<?php echo e($rd['file_extension'] ?: 'pdf'); ?>" style="font-size: 14px;">
                                    <?php echo render_icon($rd['file_extension'] ?: 'pdf'); ?>
                                </span>
                                <div style="min-width: 0;">
                                    <a href="view.php?id=<?php echo $rd['id']; ?>" style="font-size: 12.5px; font-weight: 600; color: var(--ink); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo e($rd['name']); ?>
                                    </a>
                                    <span style="font-size: 10.5px; color: var(--muted);"><?php echo e($rd['version']); ?> &bull; <?php echo date('M d, Y', strtotime($rd['updated_at'])); ?></span>
                                </div>
                            </div>
                            <span class="status-badge <?php echo get_ws_status_badge($rd['status']); ?>" style="font-size: 10.5px;">
                                <?php echo ucfirst(e($rd['status'])); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Section 3: Recent Activity Log Feed -->
    <?php if (!empty($recentLogs)): ?>
        <div class="explorer-filter-card" style="padding: 18px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <?php echo render_icon('history'); ?>
                    <h3 style="font-size: 14.5px; font-weight: 700; color: var(--ink); margin: 0;">My Activity Trail</h3>
                </div>
                <?php if (in_array($userRole, ['super admin', 'admin'], true)): ?>
                    <a href="system-logs.php" style="font-size: 12px; color: var(--primary); text-decoration: none;">Full Audit Logs &rarr;</a>
                <?php endif; ?>
            </div>

            <div style="display: flex; flex-direction: column; gap: 6px;">
                <?php foreach ($recentLogs as $log): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; font-size: 12px; border-bottom: 1px dashed var(--border); color: var(--ink);">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-family: monospace; font-size: 11px; font-weight: 700; color: var(--muted);"><?php echo e($log['action']); ?></span>
                            <?php if ($log['entity_type']): ?>
                                <span style="font-size: 11px; color: var(--muted);">(<?php echo e($log['entity_type']); ?> #<?php echo e((string)$log['entity_id']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <span style="font-size: 11px; color: var(--muted);"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
