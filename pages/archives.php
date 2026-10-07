<?php
$pageTitle = 'Archives';
$activeNav = 'archives';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$pdo = db();

$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// ============================================================
// POST Actions: Restore / Permanently Purge Document
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Restore Item (Folder / Document) to Active Workspace
    if ($action === 'restore_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($nodeId <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
                exit;
            }
        } else {
            // Restore node: set is_deleted = 0, status = 'active'
            $stmt = $pdo->prepare("UPDATE dms_nodes SET is_deleted = 0, status = 'active', updated_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $nodeId]);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item restored to active workspace.']);
                exit;
            }
            redirect_to('archives', 'success', 'Item restored successfully.');
        }
    }

    // 2. Permanently Delete Item (Hard delete from database & disk)
    if ($action === 'purge_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($nodeId <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
                exit;
            }
        } else {
            // Gather all nodes to purge (the node itself plus any descendants if it is a folder)
            $nodesToPurge = [$nodeId];
            $queue = [$nodeId];
            while (!empty($queue)) {
                $currId = array_shift($queue);
                $cStmt = $pdo->prepare("SELECT id FROM dms_nodes WHERE parent_id = :parent_id");
                $cStmt->execute(['parent_id' => $currId]);
                $cIds = $cStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cIds as $cid) {
                    $cidInt = (int)$cid;
                    $nodesToPurge[] = $cidInt;
                    $queue[] = $cidInt;
                }
            }

            foreach ($nodesToPurge as $pid) {
                // Find file path to unlink physical file if exists
                $st = $pdo->prepare("SELECT file_path FROM dms_nodes WHERE id = :id");
                $st->execute(['id' => $pid]);
                $found = $st->fetch(PDO::FETCH_ASSOC);

                if ($found && !empty($found['file_path'])) {
                    $physicalPath = __DIR__ . '/../' . ltrim($found['file_path'], '/\\');
                    if (file_exists($physicalPath)) {
                        @unlink($physicalPath);
                    }
                }

                // Delete annotations and layers associated with this node
                $pdo->prepare("DELETE FROM dms_annotations WHERE document_id = :id")->execute(['id' => $pid]);
                $pdo->prepare("DELETE FROM dms_annotation_layers WHERE document_id = :id")->execute(['id' => $pid]);
                $pdo->prepare("DELETE FROM dms_annotation_history WHERE document_id = :id")->execute(['id' => $pid]);
                $pdo->prepare("DELETE FROM dms_nodes WHERE id = :id")->execute(['id' => $pid]);
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item permanently deleted.']);
                exit;
            }
            redirect_to('archives', 'success', 'Item permanently purged from database.');
        }
    }
}

// 1. Capture request parameters
$folderId = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$typeFilt = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ  = isset($_GET['q']) ? trim($_GET['q']) : '';

// Resolve Breadcrumbs & Current Folder if inside an archived folder
$breadcrumbs = [];
$currentFolder = null;

if ($folderId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM dms_nodes WHERE id = :id AND node_type = "folder"');
    $stmt->execute(['id' => $folderId]);
    $currentFolder = $stmt->fetch();

    if ($currentFolder) {
        $temp = $currentFolder;
        while ($temp) {
            array_unshift($breadcrumbs, $temp);
            if ($temp['parent_id'] !== null) {
                $pStmt = $pdo->prepare('SELECT * FROM dms_nodes WHERE id = :id');
                $pStmt->execute(['id' => $temp['parent_id']]);
                $temp = $pStmt->fetch();
            } else {
                $temp = null;
            }
        }
    } else {
        $folderId = null;
    }
}

// Pagination parameters
$page            = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$allowedPerPages = [10, 25, 50, 100];
$perPage         = isset($_GET['per_page']) && is_numeric($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPages, true) ? (int)$_GET['per_page'] : 25;

// Fetch distinct document types among obsolete documents for filter dropdown
$docTypesStmt = $pdo->query("SELECT DISTINCT doc_type FROM dms_nodes WHERE (status = 'obsolete' OR is_deleted = 1) AND doc_type IS NOT NULL AND doc_type != '' ORDER BY doc_type ASC");
$availableDocTypes = $docTypesStmt->fetchAll(PDO::FETCH_COLUMN);
$standardTypes = ['SOP', 'Specification', 'Drawing', 'Contract', 'Report', 'Manual'];
$allFilterTypes = array_unique(array_merge($availableDocTypes, $standardTypes));
sort($allFilterTypes);

// 2. Query Obsolete Items (Both Folders and Documents)
$baseSelect = 'SELECT dms_nodes.*, 
                      COALESCE(u.full_name, u.username, "System") AS creator_name,
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
} elseif ($folderId !== null) {
    // Inside a specific archived folder
    $whereClause .= ' AND dms_nodes.parent_id = :parent_id';
    $params['parent_id'] = $folderId;
    $countParams['parent_id'] = $folderId;
} else {
    // Root level view of archives:
    // Show root items OR items whose parent is not obsolete (so they surface at archives root)
    $whereClause .= ' AND (dms_nodes.parent_id IS NULL OR dms_nodes.parent_id NOT IN (
        SELECT id FROM dms_nodes WHERE node_type = "folder" AND (status = "obsolete" OR is_deleted = 1)
    ))';
}

if ($typeFilt !== null) {
    $whereClause .= ' AND dms_nodes.doc_type = :doc_type';
    $params['doc_type'] = $typeFilt;
    $countParams['doc_type'] = $typeFilt;
}

$orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.updated_at DESC, dms_nodes.name ASC';

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
function render_archives_card_list($items, $searchQ, $folderId = null, $currentFolder = null, $breadcrumbs = [], $page = 1, $perPage = 25, $totalCount = 0, $totalPages = 1): void {
    ?>
    <!-- Permanent Top File Directory Navigation Bar (Blended Background) -->
    <div class="explorer-directory-bar">
        <?php if ($folderId !== null && $currentFolder): ?>
            <?php
            $backUrl = $currentFolder['parent_id'] !== null ? '?folder=' . $currentFolder['parent_id'] : 'archives.php';
            ?>
            <a href="<?php echo e($backUrl); ?>" class="dir-nav-btn back-btn" title="Go up to parent directory">
                <?php echo render_icon('arrow-left'); ?>
            </a>
        <?php else: ?>
            <span class="dir-nav-btn root-btn" title="Archives Repository" style="color:var(--amber);">
                <?php echo render_icon('archive'); ?>
            </span>
        <?php endif; ?>

        <!-- Breadcrumbs Navigation -->
        <nav class="explorer-breadcrumbs" aria-label="Archive Breadcrumbs">
            <a href="archives.php" class="breadcrumb-item root-link <?php echo ($folderId === null && $searchQ === '') ? 'current' : ''; ?>">
                <span>Archives</span>
            </a>
            <?php foreach ($breadcrumbs as $idx => $crumb): ?>
                <span class="breadcrumb-sep"><?php echo render_icon('chevron-right'); ?></span>
                <?php if ($idx === count($breadcrumbs) - 1 && $searchQ === ''): ?>
                    <span class="breadcrumb-item current"><?php echo e($crumb['name']); ?></span>
                <?php else: ?>
                    <a href="archives.php?folder=<?php echo e((string)$crumb['id']); ?>" class="breadcrumb-item">
                        <?php echo e($crumb['name']); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

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
                <?php echo $searchQ !== '' ? 'No obsolete documents or folders matched your query.' : ($folderId !== null ? 'There are no items inside this archived folder.' : 'There are currently no obsolete or archived specimens in the repository.'); ?>
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
            <div class="explorer-item-card <?php echo $isFolder ? 'is-folder' : 'is-document'; ?>" id="node-<?php echo e((string)$item['id']); ?>">
                <!-- Selection Checkbox -->
                <div class="item-checkbox-col">
                    <input type="checkbox" class="item-checkbox" aria-label="Select item">
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
                            <?php if (!empty($item['doc_type'])): ?>
                                <span class="item-type-badge"><?php echo e($item['doc_type']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['version'])): ?>
                                <span class="version-badge"><?php echo e($item['version']); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Owner Column -->
                <div class="item-owner-col" title="Owner: <?php echo e($item['creator_name']); ?>">
                    <span class="user-avatar-sm"><?php echo e(get_initials($item['creator_name'])); ?></span>
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

        <!-- Pagination Controls Footer (Identical to explorer.php) -->
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
                    <label for="archivesPerPageSelect" class="pagination-label">Rows per page:</label>
                    <select id="archivesPerPageSelect" class="pagination-select" aria-label="Items per page" onchange="handleArchivesPerPage(this.value)">
                        <?php foreach ([10, 25, 50, 100] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Navigation buttons -->
                <div class="pagination-nav-group">
                    <button type="button" class="pagination-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" onclick="navigateArchivesPage(<?php echo max(1, $page - 1); ?>)" title="Previous Page" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                        <?php echo render_icon('arrow-left'); ?>
                    </button>

                    <!-- Page Numbers -->
                    <div class="pagination-pages">
                        <?php
                        $window = 2;
                        $startPage = max(1, $page - $window);
                        $endPage = min($totalPages, $page + $window);

                        if ($startPage > 1): ?>
                            <button type="button" class="pagination-page-btn" onclick="navigateArchivesPage(1)">1</button>
                            <?php if ($startPage > 2): ?>
                                <span class="pagination-ellipsis">&hellip;</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <button type="button" class="pagination-page-btn <?php echo $p === $page ? 'is-active' : ''; ?>" onclick="navigateArchivesPage(<?php echo $p; ?>)">
                                <?php echo $p; ?>
                            </button>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="pagination-ellipsis">&hellip;</span>
                            <?php endif; ?>
                            <button type="button" class="pagination-page-btn" onclick="navigateArchivesPage(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></button>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="pagination-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" onclick="navigateArchivesPage(<?php echo min($totalPages, $page + 1); ?>)" title="Next Page" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                        <?php echo render_icon('chevron-right'); ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endif;
}

// Handle AJAX partial reload
if ($isAjax) {
    render_archives_card_list($items, $searchQ, $folderId, $currentFolder, $breadcrumbs, $page, $perPage, $totalCount, $totalPages);
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
            <?php if ($folderId !== null): ?>
                <input type="hidden" name="folder" value="<?php echo (int)$folderId; ?>" id="archivesCurrentFolderId">
            <?php endif; ?>
            <?php if ($typeFilt !== null): ?>
                <input type="hidden" name="type" value="<?php echo e($typeFilt); ?>">
            <?php endif; ?>

            <div class="explorer-search-box">
                <span class="search-icon"><?php echo render_icon('search'); ?></span>
                <input type="text" name="q" id="archivesSearchInput" value="<?php echo e($searchQ); ?>" placeholder="Search archived documents..." class="search-input" autocomplete="off" oninput="debounceArchivesSearch()">
                <a href="#" id="archivesSearchClear" class="search-clear" title="Clear search" style="<?php echo $searchQ === '' ? 'display: none;' : ''; ?>" onclick="clearArchivesSearch(event)">&times;</a>
            </div>
        </form>

        <div class="explorer-filters-right">
            <div class="filter-dropdown-group">
                <div class="select-wrapper">
                    <select name="type" id="archivesFilterType" class="filter-select" onchange="handleArchivesTypeFilter(this.value)">
                        <option value="">All Document Types</option>
                        <?php foreach ($allFilterTypes as $dType): ?>
                            <option value="<?php echo e($dType); ?>" <?php echo $typeFilt === $dType ? 'selected' : ''; ?>>
                                <?php echo e($dType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

    </div>

    <!-- Main Card-by-Card Document List Container -->
    <div class="explorer-card-list" id="archivesCardList">
        <?php render_archives_card_list($items, $searchQ, $folderId, $currentFolder, $breadcrumbs, $page, $perPage, $totalCount, $totalPages); ?>
    </div>

</div>

<!-- Floating Action Popover Menu (3-Dots Context Menu for Archives) -->
<div class="edms-action-popover" id="archiveActionPopover" style="display: none;">
    <button type="button" class="popover-item" id="popoverArchiveRestore">
        <?php echo render_icon('refresh'); ?>
        <span>Restore to Workspace</span>
    </button>
    <div class="popover-divider"></div>
    <button type="button" class="popover-item is-danger" id="popoverArchivePurge">
        <?php echo render_icon('trash'); ?>
        <span>Permanently Delete</span>
    </button>
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
            <input type="hidden" name="action" value="restore_node">
            <input type="hidden" name="id" id="restoreDocId" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Restore <strong id="restoreDocNameDisplay"></strong> to the active workspace?
                </p>
                <p class="form-help" style="color: var(--muted); line-height: 1.5; font-size:12.5px; margin:0;">
                    Its status will transition from <strong>OBSOLETE</strong> to <strong>ACTIVE</strong>, returning it to the active project tree in its original location.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRestore">
                    <?php echo render_icon('refresh'); ?>
                    <span>Confirm Restore</span>
                </button>
            </div>
        </form>
    </div>
</div>

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
            <input type="hidden" name="action" value="purge_node">
            <input type="hidden" name="id" id="purgeDocId" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Permanently delete <strong id="purgeDocNameDisplay"></strong>?
                </p>
                <p class="form-help" style="color: var(--red-ink); background: var(--red-soft); padding: 8px 12px; border-radius: 6px; font-size: 12px; margin:0; line-height: 1.5;">
                    <strong>Caution:</strong> This operation completely purges the specimen binary, annotations, and database records. If this is a folder, all enclosed contents will also be removed. This cannot be undone.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePurgeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:var(--red); border-color:var(--red);" id="btnSubmitPurge">
                    <?php echo render_icon('trash'); ?>
                    <span>Delete Permanently</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let archivesSearchTimer = null;
let currentArchiveActiveItem = null;

const archivePopover = document.getElementById('archiveActionPopover');
const popoverArchiveRestore = document.getElementById('popoverArchiveRestore');
const popoverArchivePurge = document.getElementById('popoverArchivePurge');

function hideArchivePopover() {
    if (archivePopover) archivePopover.style.display = 'none';
}

// Delegated Click for 3-Dots Action Popover
document.getElementById('archivesCardList').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-archive-actions');
    if (!btn || !archivePopover) return;
    e.preventDefault();
    e.stopPropagation();

    currentArchiveActiveItem = {
        id: btn.dataset.id,
        name: btn.dataset.name
    };

    // Position popover
    const rect = btn.getBoundingClientRect();
    archivePopover.style.display = 'flex';
    const popoverWidth = 175;
    let left = rect.right - popoverWidth;
    if (left < 10) left = 10;
    let top = rect.bottom + 4;
    if (top + 120 > window.innerHeight) {
        top = rect.top - 120;
    }

    archivePopover.style.left = left + 'px';
    archivePopover.style.top = top + 'px';
});

// Popover item clicks
if (popoverArchiveRestore) {
    popoverArchiveRestore.addEventListener('click', function() {
        hideArchivePopover();
        if (!currentArchiveActiveItem) return;
        openRestoreModal(currentArchiveActiveItem.id, currentArchiveActiveItem.name);
    });
}

if (popoverArchivePurge) {
    popoverArchivePurge.addEventListener('click', function() {
        hideArchivePopover();
        if (!currentArchiveActiveItem) return;
        openPurgeModal(currentArchiveActiveItem.id, currentArchiveActiveItem.name);
    });
}

// Close popover when clicking anywhere else
document.addEventListener('click', function(e) {
    if (archivePopover && !archivePopover.contains(e.target)) {
        hideArchivePopover();
    }
});

// Form Submissions with AJAX
const formRestore = document.getElementById('formRestoreDoc');
if (formRestore) {
    formRestore.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(formRestore);
        fetch('archives.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeRestoreModal();
                submitArchivesAjax();
            } else {
                alert(data.message || 'Failed to restore document.');
            }
        })
        .catch(err => {
            console.error('Restore error:', err);
            alert('An error occurred while restoring.');
        });
    });
}

const formPurge = document.getElementById('formPurgeDoc');
if (formPurge) {
    formPurge.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(formPurge);
        fetch('archives.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closePurgeModal();
                submitArchivesAjax();
            } else {
                alert(data.message || 'Failed to delete document.');
            }
        })
        .catch(err => {
            console.error('Purge error:', err);
            alert('An error occurred during deletion.');
        });
    });
}

function debounceArchivesSearch() {
    clearTimeout(archivesSearchTimer);
    archivesSearchTimer = setTimeout(() => {
        submitArchivesAjax();
    }, 280);
    const clearBtn = document.getElementById('archivesSearchClear');
    const inputVal = document.getElementById('archivesSearchInput').value;
    if (clearBtn) clearBtn.style.display = inputVal ? 'block' : 'none';
}

function clearArchivesSearch(e) {
    if (e) e.preventDefault();
    const input = document.getElementById('archivesSearchInput');
    if (input) input.value = '';
    const clearBtn = document.getElementById('archivesSearchClear');
    if (clearBtn) clearBtn.style.display = 'none';
    submitArchivesAjax();
}

function handleArchivesTypeFilter() {
    submitArchivesAjax();
}

function handleArchivesPerPage(val) {
    submitArchivesAjax(1, val);
}

function navigateArchivesPage(page) {
    submitArchivesAjax(page);
}

function submitArchivesAjax(page = 1, perPage = null) {
    const q = document.getElementById('archivesSearchInput')?.value || '';
    const type = document.getElementById('archivesFilterType')?.value || '';
    const folder = document.getElementById('archivesCurrentFolderId')?.value || '';
    const selectedPerPage = perPage || document.getElementById('archivesPerPageSelect')?.value || 25;

    const params = new URLSearchParams();
    params.set('ajax', '1');
    if (folder) params.set('folder', folder);
    if (q) params.set('q', q);
    if (type) params.set('type', type);
    params.set('page', page);
    params.set('per_page', selectedPerPage);

    fetch(`archives.php?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.text())
    .then(html => {
        const list = document.getElementById('archivesCardList');
        if (list) list.innerHTML = html;
        params.delete('ajax');
        window.history.replaceState({}, '', `archives.php?${params.toString()}`);
    })
    .catch(err => console.error('Archives filter error:', err));
}

function openRestoreModal(id, name) {
    document.getElementById('restoreDocId').value = id;
    document.getElementById('restoreDocNameDisplay').textContent = name;
    document.getElementById('modalRestoreDoc').classList.add('is-open');
}

function closeRestoreModal() {
    document.getElementById('modalRestoreDoc').classList.remove('is-open');
}

function openPurgeModal(id, name) {
    document.getElementById('purgeDocId').value = id;
    document.getElementById('purgeDocNameDisplay').textContent = name;
    document.getElementById('modalPurgeDoc').classList.add('is-open');
}

function closePurgeModal() {
    document.getElementById('modalPurgeDoc').classList.remove('is-open');
}

// Modal closing helpers
document.querySelectorAll('.edms-modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.classList.remove('is-open');
    });
});
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideArchivePopover();
        document.querySelectorAll('.edms-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
