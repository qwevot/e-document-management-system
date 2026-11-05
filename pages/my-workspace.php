<?php
$pageTitle = 'My Workspace';
$activeNav = 'my-workspace';
$extraCss  = ['explorer.css'];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/review.php';
require_once __DIR__ . '/../includes/contributors.php';
require_login();

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

// 1. My Workspace: Documents authored by user, where user is a contributor, OR starred by user
$myDocsStmt = $pdo->prepare("
    SELECT DISTINCT d.id, d.name, d.version, d.status, d.file_extension, d.updated_at, d.created_at,
           d.parent_id, p.name AS parent_name,
           COALESCE(u.full_name, u.username, 'System') AS creator_name,
           u.avatar AS creator_avatar,
           (d.created_by = :uid1) AS is_owner,
           (d.created_by = :uid1b OR c.user_id IS NOT NULL) AS is_mine,
           (s.node_id IS NOT NULL) AS is_starred
    FROM dms_nodes d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN dms_nodes p ON d.parent_id = p.id
    LEFT JOIN dms_document_contributors c ON d.id = c.document_id AND c.user_id = :uid2
    LEFT JOIN dms_starred_nodes s ON d.id = s.node_id AND s.user_id = :uid5
    WHERE d.node_type = 'document'
      AND (
          s.node_id IS NOT NULL
          OR (d.is_deleted = 0 AND (d.created_by = :uid3 OR c.user_id IS NOT NULL))
          OR (d.is_deleted = 1 AND d.status = 'obsolete' AND d.created_by = :uid4)
      )
    ORDER BY d.updated_at DESC
");
$myDocsStmt->execute(['uid1' => $userId, 'uid1b' => $userId, 'uid2' => $userId, 'uid3' => $userId, 'uid4' => $userId, 'uid5' => $userId]);
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

$mineDocs = array_filter($myDocs, fn($d) => !empty($d['is_mine']));
$statusCounts = array_count_values(array_column($mineDocs, 'status'));
$totalMyDocs = count($mineDocs) - ($statusCounts['obsolete'] ?? 0);
$totalStarred = count(array_filter($myDocs, fn($d) => !empty($d['is_starred'])));

function ws_loc_badge(?int $pid, ?string $pname): string {
    $url = $pid ? "explorer.php?mode=project&folder=$pid" : 'explorer.php?mode=project';
    $name = e($pname ?: 'Projects');
    return "<a href=\"$url\" class=\"location-badge\" title=\"Located in: $name\">" . render_icon('folder') . "<span class=\"loc-name\">$name</span></a>";
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="welcome-header">
    <h1>Welcome, <?php echo e($fullName); ?>!</h1>
</div>
    
<?php require_once __DIR__ . '/../includes/flash.php'; ?>

<!-- Document Feeds Stage -->
<div class="explorer-wrapper">

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
                <?php if (can_edit_dms($user)): ?>
                    <button type="button" id="btnUploadDoc" class="btn btn-primary">
                        <?php echo render_icon('cloud-upload'); ?>
                        <span>Upload Document</span>
                    </button>
                <?php endif; ?>
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
                    <div class="col-hdr-checkbox-icon"></div>
                    <div class="col-hdr-name">Document</div>
                    <div class="col-hdr-owner">Owner</div>
                    <div class="col-hdr-location">Location</div>
                    <div class="col-hdr-status">Status</div>
                    <div class="col-hdr-actions"></div>
                </div>

                <?php foreach ($myDocs as $doc): ?>
                    <?php
                    $extIcon = $doc['file_extension'] ?: 'pdf';
                    $displayName = format_document_display_name($doc['name'], $doc['file_extension'] ?? null);
                    ?>
                    <div class="explorer-item-card is-document" data-id="<?php echo (int)$doc['id']; ?>" data-name="<?php echo htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8'); ?>" data-type="document" data-location="<?php echo htmlspecialchars($doc['parent_name'] ?? 'Projects', ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo e($doc['status']); ?>" data-mine="<?php echo !empty($doc['is_mine']) ? '1' : '0'; ?>" data-starred="<?php echo !empty($doc['is_starred']) ? '1' : '0'; ?>">
                        <div class="item-checkbox-col">
                            <input type="checkbox" class="item-checkbox" value="<?php echo (int)$doc['id']; ?>" aria-label="Select item">
                        </div>
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($displayName); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($displayName); ?>">
                                    <?php echo e($displayName); ?>
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
                            <span class="status-badge <?php echo get_status_class($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div class="item-actions-col">
                            <button type="button" class="btn-action btn-star <?php echo !empty($doc['is_starred']) ? 'is-starred' : ''; ?>" onclick="toggleStar(event, <?php echo (int)$doc['id']; ?>, this)" title="Star document"><?php echo render_icon('star'); ?></button>
                            <button type="button" class="btn-action btn-more-actions" 
                                    data-id="<?php echo (int)$doc['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                    data-type="document" 
                                    data-location="<?php echo htmlspecialchars($doc['parent_name'] ?? 'Projects', ENT_QUOTES, 'UTF-8'); ?>" 
                                    title="More Actions">
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
                    <div class="col-hdr-checkbox-icon"></div>
                    <div class="col-hdr-name">Document</div>
                    <div class="col-hdr-owner">Owner</div>
                    <div class="col-hdr-modified">Last Accessed</div>
                    <div class="col-hdr-location">Location</div>
                    <div class="col-hdr-status">Status</div>
                    <div class="col-hdr-actions"></div>
                </div>

                <?php foreach ($recentDocs as $doc): ?>
                    <?php
                    $extIcon = $doc['file_extension'] ?: 'pdf';
                    $displayName = format_document_display_name($doc['name'], $doc['file_extension'] ?? null);
                    ?>
                    <div class="explorer-item-card is-document" data-id="<?php echo (int)$doc['id']; ?>" data-name="<?php echo htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8'); ?>" data-type="document" data-location="<?php echo htmlspecialchars($doc['parent_name'] ?? 'Projects', ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo e($doc['status']); ?>">
                        <div class="item-checkbox-col">
                            <input type="checkbox" class="item-checkbox" value="<?php echo (int)$doc['id']; ?>" aria-label="Select item">
                        </div>
                        <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($displayName); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$doc['id']; ?>" class="item-title" title="<?php echo e($displayName); ?>">
                                    <?php echo e($displayName); ?>
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
                                <?php echo date('M j, Y', strtotime($doc['last_touched_at'])); ?> &bull; <?php echo date('H:i', strtotime($doc['last_touched_at'])); ?>
                            </span>
                        </div>

                        <div class="item-location-col"><?php echo ws_loc_badge($doc['parent_id'] ? (int)$doc['parent_id'] : null, $doc['parent_name'] ?? null); ?></div>

                        <div class="item-status-col">
                            <span class="status-badge <?php echo get_status_class($doc['status']); ?>">
                                <?php echo ucfirst(e($doc['status'])); ?>
                            </span>
                        </div>

                        <div class="item-actions-col">
                            <button type="button" class="btn-action btn-more-actions" 
                                    data-id="<?php echo (int)$doc['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                    data-type="document" 
                                    data-location="<?php echo htmlspecialchars($doc['parent_name'] ?? 'Projects', ENT_QUOTES, 'UTF-8'); ?>" 
                                    title="More Actions">
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
                    <?php
                    $extIcon = $rev['file_extension'] ?: 'pdf';
                    $displayName = format_document_display_name($rev['document_name'], $rev['file_extension'] ?? null);
                    ?>
                    <div class="explorer-item-card is-document" data-status="<?php echo e($rev['document_status']); ?>">
                        <a href="view.php?id=<?php echo (int)$rev['document_id']; ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open <?php echo e($displayName); ?>">
                            <?php echo render_icon($extIcon); ?>
                        </a>

                        <div class="item-main-col">
                            <div class="item-title-row">
                                <a href="view.php?id=<?php echo (int)$rev['document_id']; ?>" class="item-title" title="<?php echo e($displayName); ?>">
                                    <?php echo e($displayName); ?>
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
                                <?php echo date('M j, Y', strtotime($rev['review_requested_at'])); ?> &bull; <?php echo date('H:i', strtotime($rev['review_requested_at'])); ?>
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

        <!-- Pagination Controls Footer (Identical to explorer.php) -->
        <div class="explorer-pagination-bar" id="wsPaginationBar" style="display: none; margin-top: 12px;">
            <div class="pagination-info">
                <span>Showing <strong id="wsPagStart">1</strong>-<strong id="wsPagEnd">10</strong> of <strong id="wsPagTotal">0</strong> items</span>
            </div>

            <div class="pagination-actions">
                <div class="pagination-per-page">
                    <label for="wsPerPageSelect" class="pagination-label">Rows per page:</label>
                    <select id="wsPerPageSelect" class="pagination-select" aria-label="Items per page" onchange="changeWsPerPage(this.value)">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                <div class="pagination-nav-group">
                    <button type="button" class="pagination-btn" id="btnWsPagPrev" onclick="changeWsPage(wsCurrentPage - 1)" title="Previous Page">
                        <?php echo render_icon('arrow-left'); ?>
                    </button>
                    <div class="pagination-pages" id="wsPagPages"></div>
                    <button type="button" class="pagination-btn" id="btnWsPagNext" onclick="changeWsPage(wsCurrentPage + 1)" title="Next Page">
                        <?php echo render_icon('chevron-right'); ?>
                    </button>
                </div>
            </div>
        </div>

</div>

<?php require_once __DIR__ . '/../includes/item_actions.php'; ?>
<?php include __DIR__ . '/../includes/modal_upload_doc.php'; ?>

<script>
let currentWsFilter = new URLSearchParams(window.location.search).get('filter') || 'all';
let wsCurrentPage = 1;
let wsPerPage = 25;

function filterWorkspace(btn, filter) {
    document.querySelectorAll('.sidebar [data-filter]').forEach(el => el.classList.toggle('active', el.dataset.filter === filter));
    if (filter === 'all' && btn && !btn.classList.contains('open') && window.toggleNavGroup) window.toggleNavGroup(btn);
    currentWsFilter = filter;
    wsCurrentPage = 1;
    history.replaceState(null, '', '?filter=' + encodeURIComponent(filter));
    applyWorkspaceFilters();
}

function changeWsPage(p) {
    wsCurrentPage = p;
    applyWorkspaceFilters();
}

function changeWsPerPage(val) {
    wsPerPage = parseInt(val, 10) || 25;
    wsCurrentPage = 1;
    applyWorkspaceFilters();
}

function applyWorkspaceFilters() {
    const paneMyWs = document.getElementById('paneMyWorkspace');
    const paneRecent = document.getElementById('paneRecent');
    const panePending = document.getElementById('panePendingReview');
    const emptyFilterEl = document.getElementById('wsFilterEmpty');
    const pagBar = document.getElementById('wsPaginationBar');
    const q = wsSearch ? wsSearch.value.trim().toLowerCase() : '';

    let activePane = null;
    let targetCards = [];

    if (currentWsFilter === 'recent') {
        if (paneMyWs) paneMyWs.style.display = 'none';
        if (panePending) panePending.style.display = 'none';
        if (paneRecent) {
            paneRecent.style.display = '';
            activePane = paneRecent;
            targetCards = Array.from(paneRecent.querySelectorAll('.explorer-item-card.is-document'));
        }
    } else if (currentWsFilter === 'pending') {
        if (paneMyWs) paneMyWs.style.display = 'none';
        if (paneRecent) paneRecent.style.display = 'none';
        if (panePending) {
            panePending.style.display = '';
            activePane = panePending;
            targetCards = Array.from(panePending.querySelectorAll('.explorer-item-card.is-document'));
        }
    } else {
        if (paneRecent) paneRecent.style.display = 'none';
        if (panePending) panePending.style.display = 'none';
        if (paneMyWs) {
            paneMyWs.style.display = '';
            activePane = paneMyWs;
            targetCards = Array.from(paneMyWs.querySelectorAll('.explorer-item-card.is-document'));
        }
    }

    if (emptyFilterEl) emptyFilterEl.style.display = 'none';

    // 1. Filter matching candidates
    const matchingCards = targetCards.filter(card => {
        const status = card.dataset.status;
        const matchStatus = (currentWsFilter === 'recent' || currentWsFilter === 'pending')
            ? true
            : (currentWsFilter === 'starred'
                ? (card.dataset.starred === '1')
                : (card.dataset.mine === '1' && (currentWsFilter === 'all' ? status !== 'obsolete' : status === currentWsFilter)));
        const titleEl = card.querySelector('.item-title');
        const title = titleEl ? titleEl.textContent.toLowerCase() : '';
        const matchSearch = (q === '' || title.includes(q));
        return matchStatus && matchSearch;
    });

    const totalMatching = matchingCards.length;

    // Show empty filter message if my workspace has cards but none matched filter/query
    if (emptyFilterEl && activePane === paneMyWs) {
        emptyFilterEl.style.display = (totalMatching === 0 && targetCards.length > 0) ? 'block' : 'none';
    }

    // 2. Pagination Math
    const totalPages = Math.max(1, Math.ceil(totalMatching / wsPerPage));
    if (wsCurrentPage > totalPages) wsCurrentPage = totalPages;
    if (wsCurrentPage < 1) wsCurrentPage = 1;

    const startIndex = (wsCurrentPage - 1) * wsPerPage;
    const endIndex = Math.min(startIndex + wsPerPage, totalMatching);

    // 3. Render items visibility
    targetCards.forEach(card => card.style.display = 'none');
    for (let i = startIndex; i < endIndex; i++) {
        if (matchingCards[i]) matchingCards[i].style.display = '';
    }

    // 4. Update Pagination Bar (Identical styling to explorer.php)
    if (pagBar) {
        if (totalMatching > 0) {
            pagBar.style.display = 'flex';
            const pagStart = document.getElementById('wsPagStart');
            const pagEnd = document.getElementById('wsPagEnd');
            const pagTotal = document.getElementById('wsPagTotal');
            const btnPrev = document.getElementById('btnWsPagPrev');
            const btnNext = document.getElementById('btnWsPagNext');
            const pagPages = document.getElementById('wsPagPages');

            if (pagStart) pagStart.textContent = totalMatching > 0 ? (startIndex + 1) : 0;
            if (pagEnd) pagEnd.textContent = endIndex;
            if (pagTotal) pagTotal.textContent = totalMatching;

            if (btnPrev) {
                btnPrev.disabled = wsCurrentPage <= 1;
                btnPrev.classList.toggle('is-disabled', wsCurrentPage <= 1);
            }
            if (btnNext) {
                btnNext.disabled = wsCurrentPage >= totalPages;
                btnNext.classList.toggle('is-disabled', wsCurrentPage >= totalPages);
            }

            if (pagPages) {
                let html = '';
                const windowSize = 2;
                const startP = Math.max(1, wsCurrentPage - windowSize);
                const endP = Math.min(totalPages, wsCurrentPage + windowSize);

                if (startP > 1) {
                    html += `<button type="button" class="pagination-page-btn" onclick="changeWsPage(1)">1</button>`;
                    if (startP > 2) html += `<span class="pagination-ellipsis">&hellip;</span>`;
                }
                for (let p = startP; p <= endP; p++) {
                    const activeCls = (p === wsCurrentPage) ? 'is-active' : '';
                    html += `<button type="button" class="pagination-page-btn ${activeCls}" onclick="changeWsPage(${p})">${p}</button>`;
                }
                if (endP < totalPages) {
                    if (endP < totalPages - 1) html += `<span class="pagination-ellipsis">&hellip;</span>`;
                    html += `<button type="button" class="pagination-page-btn" onclick="changeWsPage(${totalPages})">${totalPages}</button>`;
                }
                pagPages.innerHTML = html;
            }
        } else {
            pagBar.style.display = 'none';
        }
    }
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

applyWorkspaceFilters();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
