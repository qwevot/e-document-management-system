<?php
$pageTitle = 'My Workspace';
$activeNav = 'my-workspace';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/review.php';
require_once __DIR__ . '/../includes/contributors.php';
require_once __DIR__ . '/../includes/header.php';

$pdo = db();
$user = current_user() ?? ['id' => 1, 'full_name' => 'User', 'role' => 'viewer'];
$userId = (int)($user['id'] ?? 1);

// 1. Notifications / Requires Attention
// A: Inbound review requests assigned to current user
$pendingReviews = get_pending_reviews_for_user($userId);

// B: Submissions by current user that have been approved or rejected
$verdictsStmt = $pdo->prepare("
    SELECT r.id AS review_id, r.version, r.status AS review_status, r.comments, r.reviewed_at,
           d.id AS document_id, d.name AS document_name, d.file_extension,
           u.full_name AS reviewer_name
    FROM dms_document_reviews r
    JOIN dms_nodes d ON r.document_id = d.id
    LEFT JOIN users u ON r.reviewer_id = u.id
    WHERE r.requested_by = :uid
      AND r.status IN ('approved', 'rejected')
      AND d.is_deleted = 0
    ORDER BY r.reviewed_at DESC
    LIMIT 6
");
$verdictsStmt->execute(['uid' => $userId]);
$recentVerdicts = $verdictsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAttentionCount = count($pendingReviews) + count($recentVerdicts);

// 2. My Workspace: Documents authored by user OR where user is a contributor
$myDocsStmt = $pdo->prepare("
    SELECT DISTINCT d.id, d.name, d.version, d.status, d.file_extension, d.updated_at, d.created_at,
           (d.created_by = :uid1) AS is_owner
    FROM dms_nodes d
    LEFT JOIN dms_document_contributors c ON d.id = c.document_id AND c.user_id = :uid2
    WHERE d.node_type = 'document'
      AND d.is_deleted = 0
      AND (d.created_by = :uid3 OR c.user_id IS NOT NULL)
    ORDER BY d.updated_at DESC
    LIMIT 15
");
$myDocsStmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId]);
$myDocs = $myDocsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Recently Opened / Touched Documents from system activity logs or recent nodes
$recentTouchedStmt = $pdo->prepare("
    SELECT DISTINCT d.id, d.name, d.version, d.status, d.file_extension,
           COALESCE(l.created_at, d.updated_at) AS last_touched_at,
           l.action AS last_action
    FROM dms_nodes d
    LEFT JOIN system_logs l ON l.entity_type = 'document' AND l.entity_id = d.id AND l.user_id = :uid
    WHERE d.node_type = 'document'
      AND d.is_deleted = 0
    ORDER BY last_touched_at DESC
    LIMIT 15
");
$recentTouchedStmt->execute(['uid' => $userId]);
$recentDocs = $recentTouchedStmt->fetchAll(PDO::FETCH_ASSOC);

function ws_status_badge(string $status): string {
    return match ($status) {
        'published' => 'badge-publish',
        'active'    => 'badge-active',
        'hold'      => 'badge-hold',
        'suspend'   => 'badge-suspend',
        'rejected'  => 'badge-rejected',
        'obsolete'  => 'badge-obsolete',
        default     => 'badge-active',
    };
}
?>

<div class="welcome-header">
    <h1>Welcome, <?php echo e($fullName); ?>!</h1>
</div>

<!-- Main 2-Column Dashboard Grid: Documents Left (Rectangle 3), Notifications Right (Rectangle 2) -->
<div class="workspace-dashboard-grid" style="display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start;">

    <!-- Left Column: Document Tabs (Rectangle 3) -->
    <div class="explorer-wrapper" style="margin: 0;">
        
        <!-- Tab Switcher Bar: My Workspace & Recently Opened -->
        <div class="explorer-filter-card">
            <div class="pill-tabs" id="wsPillTabs">
                <button type="button" class="pill-tab active" id="tabBtnMyWorkspace" onclick="switchWsTab('my-workspace')" style="border: none; cursor: pointer;">
                    <?php echo render_icon('workspace'); ?>
                    <span>My Workspace</span>
                </button>
                <button type="button" class="pill-tab" id="tabBtnRecent" onclick="switchWsTab('recent')" style="border: none; cursor: pointer;">
                    <?php echo render_icon('history'); ?>
                    <span>Recently Opened</span>
                </button>
            </div>
            <a href="explorer.php" class="btn btn-secondary" style="padding: 4px 10px; font-size: 11.5px;">
                <?php echo render_icon('explorer'); ?>
                <span>Browse All</span>
            </a>
        </div>

        <!-- Tab 1: My Workspace List -->
        <div id="paneMyWorkspace" class="explorer-card-list">
            <?php if (empty($myDocs)): ?>
                <div class="explorer-empty-card" style="padding: 32px 16px;">
                    <div class="empty-icon"><?php echo render_icon('workspace'); ?></div>
                    <h3 class="empty-title">No documents yet</h3>
                    <p class="empty-subtitle">Documents you author or contribute to will appear here.</p>
                </div>
            <?php else: ?>
                <div class="explorer-table-header">
                    <div style="width: 32px;"></div>
                    <div class="col-hdr-name">Document</div>
                    <div class="col-hdr-owner">Role</div>
                    <div class="col-hdr-version">Version</div>
                    <div class="col-hdr-status">Status</div>
                    <div style="width: 60px; text-align: right;">History</div>
                </div>

                <?php foreach ($myDocs as $doc): ?>
                    <?php $extIcon = $doc['file_extension'] ?: 'pdf'; ?>
                    <div class="explorer-item-card is-document" style="padding: 8px 12px;">
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($doc['name']); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($doc['name']); ?>">
                                    <?php echo e($doc['name']); ?>
                                </a>
                            </div>
                        </div>

                        <div class="item-owner-col">
                            <span style="font-size: 11.5px; color: var(--muted); font-weight: 500;">
                                <?php echo !empty($doc['is_owner']) ? 'Author' : 'Contributor'; ?>
                            </span>
                        </div>

                        <div class="item-version-col">
                            <span class="version-badge"><?php echo e($doc['version']); ?></span>
                        </div>

                        <div class="item-status-col">
                            <span class="status-badge <?php echo ws_status_badge($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div style="width: 60px; text-align: right; flex-shrink: 0;">
                            <button type="button" class="btn-action" title="View Audit History" onclick="openWsAuditModal(<?php echo (int)$doc['id']; ?>, '<?php echo addslashes(e($doc['name'])); ?>')">
                                <?php echo render_icon('history'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tab 2: Recently Opened List (Hidden by default) -->
        <div id="paneRecent" class="explorer-card-list" style="display: none;">
            <?php if (empty($recentDocs)): ?>
                <div class="explorer-empty-card" style="padding: 32px 16px;">
                    <div class="empty-icon"><?php echo render_icon('history'); ?></div>
                    <h3 class="empty-title">No recent documents</h3>
                    <p class="empty-subtitle">Documents you open will be listed here for quick access.</p>
                </div>
            <?php else: ?>
                <div class="explorer-table-header">
                    <div style="width: 32px;"></div>
                    <div class="col-hdr-name">Document</div>
                    <div class="col-hdr-modified">Last Accessed</div>
                    <div class="col-hdr-version">Version</div>
                    <div class="col-hdr-status">Status</div>
                    <div style="width: 60px; text-align: right;">History</div>
                </div>

                <?php foreach ($recentDocs as $doc): ?>
                    <?php $extIcon = $doc['file_extension'] ?: 'pdf'; ?>
                    <div class="explorer-item-card is-document" style="padding: 8px 12px;">
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($doc['name']); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($doc['name']); ?>">
                                    <?php echo e($doc['name']); ?>
                                </a>
                            </div>
                        </div>

                        <div class="item-modified-col">
                            <span style="font-size: 11px; color: var(--muted);">
                                <?php echo date('M j, Y • H:i', strtotime($doc['last_touched_at'])); ?>
                            </span>
                        </div>

                        <div class="item-version-col">
                            <span class="version-badge"><?php echo e($doc['version']); ?></span>
                        </div>

                        <div class="item-status-col">
                            <span class="status-badge <?php echo ws_status_badge($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div style="width: 60px; text-align: right; flex-shrink: 0;">
                            <button type="button" class="btn-action" title="View Audit History" onclick="openWsAuditModal(<?php echo (int)$doc['id']; ?>, '<?php echo addslashes(e($doc['name'])); ?>')">
                                <?php echo render_icon('history'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- Right Column: Notifications & Requires Attention (Rectangle 2) -->
    <div class="explorer-wrapper" style="margin: 0;">
        <div class="explorer-toolbar-card" style="padding: 10px 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-flex; color: var(--green);"><?php echo render_icon('notification'); ?></span>
                <span style="font-weight: 700; font-size: 13.5px; color: var(--ink);">Notifications</span>
            </div>
            <?php if ($totalAttentionCount > 0): ?>
                <span class="status-badge badge-publish" style="font-size: 10px;"><?php echo $totalAttentionCount; ?> new</span>
            <?php endif; ?>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php if ($totalAttentionCount === 0): ?>
                <div class="explorer-empty-card" style="padding: 28px 12px;">
                    <div style="color: var(--green); margin-bottom: 6px;"><?php echo render_icon('check'); ?></div>
                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">No pending approvals or review verdicts.</div>
                </div>
            <?php else: ?>
                <!-- Pending Approvals Assigned to User -->
                <?php foreach ($pendingReviews as $pr): ?>
                    <div class="explorer-item-card" style="padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; border-left: 3px solid var(--amber);">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                            <span class="status-badge badge-hold" style="font-size: 9.5px;">Awaiting Review</span>
                            <span style="font-size: 10.5px; color: var(--muted);"><?php echo date('M j, H:i', strtotime($pr['review_requested_at'])); ?></span>
                        </div>
                        <a href="view.php?id=<?php echo (int)$pr['document_id']; ?>" style="font-size: 12.5px; font-weight: 600; color: var(--ink); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($pr['document_name']); ?>">
                            <?php echo e($pr['document_name']); ?>
                        </a>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 2px;">
                            <span style="font-size: 11px; color: var(--muted);">By <?php echo e($pr['requested_by_name']); ?></span>
                            <a href="view.php?id=<?php echo (int)$pr['document_id']; ?>" class="btn btn-primary" style="padding: 2px 8px; font-size: 11px;">
                                Review &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Verdicts on User Submissions -->
                <?php foreach ($recentVerdicts as $vd): ?>
                    <?php 
                    $isApprove = ($vd['review_status'] === 'approved');
                    $borderCol = $isApprove ? 'var(--green)' : 'var(--red)';
                    $badgeCls = $isApprove ? 'badge-publish' : 'badge-rejected';
                    $badgeText = $isApprove ? 'Accepted' : 'Rejected';
                    ?>
                    <div class="explorer-item-card" style="padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; border-left: 3px solid <?php echo $borderCol; ?>;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                            <span class="status-badge <?php echo $badgeCls; ?>" style="font-size: 9.5px;"><?php echo $badgeText; ?></span>
                            <span style="font-size: 10.5px; color: var(--muted);"><?php echo date('M j, H:i', strtotime($vd['reviewed_at'])); ?></span>
                        </div>
                        <a href="view.php?id=<?php echo (int)$vd['document_id']; ?>" style="font-size: 12.5px; font-weight: 600; color: var(--ink); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($vd['document_name']); ?>">
                            <?php echo e($vd['document_name']); ?>
                        </a>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 2px;">
                            <span style="font-size: 11px; color: var(--muted);">By <?php echo e($vd['reviewer_name'] ?? 'Reviewer'); ?></span>
                            <a href="view.php?id=<?php echo (int)$vd['document_id']; ?>" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;">
                                View &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal: Document Audit History (Reusing existing EDMS modal architecture) -->
<div class="edms-modal-backdrop" id="modalAuditHistory">
    <div class="edms-modal-dialog" style="max-width: 540px; width: 90%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('history'); ?>
                <span>Audit History: <strong id="auditModalDocName" style="font-weight: 600;">Document</strong></span>
            </div>
            <button type="button" class="btn-modal-close" onclick="closeWsAuditModal()" title="Close">&times;</button>
        </div>
        <div class="edms-modal-body" style="max-height: 440px; overflow-y: auto; padding: 16px;">
            <div id="auditModalFeed" class="annotation-history-feed" style="max-height: none; overflow: visible;">
                <div class="history-empty-state">
                    <span>Loading audit records...</span>
                </div>
            </div>
        </div>
        <div class="edms-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeWsAuditModal()">Close</button>
        </div>
    </div>
</div>

<script>
function switchWsTab(tab) {
    const btnMyWs = document.getElementById('tabBtnMyWorkspace');
    const btnRecent = document.getElementById('tabBtnRecent');
    const paneMyWs = document.getElementById('paneMyWorkspace');
    const paneRecent = document.getElementById('paneRecent');

    if (tab === 'my-workspace') {
        btnMyWs.classList.add('active');
        btnRecent.classList.remove('active');
        paneMyWs.style.display = '';
        paneRecent.style.display = 'none';
    } else {
        btnRecent.classList.add('active');
        btnMyWs.classList.remove('active');
        paneRecent.style.display = '';
        paneMyWs.style.display = 'none';
    }
}

function openWsAuditModal(docId, docName) {
    const modal = document.getElementById('modalAuditHistory');
    const nameEl = document.getElementById('auditModalDocName');
    const feedEl = document.getElementById('auditModalFeed');

    if (!modal) return;
    if (nameEl) nameEl.textContent = docName;
    if (feedEl) feedEl.innerHTML = '<div class="history-empty-state"><span>Loading audit records...</span></div>';

    modal.classList.add('is-open');

    fetch(`explorer.php?action=get_audit_history&doc_id=${docId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (feedEl && data.success) {
            feedEl.innerHTML = data.html || '<div class="history-empty-state"><span>No audit records recorded yet.</span></div>';
        }
    })
    .catch(() => {
        if (feedEl) feedEl.innerHTML = '<div class="history-empty-state" style="color:var(--red);"><span>Failed to load audit history.</span></div>';
    });
}

function closeWsAuditModal() {
    const modal = document.getElementById('modalAuditHistory');
    if (modal) modal.classList.remove('is-open');
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalAuditHistory');
    if (modal && e.target === modal) {
        closeWsAuditModal();
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
