<?php
$pageTitle = 'Archives';
$activeNav = 'archives';
$extraCss  = ['explorer.css'];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role('super admin', 'admin');

$pdo = db();
$currentUser = current_user();
$isSuperAdmin = is_super_admin($currentUser);

$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

require_once __DIR__ . '/../includes/archive_actions.php';

// 1. Capture request parameters
$folderId = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode = isset($_GET['mode']) && in_array($_GET['mode'], ['project', 'document'], true) ? $_GET['mode'] : 'project';
$typeFilt = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ  = isset($_GET['q']) ? trim($_GET['q']) : '';

// Resolve Breadcrumbs & Current Folder if in project mode inside an archived folder
$breadcrumbs = [];
$currentFolder = null;

if ($viewMode === 'project' && $folderId !== null) {
    $breadcrumbs = get_folder_breadcrumbs($pdo, $folderId, false);
    $currentFolder = !empty($breadcrumbs) ? end($breadcrumbs) : null;
    if (!$currentFolder) {
        $folderId = null;
    }
}

// Pagination parameters
$page            = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$allowedPerPages = [10, 25, 50, 100];
$perPage         = isset($_GET['per_page']) && is_numeric($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPages, true) ? (int)$_GET['per_page'] : 25;

// Fetch distinct file types among obsolete documents dynamically from database records
$fileTypesStmt = $pdo->query("
    SELECT DISTINCT LOWER(TRIM(LEADING '.' FROM file_extension)) AS ext
    FROM dms_nodes
    WHERE node_type = 'document'
    AND (status = 'obsolete' OR is_deleted = 1)
    AND file_extension IS NOT NULL
    AND file_extension != ''
    ORDER BY ext ASC
");
$allFilterTypes = array_values(array_filter($fileTypesStmt->fetchAll(PDO::FETCH_COLUMN)));

// 2. Query Obsolete Items (According to Project Tree Mode or Flat Documents Mode)
$baseSelect = 'SELECT dms_nodes.*, 
                    COALESCE(u.full_name, u.username, "System") AS creator_name,
                    u.avatar AS creator_avatar,
                    p.name AS parent_name
                FROM dms_nodes
                LEFT JOIN users u ON dms_nodes.created_by = u.id
                LEFT JOIN dms_nodes p ON dms_nodes.parent_id = p.id';

$baseCount = 'SELECT COUNT(*) FROM dms_nodes';

// Obsolete condition: status = "obsolete" OR is_deleted = 1
$whereClause = ' WHERE (dms_nodes.status = "obsolete" OR dms_nodes.is_deleted = 1)';
$params = [];
$countParams = [];

if ($searchQ !== '') {
    // Global search across archives
    $whereClause .= ' AND dms_nodes.name LIKE :q';
    $params['q'] = '%' . $searchQ . '%';
    $countParams['q'] = '%' . $searchQ . '%';

    if ($viewMode === 'document') {
        $whereClause .= ' AND dms_nodes.node_type = "document"';
    }

    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.updated_at DESC, dms_nodes.name ASC';
} elseif ($viewMode === 'document') {
    // Flat list of ALL archived documents
    $whereClause .= ' AND dms_nodes.node_type = "document"';
    $orderBy = ' ORDER BY dms_nodes.updated_at DESC, dms_nodes.name ASC';
} elseif ($folderId !== null) {
    // Inside a specific archived folder
    $whereClause .= ' AND dms_nodes.parent_id = :parent_id';
    $params['parent_id'] = $folderId;
    $countParams['parent_id'] = $folderId;
    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.updated_at DESC, dms_nodes.name ASC';
} else {
    // Root level view of archives (Project mode):
    // Show root items OR items whose parent is not obsolete (so they surface at archives root)
    $whereClause .= ' AND (dms_nodes.parent_id IS NULL OR dms_nodes.parent_id NOT IN (
        SELECT id FROM dms_nodes WHERE node_type = "folder" AND (status = "obsolete" OR is_deleted = 1)
    ))';
    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.updated_at DESC, dms_nodes.name ASC';
}

if ($typeFilt !== null) {
    $cleanExt = ltrim(strtolower($typeFilt), '.');
    $whereClause .= ' AND (LOWER(TRIM(LEADING "." FROM dms_nodes.file_extension)) = :file_ext)';
    $params['file_ext'] = $cleanExt;
    $countParams['file_ext'] = $cleanExt;
}

// 3. Count & Pagination Math
$countStmt = $pdo->prepare($baseCount . $whereClause);
$countStmt->execute($countParams);
$totalCount = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = $baseSelect . $whereClause . $orderBy . ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to render cards HTML (identical markup and pagination to explorer.php)
function render_archives_card_list($items, $viewMode, $searchQ, $folderId = null, $currentFolder = null, $breadcrumbs = [], $page = 1, $perPage = 25, $totalCount = 0, $totalPages = 1): void {
    ?>
    <!-- Permanent Top File Directory Navigation Bar (Blended Background) -->
    <div class="explorer-directory-bar">
        <?php if ($viewMode === 'project' && $folderId !== null && $currentFolder): ?>
            <?php
            $backUrl = $currentFolder['parent_id'] !== null ? '?mode=project&folder=' . $currentFolder['parent_id'] : '?mode=project';
            ?>
            <a href="<?php echo e($backUrl); ?>" class="dir-nav-btn back-btn" title="Go up to parent directory">
                <?php echo render_icon('arrow-left'); ?>
            </a>
        <?php else: ?>
            <span class="dir-nav-btn root-btn" title="Archives Repository" style="color:var(--amber);">
                <?php echo render_icon($viewMode === 'document' ? 'files' : 'archive'); ?>
            </span>
        <?php endif; ?>

        <!-- Breadcrumbs Navigation -->
        <nav class="explorer-breadcrumbs" aria-label="Archive Breadcrumbs">
            <?php if ($viewMode === 'document'): ?>
                <span class="breadcrumb-item <?php echo $searchQ === '' ? 'current' : ''; ?>">
                    <span>All Archived Documents</span>
                </span>
            <?php else: ?>
                <a href="?mode=project" class="breadcrumb-item root-link <?php echo ($folderId === null && $searchQ === '') ? 'current' : ''; ?>">
                    <span>Archives</span>
                </a>
                <?php foreach ($breadcrumbs as $idx => $crumb): ?>
                    <span class="breadcrumb-sep"><?php echo render_icon('chevron-right'); ?></span>
                    <?php if ($idx === count($breadcrumbs) - 1 && $searchQ === ''): ?>
                        <span class="breadcrumb-item current"><?php echo e($crumb['name']); ?></span>
                    <?php else: ?>
                        <a href="?mode=project&folder=<?php echo e((string)$crumb['id']); ?>" class="breadcrumb-item">
                            <?php echo e($crumb['name']); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($searchQ !== ''): ?>
                <span class="breadcrumb-sep"><?php echo render_icon('chevron-right'); ?></span>
                <span class="breadcrumb-item current">
                    <span class="search-crumb-label">Search: "<?php echo e($searchQ); ?>"</span>
                </span>
            <?php endif; ?>
        </nav>
    </div>

    <?php if (empty($items)): ?>
        <!-- Empty State Card -->
        <div class="explorer-empty-card">
            <div class="empty-icon" style="color:var(--amber);"><?php echo render_icon('archive'); ?></div>
            <h3 class="empty-title"><?php echo $folderId !== null ? 'This archived folder is empty' : 'No archived items found'; ?></h3>
            <p class="empty-subtitle">
                <?php echo $searchQ !== '' ? 'No obsolete documents or folders matched your query.' : ($folderId !== null ? 'There are no items inside this archived folder.' : 'There are currently no obsolete or archived documents in the repository.'); ?>
            </p>
        </div>
    <?php else: ?>
        <!-- Google Drive-Style Table Header -->
        <div class="explorer-table-header">
            <div class="col-hdr-checkbox-icon"></div>
            <div class="col-hdr-name">Name</div>
            <div class="col-hdr-owner">Owner</div>
            <div class="col-hdr-modified">Date Archived</div>
            <div class="col-hdr-location">Original Location</div>
            <div class="col-hdr-status">Status</div>
            <div class="col-hdr-actions"></div>
        </div>

        <?php foreach ($items as $item): ?>
            <?php
            $isFolder = ($item['node_type'] === 'folder');
            $itemLink = $isFolder ? 'archives.php?folder=' . $item['id'] : 'view.php?id=' . $item['id'];
            $extIcon = $isFolder ? 'folder' : ($item['file_extension'] ?? 'files');
            $archivedDate = $item['updated_at'] ?? $item['created_at'];
            ?>
            <div class="explorer-item-card <?php echo $isFolder ? 'is-folder' : 'is-document'; ?>" id="node-<?php echo e((string)$item['id']); ?>" data-id="<?php echo e((string)$item['id']); ?>" data-name="<?php echo e($item['name']); ?>" data-type="<?php echo $isFolder ? 'folder' : 'document'; ?>" data-location="<?php echo e($item['parent_name'] ?: 'Projects'); ?>" data-ext="<?php echo e($extIcon); ?>">
                <!-- Selection Checkbox -->
                <div class="item-checkbox-col">
                    <input type="checkbox" class="item-checkbox" value="<?php echo e((string)$item['id']); ?>" aria-label="Select item">
                </div>

                <!-- Type Icon -->
                <?php if ($isFolder): ?>
                    <a href="<?php echo e($itemLink); ?>" class="item-icon-col icon-folder" title="Open Archived Folder: <?php echo e($item['name']); ?>">
                        <?php echo render_icon('folder'); ?>
                    </a>
                <?php else: ?>
                    <a href="<?php echo e($itemLink); ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open Workspace: <?php echo e($item['name']); ?>">
                        <?php echo render_icon($extIcon); ?>
                    </a>
                <?php endif; ?>

                <!-- 1. Name Column -->
                <div class="item-main-col">
                    <div class="item-title-row">
                        <?php if ($isFolder): ?>
                            <a href="<?php echo e($itemLink); ?>" class="item-title" title="<?php echo e($item['name']); ?>">
                                <?php echo e($item['name']); ?>
                            </a>
                            <span class="item-type-badge">Folder</span>
                        <?php else: ?>
                            <a href="<?php echo e($itemLink); ?>" class="item-title" title="<?php echo e($item['name']); ?>">
                                <?php echo e($item['name']); ?>
                            </a>
                            <?php if (!empty($item['file_extension'])): ?>
                                <span class="item-type-badge">.<?php echo e(ltrim(strtolower($item['file_extension']), '.')); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['version'])): ?>
                                <span class="version-badge"><?php echo e($item['version']); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Owner Column -->
                <div class="item-owner-col" title="Owner: <?php echo e($item['creator_name']); ?>">
                    <?php echo render_user_avatar_sm($item['creator_name'], $item['creator_avatar'] ?? null); ?>
                    <span class="owner-name"><?php echo e($item['creator_name']); ?></span>
                </div>

                <!-- 3. Date Archived Column -->
                <div class="item-modified-col" title="Archived on: <?php echo e($archivedDate); ?>">
                    <span><?php echo date('M j, Y', strtotime($archivedDate)); ?></span>
                </div>

                <!-- 4. Located In Column -->
                <div class="item-location-col">
                    <?php if ($item['parent_id'] !== null && !empty($item['parent_name'])): ?>
                        <span class="location-badge" title="Origin: <?php echo e($item['parent_name']); ?>">
                            <?php echo render_icon('folder'); ?>
                            <span class="loc-name"><?php echo e($item['parent_name']); ?></span>
                        </span>
                    <?php else: ?>
                        <span class="location-badge" title="Origin: Projects">
                            <?php echo render_icon('folder'); ?>
                            <span class="loc-name">Projects</span>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- 5. Status Badge -->
                <div class="item-status-col">
                    <span class="status-badge badge-obsolete">Obsolete</span>
                </div>

                <!-- 6. Quick Action Tools (View, Download, 3-Dots Context Menu) -->
                <div class="item-actions-col">
                    <?php if (!$isFolder): ?>
                        <a href="view.php?id=<?php echo e((string)$item['id']); ?>" class="btn-action" title="Open Workspace">
                            <?php echo render_icon('view'); ?>
                        </a>
                        <a href="download.php?id=<?php echo e((string)$item['id']); ?>" class="btn-action" title="Download: <?php echo e($item['file_original_name'] ?: $item['name']); ?>" download>
                            <?php echo render_icon('download'); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn-action btn-archive-actions" title="More Actions" 
                            data-id="<?php echo e((string)$item['id']); ?>" 
                            data-name="<?php echo e($item['name']); ?>" 
                            data-type="<?php echo e($item['node_type']); ?>" 
                            data-ext="<?php echo e($extIcon); ?>" 
                            data-version="<?php echo e($item['version'] ?? 'v1.0'); ?>" 
                            data-location="<?php echo e($item['parent_name'] ?: 'Projects'); ?>">
                        <?php echo render_icon('more-v'); ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Pagination Controls Footer -->
        <?php render_pagination_bar($page, $perPage, $totalCount, $totalPages, 'archivesPerPageSelect', 'handleArchivesPerPage(this.value)', 'navigateArchivesPage'); ?>
    <?php endif;
}

// Handle AJAX partial reload
if ($isAjax) {
    render_archives_card_list($items, $viewMode, $searchQ, $folderId, $currentFolder, $breadcrumbs, $page, $perPage, $totalCount, $totalPages);
    exit;
}

// Full page load
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/../includes/flash.php'; ?>

<div class="explorer-wrapper">

    <!-- Top Action & Search Bar -->
    <div class="explorer-toolbar-card">
        <form method="GET" action="" class="explorer-search-form" id="archivesSearchForm">
            <input type="hidden" name="mode" value="<?php echo e($viewMode); ?>" id="archivesSearchMode">
            <?php if ($folderId !== null && $viewMode === 'project'): ?>
                <input type="hidden" name="folder" value="<?php echo (int)$folderId; ?>" id="archivesCurrentFolderId">
            <?php endif; ?>
            <?php if ($typeFilt !== null): ?>
                <input type="hidden" name="type" value="<?php echo e($typeFilt); ?>">
            <?php endif; ?>

            <div class="explorer-search-box">
                <span class="search-icon"><?php echo render_icon('search'); ?></span>
                <input type="text" name="q" id="archivesSearchInput" value="<?php echo e($searchQ); ?>" placeholder="Search archived documents or folders..." class="search-input" autocomplete="off" oninput="debounceArchivesSearch()">
                <a href="#" id="archivesSearchClear" class="search-clear" title="Clear search" style="<?php echo $searchQ === '' ? 'display: none;' : ''; ?>" onclick="clearArchivesSearch(event)">&times;</a>
            </div>
        </form>

    </div>

    <!-- Filter & Navigation Card: Projects vs Documents Mode + Filters -->
    <div class="explorer-filter-card">
        
        <div class="explorer-nav-left">
            <!-- Main View Switcher (Projects vs Documents) Identical to explorer.php -->
            <div class="pill-tabs" id="archivesPillTabs">
                <a href="?mode=project<?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" data-mode="project" class="pill-tab <?php echo $viewMode === 'project' ? 'active' : ''; ?>" onclick="handleArchivesModeClick(event, 'project')">
                    <?php echo render_icon('folder'); ?>
                    <span>Projects</span>
                </a>
                <a href="?mode=document<?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" data-mode="document" class="pill-tab <?php echo $viewMode === 'document' ? 'active' : ''; ?>" onclick="handleArchivesModeClick(event, 'document')">
                    <?php echo render_icon('files'); ?>
                    <span>Documents</span>
                </a>
            </div>
        </div>

        <!-- Filter Controls: File Type Dropdown -->
        <div class="explorer-filters-right">
            <form method="GET" action="" class="filter-dropdown-form" id="archivesFilterForm">
                <input type="hidden" name="mode" id="archivesFilterMode" value="<?php echo e($viewMode); ?>">
                <input type="hidden" name="folder" id="archivesFilterFolder" value="<?php echo ($folderId !== null && $viewMode === 'project') ? e((string)$folderId) : ''; ?>">

                <div class="filter-dropdown-group">
                    <div class="select-wrapper">
                        <select name="type" id="archivesFilterType" class="filter-select" onchange="handleArchivesTypeFilter(this.value)">
                            <option value="">All File Types</option>
                            <?php foreach ($allFilterTypes as $fExt): ?>
                                <?php $cleanExt = ltrim(strtolower($fExt), '.'); ?>
                                <option value="<?php echo e($cleanExt); ?>" <?php echo (ltrim(strtolower($typeFilt ?? ''), '.') === $cleanExt) ? 'selected' : ''; ?>>
                                    .<?php echo e($cleanExt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>

    </div>

    <!-- Main Card-by-Card Document List Container -->
    <div class="explorer-card-list" id="archivesCardList">
        <?php render_archives_card_list($items, $viewMode, $searchQ, $folderId, $currentFolder, $breadcrumbs, $page, $perPage, $totalCount, $totalPages); ?>
    </div>

</div>

<!-- Floating Action Popover Menu (3-Dots Context Menu for Archives) -->
<div class="edms-action-popover" id="archiveActionPopover" style="display: none;">
    <div class="popover-header-label" id="archivePopoverHeaderLabel" style="display: none;">1 item selected</div>
    <?php if (can_edit_dms()): ?>
    <button type="button" class="popover-item is-restore" id="popoverArchiveRestore">
        <?php echo render_icon('undo'); ?>
        <span id="popoverArchiveRestoreLabel">Restore to Workspace</span>
    </button>
    <?php endif; ?>
    <?php if ($isSuperAdmin): ?>
        <div class="popover-divider"></div>
        <button type="button" class="popover-item is-danger" id="popoverArchivePurge">
            <?php echo render_icon('trash'); ?>
            <span id="popoverArchivePurgeLabel">Permanently Delete</span>
        </button>
    <?php endif; ?>
</div>

<!-- Modal: Restore Confirmation (Identical Modal Style to explorer.php) -->
<div class="edms-modal-backdrop" id="modalRestoreDoc">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title" style="color:var(--green);">
                <?php echo render_icon('refresh'); ?>
                <span>Restore to Workspace</span>
            </div>
            <button type="button" class="btn-modal-close" onclick="closeRestoreModal()">&times;</button>
        </div>
        <form method="POST" action="archives.php" id="formRestoreDoc">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="restoreDocAction" value="restore_node">
            <input type="hidden" name="id" id="restoreDocId" value="">
            <input type="hidden" name="ids" id="restoreDocIds" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Restore <strong id="restoreDocNameDisplay"></strong> to the active workspace?
                </p>
                <p class="form-help" style="color: var(--muted); line-height: 1.5; font-size:12.5px; margin:0;">
                    Status will transition from <strong>OBSOLETE</strong> to <strong>ACTIVE</strong>, returning items to the active project tree in their original locations.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRestore">
                    <?php echo render_icon('refresh'); ?>
                    <span id="btnSubmitRestoreText">Confirm Restore</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- Modal: Permanently Delete Confirmation (Identical Modal Style to explorer.php) -->
<div class="edms-modal-backdrop" id="modalPurgeDoc">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title" style="color:var(--red);">
                <?php echo render_icon('trash'); ?>
                <span>Permanently Delete</span>
            </div>
            <button type="button" class="btn-modal-close" onclick="closePurgeModal()">&times;</button>
        </div>
        <form method="POST" action="archives.php" id="formPurgeDoc">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="purgeDocAction" value="purge_node">
            <input type="hidden" name="id" id="purgeDocId" value="">
            <input type="hidden" name="ids" id="purgeDocIds" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Permanently delete <strong id="purgeDocNameDisplay"></strong>?
                </p>
                <p class="form-help" style="color: var(--red-ink); background: var(--red-soft); padding: 8px 12px; border-radius: 6px; font-size: 12px; margin:0; line-height: 1.5;">
                    <strong>Caution:</strong> This operation completely purges the document binary, annotations, and database records. If this is a folder, all enclosed contents will also be removed. This cannot be undone.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePurgeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:var(--red); border-color:var(--red);" id="btnSubmitPurge">
                    <?php echo render_icon('trash'); ?>
                    <span id="btnSubmitPurgeText">Delete Permanently</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/archives.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/archives.js'); ?>" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
