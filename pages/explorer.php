<?php
$pageTitle = 'Document Explorer';
$activeNav = 'explorer';
$extraCss  = ['explorer.css'];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = db();
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Dedicated Action Controller decoupled to includes/explorer_actions.php
require_once __DIR__ . '/../includes/explorer_actions.php';


// 1. Capture request parameters
$parentId   = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode   = isset($_GET['mode']) && in_array($_GET['mode'], ['project', 'document'], true) ? $_GET['mode'] : 'project';
$statusFilt = isset($_GET['status']) && in_array($_GET['status'], ['active', 'hold', 'suspend', 'published', 'obsolete'], true) ? $_GET['status'] : null;
$typeFilt   = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ    = isset($_GET['q']) ? trim($_GET['q']) : '';
$sortBy     = isset($_GET['sort']) && in_array($_GET['sort'], ['name', 'updated_at'], true) ? $_GET['sort'] : 'name';
$sortDir    = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'DESC' : 'ASC';

// Pagination parameters
$page       = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$allowedPerPages = [10, 25, 50, 100];
$perPage    = isset($_GET['per_page']) && is_numeric($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPages, true) ? (int)$_GET['per_page'] : 25;

// Fetch distinct file types (extensions) dynamically from existing database records
$fileTypesStmt = $pdo->query("
    SELECT DISTINCT LOWER(TRIM(LEADING '.' FROM file_extension)) AS ext
    FROM dms_nodes
    WHERE node_type = 'document'
      AND file_extension IS NOT NULL
      AND file_extension != ''
      AND is_deleted = 0
      AND status != 'obsolete'
    ORDER BY ext ASC
");
$allFilterTypes = array_values(array_filter($fileTypesStmt->fetchAll(PDO::FETCH_COLUMN)));

// 2. Resolve Breadcrumbs (Project tree mode only)
$breadcrumbs = [];
$currentNode = null;

if ($viewMode === 'project' && $parentId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM dms_nodes WHERE id = :id AND is_deleted = 0');
    $stmt->execute(['id' => $parentId]);
    $currentNode = $stmt->fetch();

    if ($currentNode) {
        $temp = $currentNode;
        while ($temp) {
            array_unshift($breadcrumbs, $temp);
            if ($temp['parent_id'] !== null) {
                $pStmt = $pdo->prepare('SELECT * FROM dms_nodes WHERE id = :id AND is_deleted = 0');
                $pStmt->execute(['id' => $temp['parent_id']]);
                $temp = $pStmt->fetch();
            } else {
                $temp = null;
            }
        }
    }
}

// 3. Query items according to Mode with Creator, Location joins, and Pagination
$items = [];
$totalCount = 0;

$baseSelect = 'SELECT dms_nodes.*, 
                      COALESCE(u.full_name, u.username, "System") AS creator_name,
                      u.avatar AS creator_avatar,
                      p.name AS parent_name
               FROM dms_nodes
               LEFT JOIN users u ON dms_nodes.created_by = u.id
               LEFT JOIN dms_nodes p ON dms_nodes.parent_id = p.id';

$baseCount = 'SELECT COUNT(*) FROM dms_nodes';

$whereClause = '';
$params = [];
$countParams = [];

if ($searchQ !== '') {
    // Global search mode
    $whereClause = ' WHERE dms_nodes.is_deleted = 0 AND dms_nodes.status != "obsolete" AND dms_nodes.name LIKE :q';
    $params['q'] = '%' . $searchQ . '%';
    $countParams['q'] = '%' . $searchQ . '%';

    if ($viewMode === 'document') {
        $whereClause .= ' AND dms_nodes.node_type = "document"';
    }

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $cleanExt = ltrim(strtolower($typeFilt), '.');
        $whereClause .= ' AND (LOWER(TRIM(LEADING "." FROM dms_nodes.file_extension)) = :file_ext)';
        $params['file_ext'] = $cleanExt;
        $countParams['file_ext'] = $cleanExt;
    }
} elseif ($viewMode === 'document') {
    // Flat All Documents View
    $whereClause = ' WHERE dms_nodes.node_type = "document" AND dms_nodes.is_deleted = 0 AND dms_nodes.status != "obsolete"';

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $cleanExt = ltrim(strtolower($typeFilt), '.');
        $whereClause .= ' AND (LOWER(TRIM(LEADING "." FROM dms_nodes.file_extension)) = :file_ext)';
        $params['file_ext'] = $cleanExt;
        $countParams['file_ext'] = $cleanExt;
    }
} elseif ($parentId === null) {
    // Root level view (ALL Root Folders and Root Documents)
    $whereClause = ' WHERE dms_nodes.parent_id IS NULL AND dms_nodes.is_deleted = 0 AND dms_nodes.status != "obsolete"';

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $cleanExt = ltrim(strtolower($typeFilt), '.');
        $whereClause .= ' AND (LOWER(TRIM(LEADING "." FROM dms_nodes.file_extension)) = :file_ext)';
        $params['file_ext'] = $cleanExt;
        $countParams['file_ext'] = $cleanExt;
    }
} else {
    // Inside a specific folder
    $whereClause = ' WHERE dms_nodes.parent_id = :parent_id AND dms_nodes.is_deleted = 0 AND dms_nodes.status != "obsolete"';
    $params['parent_id'] = $parentId;
    $countParams['parent_id'] = $parentId;

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $cleanExt = ltrim(strtolower($typeFilt), '.');
        $whereClause .= ' AND (LOWER(TRIM(LEADING "." FROM dms_nodes.file_extension)) = :file_ext)';
        $params['file_ext'] = $cleanExt;
        $countParams['file_ext'] = $cleanExt;
    }
}

// Build unified deterministic ORDER BY clause
$folderOrder = ($viewMode === 'project' || $searchQ !== '') ? 'dms_nodes.node_type = "folder" DESC, ' : '';
$sortColumn = ($sortBy === 'updated_at') ? 'dms_nodes.updated_at' : 'dms_nodes.name';
$secondaryOrder = ($sortBy === 'updated_at') ? ', dms_nodes.name ASC' : '';
$orderBy = " ORDER BY {$folderOrder}{$sortColumn} {$sortDir}{$secondaryOrder}";

// 1. Calculate Total Count
$countStmt = $pdo->prepare($baseCount . $whereClause);
$countStmt->execute($countParams);
$totalCount = (int)$countStmt->fetchColumn();

// 2. Calculate Pagination Math
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// 3. Query Paginated Items
$sql = $baseSelect . $whereClause . $orderBy . ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

// Helper badge color
function get_status_class(string $status): string {
    return match ($status) {
        'published' => 'badge-publish',
        'active'    => 'badge-active',
        'hold'      => 'badge-hold',
        'suspend'   => 'badge-suspend',
        'rejected'  => 'badge-rejected',
        'obsolete'  => 'badge-obsolete',
        default     => 'badge-default',
    };
}

// Helper function to render cards HTML (shared between normal load and AJAX)
function render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page = 1, $perPage = 25, $totalCount = 0, $totalPages = 1, $sortBy = 'name', $sortDir = 'ASC'): void {
    ?>
    <!-- Permanent Top File Directory Navigation Bar (Blended Background) -->
    <div class="explorer-directory-bar">
        <?php if ($viewMode === 'project' && $parentId !== null && $currentNode): ?>
            <?php
            $backUrl = $currentNode['parent_id'] !== null ? '?mode=project&folder=' . $currentNode['parent_id'] : '?mode=project';
            ?>
            <a href="<?php echo e($backUrl); ?>" class="dir-nav-btn back-btn" title="Go up to parent directory">
                <?php echo render_icon('arrow-left'); ?>
            </a>
        <?php else: ?>
            <span class="dir-nav-btn root-btn" title="Current location">
                <?php echo render_icon($viewMode === 'document' ? 'files' : 'folder'); ?>
            </span>
        <?php endif; ?>

        <!-- Breadcrumbs Navigation -->
        <nav class="explorer-breadcrumbs" aria-label="Directory Breadcrumbs">
            <?php if ($viewMode === 'document'): ?>
                <span class="breadcrumb-item <?php echo $searchQ === '' ? 'current' : ''; ?>">
                    <span>All Documents</span>
                </span>
            <?php else: ?>
                <a href="?mode=project" class="breadcrumb-item root-link <?php echo ($parentId === null && $searchQ === '') ? 'current' : ''; ?>">
                    <span>Projects</span>
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
            <div class="empty-icon"><?php echo render_icon('files'); ?></div>
            <h3 class="empty-title">No items found</h3>
            <p class="empty-subtitle">
                <?php echo $searchQ !== '' ? 'No documents or folders matched your query.' : 'No documents found matching the selected filters. Upload a document or adjust filters.'; ?>
            </p>
        </div>
    <?php else: ?>
        <?php
        $isDateActive = ($sortBy === 'updated_at');
        if (!$isDateActive) {
            // State 0 (Default): First click -> State 1 (DESC / Newest first)
            $dateSortNextTarget = 'updated_at';
            $dateSortNextOrder = 'desc';
            $dateSortTitle = 'Sort by Date Modified (Newest first)';
        } elseif ($sortDir === 'DESC') {
            // State 1 (DESC): Second click -> State 2 (ASC / Oldest first)
            $dateSortNextTarget = 'updated_at';
            $dateSortNextOrder = 'asc';
            $dateSortTitle = 'Sort by Date Modified (Oldest first)';
        } else {
            // State 2 (ASC): Third click -> State 0 (Default / Back to beginning)
            $dateSortNextTarget = 'default';
            $dateSortNextOrder = 'asc';
            $dateSortTitle = 'Clear date sort (Return to default)';
        }

        $isNameActive = ($sortBy === 'name');
        $nameSortNextOrder = ($isNameActive && $sortDir === 'ASC') ? 'desc' : 'asc';
        $nameSortTitle = ($isNameActive && $sortDir === 'ASC') ? 'Sort by Name (Z to A)' : 'Sort by Name (A to Z)';
        ?>
        <!-- Google Drive-Style Table Header -->
        <div class="explorer-table-header">
            <div class="col-hdr-checkbox-icon"></div>
            <div class="col-hdr-name sortable-col <?php echo ($isNameActive && ($sortDir === 'DESC' || !$isDateActive)) ? 'active-sort' : ''; ?>" data-sort="name" data-order="<?php echo $nameSortNextOrder; ?>" role="button" tabindex="0" title="<?php echo e($nameSortTitle); ?>">
                <span>Name</span>
                <span class="sort-indicator">
                    <?php if ($isNameActive && ($sortDir === 'DESC' || !$isDateActive)): ?>
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="<?php echo $sortDir === 'DESC' ? '6 9 12 15 18 9' : '18 15 12 9 6 15'; ?>"></polyline></svg>
                    <?php endif; ?>
                </span>
            </div>
            <div class="col-hdr-owner">Owner</div>
            <div class="col-hdr-modified sortable-col <?php echo $isDateActive ? 'active-sort' : ''; ?>" data-sort="<?php echo $dateSortNextTarget; ?>" data-order="<?php echo $dateSortNextOrder; ?>" role="button" tabindex="0" title="<?php echo e($dateSortTitle); ?>">
                <span>Date Modified</span>
                <span class="sort-indicator">
                    <?php if ($isDateActive): ?>
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="<?php echo $sortDir === 'ASC' ? '18 15 12 9 6 15' : '6 9 12 15 18 9'; ?>"></polyline></svg>
                    <?php endif; ?>
                </span>
            </div>
            <div class="col-hdr-location">Location</div>
            <div class="col-hdr-status">Status</div>
            <div class="col-hdr-actions"></div>
        </div>

        <?php foreach ($items as $item): ?>
            <?php
            $isFolder = ($item['node_type'] === 'folder');
            $itemLink = $isFolder ? '?mode=project&folder=' . $item['id'] : '#doc-' . $item['id'];
            $extIcon  = $isFolder ? 'folder' : ($item['file_extension'] ?? 'files');
            ?>
            <div class="explorer-item-card <?php echo $isFolder ? 'is-folder' : 'is-document'; ?>" id="node-<?php echo e((string)$item['id']); ?>" data-id="<?php echo e((string)$item['id']); ?>" data-name="<?php echo e($item['name']); ?>" data-type="<?php echo $isFolder ? 'folder' : 'document'; ?>" data-location="<?php echo e($item['parent_name'] ?: 'Root'); ?>">
                
                <!-- Selection Checkbox -->
                <div class="item-checkbox-col">
                    <input type="checkbox" class="item-checkbox" value="<?php echo e((string)$item['id']); ?>" aria-label="Select item">
                </div>

                <!-- Type Icon -->
                <?php if ($isFolder): ?>
                    <a href="<?php echo e($itemLink); ?>" class="item-icon-col icon-folder">
                        <?php echo render_icon('folder'); ?>
                    </a>
                <?php else: ?>
                    <a href="view.php?id=<?php echo e((string)$item['id']); ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>" title="Open Workspace: <?php echo e($item['name']); ?>">
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
                        <?php else: ?>
                            <a href="view.php?id=<?php echo e((string)$item['id']); ?>" class="item-title" title="<?php echo e($item['name']); ?>">
                                <?php echo e($item['name']); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!$isFolder && !empty($item['version'])): ?>
                            <span class="version-badge"><?php echo e($item['version']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Owner / Created By Column -->
                <div class="item-owner-col" title="Created by <?php echo e($item['creator_name']); ?>">
                    <?php echo render_user_avatar_sm($item['creator_name'], $item['creator_avatar'] ?? null); ?>
                    <span class="owner-name"><?php echo e($item['creator_name']); ?></span>
                </div>

                <!-- 3. Date Modified Column -->
                <div class="item-modified-col" title="Modified: <?php echo e($item['updated_at'] ?? $item['created_at']); ?>">
                    <span><?php echo date('M j, Y', strtotime($item['updated_at'] ?? $item['created_at'])); ?></span>
                </div>

                <!-- 4. Located In Column -->
                <div class="item-location-col">
                    <?php if ($item['parent_id'] !== null && !empty($item['parent_name'])): ?>
                        <a href="?mode=project&folder=<?php echo e((string)$item['parent_id']); ?>" class="location-badge" title="Located in: <?php echo e($item['parent_name']); ?>">
                            <?php echo render_icon('folder'); ?>
                            <span class="loc-name"><?php echo e($item['parent_name']); ?></span>
                        </a>
                    <?php else: ?>
                        <span class="location-badge" title="Located at Projects">
                            <?php echo render_icon('folder'); ?>
                            <span class="loc-name">Projects</span>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- 5. Status Badge -->
                <div class="item-status-col">
                    <?php if (!$isFolder): ?>
                        <span class="status-badge <?php echo get_status_class($item['status']); ?>">
                            <?php echo ucfirst(e($item['status'])); ?>
                        </span>
                    <?php else: ?>
                        <span class="folder-type-label">Folder</span>
                    <?php endif; ?>
                </div>

                <!-- 6. Quick Action Tools -->
                <div class="item-actions-col">
                    <?php if (!$isFolder): ?>
                        <a href="view.php?id=<?php echo e((string)$item['id']); ?>" class="btn-action" title="Open Workspace">
                            <?php echo render_icon('view'); ?>
                        </a>
                        <a href="download.php?id=<?php echo e((string)$item['id']); ?>" class="btn-action" title="Download: <?php echo e($item['file_original_name'] ?: $item['name']); ?>" download>
                            <?php echo render_icon('download'); ?>
                        </a>
                        <button type="button" class="btn-action btn-more-actions" title="More Actions" data-id="<?php echo e((string)$item['id']); ?>" data-name="<?php echo e($item['name']); ?>" data-type="document" data-ext="<?php echo e($extIcon); ?>" data-version="<?php echo e($item['version'] ?? 'v1.0'); ?>" data-location="<?php echo e($item['parent_name'] ?: 'Root'); ?>">
                            <?php echo render_icon('more-v'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-action btn-more-actions" title="Folder Actions" data-id="<?php echo e((string)$item['id']); ?>" data-name="<?php echo e($item['name']); ?>" data-type="folder" data-location="<?php echo e($item['parent_name'] ?: 'Root'); ?>">
                            <?php echo render_icon('more-v'); ?>
                        </button>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>

        <!-- Pagination Controls Footer -->
        <?php
        $startItem = ($page - 1) * $perPage + 1;
        $endItem = min($totalCount, $page * $perPage);
        ?>
        <div class="explorer-pagination-bar">
            <div class="pagination-info">
                <span>Showing <strong><?php echo $startItem; ?>–<?php echo $endItem; ?></strong> of <strong><?php echo number_format($totalCount); ?></strong> items</span>
            </div>

            <div class="pagination-actions">
                <!-- Page size selector -->
                <div class="pagination-per-page">
                    <label for="paginationPerPageSelect" class="pagination-label">Rows per page:</label>
                    <select id="paginationPerPageSelect" class="pagination-select" aria-label="Items per page">
                        <?php foreach ([10, 25, 50, 100] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Navigation buttons -->
                <div class="pagination-nav-group">
                    <button type="button" class="pagination-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" data-page="<?php echo max(1, $page - 1); ?>" title="Previous Page" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                        <?php echo render_icon('arrow-left'); ?>
                    </button>

                    <!-- Page Numbers -->
                    <div class="pagination-pages">
                        <?php
                        $window = 2; // Pages to display on each side of current
                        $startPage = max(1, $page - $window);
                        $endPage = min($totalPages, $page + $window);

                        if ($startPage > 1): ?>
                            <button type="button" class="pagination-page-btn" data-page="1">1</button>
                            <?php if ($startPage > 2): ?>
                                <span class="pagination-ellipsis">&hellip;</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <button type="button" class="pagination-page-btn <?php echo $p === $page ? 'is-active' : ''; ?>" data-page="<?php echo $p; ?>">
                                <?php echo $p; ?>
                            </button>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="pagination-ellipsis">&hellip;</span>
                            <?php endif; ?>
                            <button type="button" class="pagination-page-btn" data-page="<?php echo $totalPages; ?>"><?php echo $totalPages; ?></button>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="pagination-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" data-page="<?php echo min($totalPages, $page + 1); ?>" title="Next Page" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                        <?php echo render_icon('chevron-right'); ?>
                    </button>
                </div>
            </div>
        </div>

    <?php endif; ?>
    <?php
}

// If this is an AJAX partial request, render cards and terminate immediately!
if ($isAjax) {
    render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page, $perPage, $totalCount, $totalPages, $sortBy, $sortDir);
    exit;
}

// Normal full page load
require_once __DIR__ . '/../includes/header.php';
?>

<div class="explorer-wrapper">

    <!-- Drag-and-Drop Ingestion Overlay -->
    <div class="explorer-drag-overlay" id="explorerDragOverlay" aria-hidden="true">
        <div class="drag-overlay-card">
            <div class="drag-overlay-icon">
                <?php echo render_icon('cloud-upload'); ?>
            </div>
            <h3 class="drag-overlay-title">Drop documents here to upload</h3>
            <p class="drag-overlay-subtitle">Target: <strong id="dragOverlayTargetLabel"><?php echo ($currentNode && $viewMode === 'project') ? e($currentNode['name']) : 'Projects'; ?></strong></p>
            <span class="drag-overlay-hint">Release mouse to review and confirm upload</span>
        </div>
    </div>

    <!-- Top Action & Search Bar -->
    <div class="explorer-toolbar-card">
        <form method="GET" action="" class="explorer-search-form" id="explorerSearchForm">
            <input type="hidden" name="mode" value="<?php echo e($viewMode); ?>">
            <?php if ($parentId !== null && $viewMode === 'project'): ?>
                <input type="hidden" name="folder" value="<?php echo e((string)$parentId); ?>">
            <?php endif; ?>
            <?php if ($statusFilt !== null): ?>
                <input type="hidden" name="status" value="<?php echo e($statusFilt); ?>">
            <?php endif; ?>
            <?php if ($typeFilt !== null): ?>
                <input type="hidden" name="type" value="<?php echo e($typeFilt); ?>">
            <?php endif; ?>

            <div class="explorer-search-box">
                <span class="search-icon"><?php echo render_icon('search'); ?></span>
                <input type="text" name="q" id="explorerSearchInput" value="<?php echo e($searchQ); ?>" placeholder="Search documents or folders..." class="search-input" autocomplete="off">
                <a href="#" id="explorerSearchClear" class="search-clear" title="Clear search" style="<?php echo $searchQ === '' ? 'display: none;' : ''; ?>">&times;</a>
            </div>
        </form>

        <?php if (can_edit_dms()): ?>
        <div class="toolbar-actions">
            <button type="button" id="btnNewFolder" class="btn btn-secondary" style="<?php echo $viewMode === 'project' ? '' : 'display: none;'; ?>">
                <?php echo render_icon('plus'); ?>
                <span>New Folder</span>
            </button>
            <button type="button" id="btnUploadDoc" class="btn btn-primary">
                <?php echo render_icon('cloud-upload'); ?>
                <span>Upload Document</span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter & Navigation Card: Projects vs Documents Mode + Filters -->
    <div class="explorer-filter-card">
        
        <div class="explorer-nav-left">
            <!-- Main View Switcher (Projects vs Documents) Always Visible -->
            <div class="pill-tabs" id="explorerPillTabs">
                <a href="?mode=project<?php echo $statusFilt ? '&status=' . $statusFilt : ''; ?><?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" data-mode="project" class="pill-tab <?php echo $viewMode === 'project' ? 'active' : ''; ?>">
                    <?php echo render_icon('folder'); ?>
                    <span>Projects</span>
                </a>
                <a href="?mode=document<?php echo $statusFilt ? '&status=' . $statusFilt : ''; ?><?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" data-mode="document" class="pill-tab <?php echo $viewMode === 'document' ? 'active' : ''; ?>">
                    <?php echo render_icon('files'); ?>
                    <span>Documents</span>
                </a>
            </div>
        </div>

        <!-- Filter Controls: Document Type & Status Dropdowns -->
        <div class="explorer-filters-right">
            <form method="GET" action="" class="filter-dropdown-form" id="explorerFilterForm">
                <input type="hidden" name="mode" id="explorerFilterMode" value="<?php echo e($viewMode); ?>">
                <input type="hidden" name="folder" id="explorerFilterFolder" value="<?php echo ($parentId !== null && $viewMode === 'project') ? e((string)$parentId) : ''; ?>">

                <div class="filter-dropdown-group">
                    <!-- File Type Dropdown -->
                    <div class="select-wrapper">
                        <select name="type" id="filterTypeSelect" class="filter-select">
                            <option value="">All File Types</option>
                            <?php foreach ($allFilterTypes as $fExt): ?>
                                <?php $cleanExt = ltrim(strtolower($fExt), '.'); ?>
                                <option value="<?php echo e($cleanExt); ?>" <?php echo (ltrim(strtolower($typeFilt ?? ''), '.') === $cleanExt) ? 'selected' : ''; ?>>
                                    .<?php echo e($cleanExt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Dropdown -->
                    <div class="select-wrapper">
                        <select name="status" id="filterStatusSelect" class="filter-select">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $statusFilt === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="published" <?php echo $statusFilt === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="hold" <?php echo $statusFilt === 'hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="suspend" <?php echo $statusFilt === 'suspend' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>

    </div>

    <!-- Main Card-by-Card Document List Container -->
    <div class="explorer-card-list" id="explorerCardList">
        <?php render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page, $perPage, $totalCount, $totalPages, $sortBy, $sortDir); ?>
    </div>

</div>

<!-- Modal: Upload Document Specimen -->
<div class="edms-modal-backdrop" id="modalUploadDoc">
    <div class="edms-modal-dialog" style="max-width: 520px; width: 95%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <span>Upload Document</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseUploadModal" title="Close modal">&times;</button>
        </div>
        <form id="formUploadDoc" method="POST" action="explorer.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="parent_id" id="uploadParentId" value="<?php echo ($parentId !== null && $viewMode === 'project') ? e((string)$parentId) : ''; ?>">

            <div class="edms-modal-body">
                <!-- Dropzone Area -->
                <div class="upload-dropzone" id="uploadDropzone">
                    <input type="file" name="file" id="uploadFileInput" class="upload-file-input" required>
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

                <!-- Selected File Pill (Shown when file is selected) -->
                <div class="selected-file-preview" id="selectedFilePreview" style="display: none;">
                    <div class="preview-file-icon" id="previewFileIcon">
                        <?php echo render_icon('files'); ?>
                    </div>
                    <div class="preview-file-details">
                        <span class="preview-file-name" id="previewFileName">filename.ext</span>
                        <span class="preview-file-size" id="previewFileSize">0 KB</span>
                    </div>
                    <button type="button" class="btn-clear-selected-file" id="btnClearSelectedFile" title="Remove file">&times;</button>
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

                <p class="form-help" id="uploadLocationHelp">
                    <?php if ($currentNode && $viewMode === 'project'): ?>
                        Target location: Inside <strong><?php echo e($currentNode['name']); ?></strong>
                    <?php else: ?>
                        Target location: <strong>Projects</strong>
                    <?php endif; ?>
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelUpload">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitUpload">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Batch Upload Confirmation -->
<div class="edms-modal-backdrop" id="modalBatchUploadConfirm">
    <div class="edms-modal-dialog" style="max-width: 540px; width: 95%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('cloud-upload'); ?>
                <span id="batchUploadModalTitle">Confirm Document Upload</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseBatchUploadModal" title="Close modal">&times;</button>
        </div>
        <form id="formBatchUploadConfirm">
            <div class="edms-modal-body">
                <div class="batch-upload-summary-bar">
                    <span id="batchUploadCountText">Are you sure you want to upload <strong>0 documents</strong>?</span>
                    <span class="batch-upload-size" id="batchUploadTotalSize">0 KB</span>
                </div>

                <!-- Scrollable File Inspection List with Remove Buttons -->
                <div class="batch-upload-file-list" id="batchUploadFileList">
                    <!-- Populated dynamically by core.js -->
                </div>

                <!-- Initial Status Selector for the Batch -->
                <div class="form-group" style="margin-top: 14px;">
                    <label for="batchUploadStatusSelect" class="form-label">Initial Status for All Documents</label>
                    <div class="select-wrapper">
                        <select id="batchUploadStatusSelect" class="form-input">
                            <option value="active" selected>Active (Draft)</option>
                            <option value="published">Published</option>
                            <option value="hold">On Hold</option>
                        </select>
                    </div>
                </div>

                <!-- Target Location Breadcrumb Indicator -->
                <p class="form-help" id="batchUploadLocationHelp" style="margin-top: 12px;">
                    Target location: <strong>Projects</strong>
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelBatchUpload">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnConfirmBatchUpload">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span id="btnConfirmBatchUploadText">Upload Documents</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Floating Upload Dock (Google Drive Style Progress Tray) -->
<div class="edms-upload-dock" id="edmsUploadDock" style="display: none;">
    <div class="upload-dock-header" id="uploadDockHeader">
        <div class="upload-dock-title">
            <span class="upload-dock-spinner" id="uploadDockSpinner"></span>
            <span id="uploadDockTitleText">Uploading 1 of 3 files...</span>
        </div>
        <div class="upload-dock-actions">
            <button type="button" class="dock-btn-icon" id="btnToggleUploadDock" title="Minimize / Expand">
                <svg class="dock-chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <button type="button" class="dock-btn-icon" id="btnCloseUploadDock" title="Close dock" style="display: none;">&times;</button>
        </div>
    </div>
    <div class="upload-dock-progress-wrapper">
        <div class="upload-dock-progress-bar" id="uploadDockOverallBar" style="width: 0%;"></div>
    </div>
    <div class="upload-dock-body" id="uploadDockBody">
        <div class="upload-dock-item-list" id="uploadDockItemList">
            <!-- Populated dynamically with each uploading specimen -->
        </div>
    </div>
</div>

<!-- Modal: Create New Folder -->
<div class="edms-modal-backdrop" id="modalNewFolder">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <span>Create New Folder</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseNewFolderModal" title="Close modal">&times;</button>
        </div>
        <form id="formNewFolder" method="POST" action="explorer.php">
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_id" id="newFolderParentId" value="<?php echo ($parentId !== null && $viewMode === 'project') ? e((string)$parentId) : ''; ?>">
            
            <div class="edms-modal-body">
                <div class="form-group">
                    <label for="newFolderName" class="form-label">Folder Name</label>
                    <input type="text" id="newFolderName" name="name" class="form-input" placeholder="e.g. Engineering & Specifications" required autocomplete="off">
                    <p class="form-help" id="newFolderContextHelp">
                        <?php if ($currentNode): ?>
                            Target location: Inside <strong><?php echo e($currentNode['name']); ?></strong>
                        <?php else: ?>
                            Target location: <strong>Projects</strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelNewFolder">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitNewFolder">
                    <?php echo render_icon('plus'); ?>
                    <span>Create Folder</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/item_actions.php'; ?>
<?php
require_once __DIR__ . '/../includes/footer.php';
?>
