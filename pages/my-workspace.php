<?php
$pageTitle = 'My Workspace';
$activeNav = 'my-workspace';
$extraCss  = ['explorer.css'];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/review.php';
require_once __DIR__ . '/../includes/contributors.php';
require_once __DIR__ . '/../includes/header.php';

$pdo = db();
$user = current_user() ?? ['id' => 1, 'full_name' => 'User', 'role' => 'viewer'];
$userId = (int)($user['id'] ?? 1);

// 1. My Workspace: Documents authored by user OR where user is a contributor
$myDocsStmt = $pdo->prepare("
    SELECT DISTINCT d.id, d.name, d.version, d.status, d.file_extension, d.updated_at, d.created_at,
           d.parent_id, p.name AS parent_name,
           COALESCE(u.full_name, u.username, 'System') AS creator_name,
           u.avatar AS creator_avatar,
           (d.created_by = :uid1) AS is_owner
    FROM dms_nodes d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN dms_nodes p ON d.parent_id = p.id
    LEFT JOIN dms_document_contributors c ON d.id = c.document_id AND c.user_id = :uid2
    WHERE d.node_type = 'document'
      AND d.is_deleted = 0
      AND (d.created_by = :uid3 OR c.user_id IS NOT NULL)
    ORDER BY d.updated_at DESC
    LIMIT 15
");
$myDocsStmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId]);
$myDocs = $myDocsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Recently Opened Documents: Aggregated by most recent access by current user
$recentTouchedStmt = $pdo->prepare("
    SELECT d.id, d.name, d.version, d.status, d.file_extension,
           d.parent_id, p.name AS parent_name,
           COALESCE(u.full_name, u.username, 'System') AS creator_name,
           u.avatar AS creator_avatar,
           MAX(l.created_at) AS last_touched_at
    FROM dms_nodes d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN dms_nodes p ON d.parent_id = p.id
    INNER JOIN system_logs l ON l.entity_type = 'document' AND l.entity_id = d.id AND l.user_id = :uid
    WHERE d.node_type = 'document'
      AND d.is_deleted = 0
      AND l.action IN ('document.view', 'document.download', 'document.upload', 'version.upload', 'document.review_requested')
    GROUP BY d.id, d.name, d.version, d.status, d.file_extension, d.parent_id, p.name, u.full_name, u.username, u.avatar
    ORDER BY last_touched_at DESC
    LIMIT 15
");
$recentTouchedStmt->execute(['uid' => $userId]);
$recentDocs = $recentTouchedStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Pending Review Requests: Documents awaiting user's review approval
$pendingReviews = get_pending_reviews_for_user($userId);
$totalPending = count($pendingReviews);

$statusCounts = array_count_values(array_column($myDocs, 'status'));
$totalMyDocs = count($myDocs);
$totalRecent = count($recentDocs);

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

function ws_loc_badge(?int $pid, ?string $pname): string {
    $url = $pid ? "explorer.php?mode=project&folder=$pid" : 'explorer.php?mode=project';
    $name = e($pname ?: 'Projects');
    return "<a href=\"$url\" class=\"location-badge\" title=\"Located in: $name\">" . render_icon('folder') . "<span class=\"loc-name\">$name</span></a>";
}
?>

<div class="welcome-header">
    <h1>Welcome, <?php echo e($fullName); ?>!</h1>
</div>
    
<!-- Main 2-Column Dashboard Grid: Gmail-style Mailbox Left, Documents Right -->
<div class="workspace-dashboard-grid">

    <!-- Left Column: Mailbox Category Sidebar -->
    <aside class="ws-mailbox-sidebar">
        <div class="ws-mailbox-group">
            <div class="ws-mailbox-heading">WORKSPACE</div>
            <button type="button" class="ws-mailbox-item is-active" data-filter="all" onclick="filterWorkspace(this, 'all')">
                <span class="ws-mailbox-icon"><?php echo render_icon('workspace'); ?></span>
                <span class="ws-mailbox-label">My Workspace</span>
                <span class="ws-mailbox-count"><?php echo $totalMyDocs; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="recent" onclick="filterWorkspace(this, 'recent')">
                <span class="ws-mailbox-icon"><?php echo render_icon('history'); ?></span>
                <span class="ws-mailbox-label">Recently Opened</span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="pending" onclick="filterWorkspace(this, 'pending')">
                <span class="ws-mailbox-icon"><?php echo render_icon('tasks'); ?></span>
                <span class="ws-mailbox-label">Pending Review</span>
                <?php if ($totalPending > 0): ?>
                    <span class="ws-mailbox-count" style="background: var(--amber-soft); color: var(--amber-ink); font-weight: 700;"><?php echo $totalPending; ?></span>
                <?php endif; ?>
            </button>
        </div>

        <div class="ws-mailbox-divider"></div>

        <div class="ws-mailbox-group">
            <div class="ws-mailbox-heading">STATUS</div>
            <button type="button" class="ws-mailbox-item" data-filter="active" onclick="filterWorkspace(this, 'active')">
                <span class="ws-status-dot" style="background: var(--info-blue);"></span>
                <span class="ws-mailbox-label">Active</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['active'] ?? 0; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="hold" onclick="filterWorkspace(this, 'hold')">
                <span class="ws-status-dot" style="background: var(--amber);"></span>
                <span class="ws-mailbox-label">Hold</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['hold'] ?? 0; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="rejected" onclick="filterWorkspace(this, 'rejected')">
                <span class="ws-status-dot" style="background: var(--red);"></span>
                <span class="ws-mailbox-label">Rejected</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['rejected'] ?? 0; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="published" onclick="filterWorkspace(this, 'published')">
                <span class="ws-status-dot" style="background: var(--green);"></span>
                <span class="ws-mailbox-label">Published</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['published'] ?? 0; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="suspend" onclick="filterWorkspace(this, 'suspend')">
                <span class="ws-status-dot" style="background: var(--red-soft, #fca5a5); border: 1px solid var(--red);"></span>
                <span class="ws-mailbox-label">Suspend</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['suspend'] ?? 0; ?></span>
            </button>
            <button type="button" class="ws-mailbox-item" data-filter="obsolete" onclick="filterWorkspace(this, 'obsolete')">
                <span class="ws-status-dot" style="background: var(--slate);"></span>
                <span class="ws-mailbox-label">Obsolete</span>
                <span class="ws-mailbox-count"><?php echo $statusCounts['obsolete'] ?? 0; ?></span>
            </button>
        </div>
    </aside>

    <!-- Right Column: Document Feeds Stage -->
    <div class="explorer-wrapper" style="margin: 0;">

        <!-- Top Action & Search Bar -->
        <div class="explorer-toolbar-card" style="margin-bottom: 16px;">
            <form method="GET" action="explorer.php" class="explorer-search-form" id="wsSearchForm">
                <div class="explorer-search-box">
                    <span class="search-icon"><?php echo render_icon('search'); ?></span>
                    <input type="text" name="q" id="wsSearchInput" placeholder="Search documents or folders..." class="search-input" autocomplete="off">
                    <a href="#" id="wsSearchClear" class="search-clear" title="Clear search" style="display: none;" onclick="clearWsSearch(event)">&times;</a>
                </div>
            </form>

            <div class="toolbar-actions" style="display: flex; gap: 8px;">
                <a href="explorer.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">
                    <?php echo render_icon('explorer'); ?>
                    <span>Browse All</span>
                </a>
                <button type="button" id="btnUploadDoc" class="btn btn-primary" onclick="openWsUploadModal()">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Document</span>
                </button>
            </div>
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
                    <div class="col-hdr-owner">Owner</div>
                    <div class="col-hdr-location">Location</div>
                    <div class="col-hdr-status">Status</div>
                    <div class="col-hdr-actions"></div>
                </div>

                <?php foreach ($myDocs as $doc): ?>
                    <?php $extIcon = $doc['file_extension'] ?: 'pdf'; ?>
                    <div class="explorer-item-card is-document" data-status="<?php echo e($doc['status']); ?>">
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($doc['name']); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($doc['name']); ?>">
                                    <?php echo e($doc['name']); ?>
                                </a>
                                <?php if (!empty($doc['version'])): ?>
                                    <span class="version-badge"><?php echo e($doc['version']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Owner Column -->
                        <div class="item-owner-col" title="Created by <?php echo e($doc['creator_name']); ?>">
                            <?php echo render_user_avatar_sm($doc['creator_name'], $doc['creator_avatar'] ?? null); ?>
                            <span class="owner-name"><?php echo e($doc['creator_name']); ?></span>
                        </div>

                        <div class="item-location-col"><?php echo ws_loc_badge($doc['parent_id'] ? (int)$doc['parent_id'] : null, $doc['parent_name'] ?? null); ?></div>

                        <div class="item-status-col">
                            <span class="status-badge <?php echo ws_status_badge($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div class="item-actions-col">
                            <button type="button" class="btn-action btn-more-actions" title="More Actions" onclick="openWsDocMenu(event, <?php echo (int)$doc['id']; ?>, '<?php echo addslashes(e($doc['name'])); ?>')">
                                <?php echo render_icon('more-v'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="explorer-empty-card" id="wsFilterEmpty" style="display: none; padding: 32px 16px;">
                <div class="empty-icon"><?php echo render_icon('search'); ?></div>
                <h3 class="empty-title">No matching documents</h3>
                <p class="empty-subtitle">There are no documents matching this status filter in your workspace.</p>
            </div>
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
                    <div class="col-hdr-owner">Owner</div>
                    <div class="col-hdr-modified">Last Accessed</div>
                    <div class="col-hdr-location">Location</div>
                    <div class="col-hdr-status">Status</div>
                    <div class="col-hdr-actions"></div>
                </div>

                <?php foreach ($recentDocs as $doc): ?>
                    <?php $extIcon = $doc['file_extension'] ?: 'pdf'; ?>
                    <div class="explorer-item-card is-document" data-status="<?php echo e($doc['status']); ?>">
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($doc['name']); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($doc['name']); ?>">
                                    <?php echo e($doc['name']); ?>
                                </a>
                                <?php if (!empty($doc['version'])): ?>
                                    <span class="version-badge"><?php echo e($doc['version']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Owner Column -->
                        <div class="item-owner-col" title="Created by <?php echo e($doc['creator_name']); ?>">
                            <?php echo render_user_avatar_sm($doc['creator_name'], $doc['creator_avatar'] ?? null); ?>
                            <span class="owner-name"><?php echo e($doc['creator_name']); ?></span>
                        </div>

                        <div class="item-modified-col">
                            <span style="font-size: 11px; color: var(--muted);">
                                <?php echo date('M j, Y • H:i', strtotime($doc['last_touched_at'])); ?>
                            </span>
                        </div>

                        <div class="item-location-col"><?php echo ws_loc_badge($doc['parent_id'] ? (int)$doc['parent_id'] : null, $doc['parent_name'] ?? null); ?></div>

                        <div class="item-status-col">
                            <span class="status-badge <?php echo ws_status_badge($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div class="item-actions-col">
                            <button type="button" class="btn-action btn-more-actions" title="More Actions" onclick="openWsDocMenu(event, <?php echo (int)$doc['id']; ?>, '<?php echo addslashes(e($doc['name'])); ?>')">
                                <?php echo render_icon('more-v'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tab 3: Pending Review List (Hidden by default) -->
        <div id="panePendingReview" class="explorer-card-list" style="display: none;">
            <?php if (empty($pendingReviews)): ?>
                <div class="explorer-empty-card" style="padding: 32px 16px;">
                    <div class="empty-icon"><?php echo render_icon('tasks'); ?></div>
                    <h3 class="empty-title">No pending reviews</h3>
                    <p class="empty-subtitle">Documents requiring your approval or review will be listed here.</p>
                </div>
            <?php else: ?>
                <div class="explorer-table-header">
                    <div style="width: 32px;"></div>
                    <div class="col-hdr-name">Document</div>
                    <div class="col-hdr-owner">Requester</div>
                    <div class="col-hdr-modified">Requested At</div>
                    <div class="col-hdr-location">Location</div>
                    <div class="col-hdr-status">Status</div>
                    <div class="col-hdr-actions"></div>
                </div>

                <?php foreach ($pendingReviews as $rev): ?>
                    <?php $extIcon = $rev['file_extension'] ?: 'pdf'; ?>
                    <div class="explorer-item-card is-document" data-status="<?php echo e($rev['document_status']); ?>">
                        <a href="view.php?id=<?php echo (int)$rev['document_id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($rev['document_name']); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$rev['document_id']; ?>" class="item-title" title="<?php echo e($rev['document_name']); ?>">
                                    <?php echo e($rev['document_name']); ?>
                                </a>
                                <?php if (!empty($rev['target_version'])): ?>
                                    <span class="version-badge"><?php echo e($rev['target_version']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Requester Column -->
                        <div class="item-owner-col" title="Requested by <?php echo e($rev['requested_by_name']); ?>">
                            <?php echo render_user_avatar_sm($rev['requested_by_name'], $rev['requester_avatar'] ?? null); ?>
                            <span class="owner-name"><?php echo e($rev['requested_by_name']); ?></span>
                        </div>

                        <div class="item-modified-col">
                            <span style="font-size: 11px; color: var(--muted);">
                                <?php echo date('M j, Y • H:i', strtotime($rev['review_requested_at'])); ?>
                            </span>
                        </div>

                        <div class="item-location-col">
                            <?php echo ws_loc_badge(null, $rev['parent_folder_name'] ?? null); ?>
                        </div>

                        <div class="item-status-col">
                            <span class="status-badge badge-hold" title="Review Pending">
                                Pending
                            </span>
                        </div>

                        <div class="item-actions-col">
                            <a href="view.php?id=<?php echo (int)$rev['document_id']; ?>" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px; white-space: nowrap; height: 26px; display: inline-flex; align-items: center; gap: 4px;" title="Open document to review">
                                <?php echo render_icon('view'); ?>
                                <span>Review</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

</div>

<div class="edms-action-popover" id="wsActionPopover" style="display: none;">
    <a href="#" class="popover-item" id="wsPopActionView" style="text-decoration: none;"><?php echo render_icon('view'); ?><span>Open Workspace</span></a>
    <a href="#" class="popover-item" id="wsPopActionDownload" style="text-decoration: none;"><?php echo render_icon('download'); ?><span>Download</span></a>
    <button type="button" class="popover-item" id="wsPopActionCopyLink"><?php echo render_icon('link'); ?><span>Copy Link</span></button>
    <button type="button" class="popover-item" id="wsPopActionAuditHistory"><?php echo render_icon('history'); ?><span>Audit History</span></button>
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

<!-- Modal: Upload Document (Reusing existing EDMS Upload Specimen Modal) -->
<div class="edms-modal-backdrop" id="modalUploadDoc">
    <div class="edms-modal-dialog" style="max-width: 520px; width: 95%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('cloud-upload'); ?>
                <span>Upload Document</span>
            </div>
            <button type="button" class="btn-modal-close" onclick="closeWsUploadModal()" title="Close modal">&times;</button>
        </div>
        <form id="formUploadDoc" method="POST" action="explorer.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="parent_id" value="">

            <div class="edms-modal-body">
                <!-- Dropzone Area -->
                <div class="upload-dropzone" id="uploadDropzone">
                    <input type="file" name="file" id="uploadFileInput" class="upload-file-input" required onchange="handleWsFileSelection(this.files[0])">
                    <div class="dropzone-content">
                        <div class="dropzone-icon">
                            <?php echo render_icon('cloud-upload'); ?>
                        </div>
                        <p class="dropzone-text">
                            <strong>Click to browse</strong> or drag &amp; drop your file here
                        </p>
                        <span class="dropzone-hint">PDF, DOCX, XLSX, DWG, PNG, ZIP, etc.</span>
                    </div>
                </div>

                <!-- Selected File Pill -->
                <div class="selected-file-preview" id="selectedFilePreview" style="display: none;">
                    <div class="preview-file-icon">
                        <?php echo render_icon('files'); ?>
                    </div>
                    <div class="preview-file-details">
                        <span class="preview-file-name" id="previewFileName">filename.ext</span>
                        <span class="preview-file-size" id="previewFileSize">0 KB</span>
                    </div>
                    <button type="button" class="btn-clear-selected-file" onclick="clearWsSelectedFile()" title="Remove file">&times;</button>
                </div>

                <!-- Document Form Inputs -->
                <div class="form-group" style="margin-top: 14px;">
                    <label for="uploadDocName" class="form-label">Document Title</label>
                    <input type="text" id="uploadDocName" name="name" class="form-input" placeholder="e.g. Standard Operating Procedure" required autocomplete="off">
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label for="uploadDocStatus" class="form-label">Initial Status</label>
                    <div class="select-wrapper">
                        <select id="uploadDocStatus" name="status" class="form-input">
                            <option value="active" selected>Active (Draft)</option>
                            <option value="published">Published</option>
                            <option value="hold">On Hold</option>
                        </select>
                    </div>
                </div>

                <p class="form-help">Target location: <strong>Root directory</strong></p>
            </div>

            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeWsUploadModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitUpload">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentWsFilter = 'all';

function filterWorkspace(btn, filter) {
    document.querySelectorAll('.ws-mailbox-item').forEach(el => el.classList.remove('is-active'));
    if (btn) btn.classList.add('is-active');
    currentWsFilter = filter;
    applyWorkspaceFilters();
}

function applyWorkspaceFilters() {
    const paneMyWs = document.getElementById('paneMyWorkspace');
    const paneRecent = document.getElementById('paneRecent');
    const panePending = document.getElementById('panePendingReview');
    const emptyFilterEl = document.getElementById('wsFilterEmpty');
    const q = wsSearch ? wsSearch.value.trim().toLowerCase() : '';

    if (currentWsFilter === 'recent') {
        if (paneMyWs) paneMyWs.style.display = 'none';
        if (panePending) panePending.style.display = 'none';
        if (paneRecent) {
            paneRecent.style.display = '';
            paneRecent.querySelectorAll('.explorer-item-card.is-document').forEach(card => {
                const titleEl = card.querySelector('.item-title');
                const title = titleEl ? titleEl.textContent.toLowerCase() : '';
                card.style.display = (q === '' || title.includes(q)) ? '' : 'none';
            });
        }
        if (emptyFilterEl) emptyFilterEl.style.display = 'none';
        return;
    }

    if (currentWsFilter === 'pending') {
        if (paneMyWs) paneMyWs.style.display = 'none';
        if (paneRecent) paneRecent.style.display = 'none';
        if (panePending) {
            panePending.style.display = '';
            panePending.querySelectorAll('.explorer-item-card.is-document').forEach(card => {
                const titleEl = card.querySelector('.item-title');
                const title = titleEl ? titleEl.textContent.toLowerCase() : '';
                card.style.display = (q === '' || title.includes(q)) ? '' : 'none';
            });
        }
        if (emptyFilterEl) emptyFilterEl.style.display = 'none';
        return;
    }

    if (paneRecent) paneRecent.style.display = 'none';
    if (panePending) panePending.style.display = 'none';
    if (paneMyWs) paneMyWs.style.display = '';

    let visibleCount = 0;
    const cards = paneMyWs ? paneMyWs.querySelectorAll('.explorer-item-card.is-document') : [];
    cards.forEach(card => {
        const matchStatus = (currentWsFilter === 'all' || card.dataset.status === currentWsFilter);
        const titleEl = card.querySelector('.item-title');
        const title = titleEl ? titleEl.textContent.toLowerCase() : '';
        const matchSearch = (q === '' || title.includes(q));
        const visible = matchStatus && matchSearch;
        card.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    if (emptyFilterEl) {
        emptyFilterEl.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
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

// Real-time Workspace Search + Explorer Submission
const wsSearch = document.getElementById('wsSearchInput');
const wsClear = document.getElementById('wsSearchClear');
if (wsSearch) {
    wsSearch.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        if (wsClear) wsClear.style.display = q.length > 0 ? 'block' : 'none';
        applyWorkspaceFilters();
    });
}

function clearWsSearch(e) {
    if (e) e.preventDefault();
    if (wsSearch) {
        wsSearch.value = '';
        wsSearch.dispatchEvent(new Event('input'));
        wsSearch.focus();
    }
}

// Copy Document Link Handler
function copyWsDocLink(e, docId) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
    const targetUrl = basePath + 'view.php?id=' + encodeURIComponent(docId);
    if (typeof window.copyTextToClipboard === 'function') {
        window.copyTextToClipboard(targetUrl, 'Document link copied to clipboard!');
    }
}

// Upload Modal Handlers
function openWsUploadModal() {
    clearWsSelectedFile();
    const docName = document.getElementById('uploadDocName');
    if (docName) docName.value = '';
    const modal = document.getElementById('modalUploadDoc');
    if (modal) modal.classList.add('is-open');
}

function closeWsUploadModal() {
    const modal = document.getElementById('modalUploadDoc');
    if (modal) modal.classList.remove('is-open');
}

function handleWsFileSelection(file) {
    if (!file) return;
    const nameEl = document.getElementById('previewFileName');
    const sizeEl = document.getElementById('previewFileSize');
    const preview = document.getElementById('selectedFilePreview');
    const dropzone = document.getElementById('uploadDropzone');
    const docName = document.getElementById('uploadDocName');

    if (nameEl) nameEl.textContent = file.name;
    if (sizeEl) sizeEl.textContent = (file.size / 1024).toFixed(1) + ' KB';
    if (preview) preview.style.display = 'flex';
    if (dropzone) dropzone.style.display = 'none';

    if (docName && !docName.value) {
        docName.value = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]/g, ' ');
    }
}

function clearWsSelectedFile() {
    const fileInput = document.getElementById('uploadFileInput');
    const preview = document.getElementById('selectedFilePreview');
    const dropzone = document.getElementById('uploadDropzone');
    if (fileInput) fileInput.value = '';
    if (preview) preview.style.display = 'none';
    if (dropzone) dropzone.style.display = 'block';
}

// Dropzone Drag-and-Drop
const wsDropzone = document.getElementById('uploadDropzone');
const wsFileInput = document.getElementById('uploadFileInput');
if (wsDropzone && wsFileInput) {
    ['dragenter', 'dragover'].forEach(name => {
        wsDropzone.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            wsDropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(name => {
        wsDropzone.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            wsDropzone.classList.remove('is-dragover');
        });
    });
    wsDropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        if (dt && dt.files && dt.files[0]) {
            wsFileInput.files = dt.files;
            handleWsFileSelection(dt.files[0]);
        }
    });
}

function openWsDocMenu(e, id, name) {
    e.stopPropagation();
    const p = document.getElementById('wsActionPopover');
    p.querySelector('#wsPopActionView').href = `view.php?id=${id}`;
    p.querySelector('#wsPopActionDownload').href = `download.php?id=${id}`;
    p.querySelector('#wsPopActionCopyLink').onclick = ev => { p.style.display = 'none'; copyWsDocLink(ev, id); };
    p.querySelector('#wsPopActionAuditHistory').onclick = () => { p.style.display = 'none'; openWsAuditModal(id, name); };
    const r = e.currentTarget.getBoundingClientRect();
    p.style.cssText = `display:flex; left:${Math.max(10, r.right - 165)}px; top:${r.bottom + 160 > window.innerHeight ? Math.max(10, r.top - 160) : r.bottom + 4}px;`;
}
document.addEventListener('click', e => {
    const p = document.getElementById('wsActionPopover');
    if (p && !p.contains(e.target) && !e.target.closest('.btn-more-actions')) p.style.display = 'none';
    if (e.target.id === 'modalAuditHistory') closeWsAuditModal();
    if (e.target.id === 'modalUploadDoc') closeWsUploadModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const p = document.getElementById('wsActionPopover');
        if (p) p.style.display = 'none';
        closeWsAuditModal();
        closeWsUploadModal();
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
