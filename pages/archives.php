<?php
$pageTitle = 'Archives';
$activeNav = 'archives';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$pdo = db();
$currentUser = current_user();
$isSuperAdmin = is_super_admin($currentUser);

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
            $nodeStmt = $pdo->prepare("SELECT name, parent_id FROM dms_nodes WHERE id = :id AND is_deleted = 1");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeToRestore = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$nodeToRestore) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Item not found in archives.']);
                    exit;
                }
                redirect_to('archives', 'error', 'Item not found in archives.');
            }

            $parentId = $nodeToRestore['parent_id'] !== null ? (int)$nodeToRestore['parent_id'] : null;
            $name = $nodeToRestore['name'];

            // Check if active duplicate name already exists in target parent
            if (is_node_duplicate($pdo, $parentId, $name, $nodeId)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'   => false,
                        'message'   => "Cannot restore: An active item named \"{$name}\" already exists in the destination folder.",
                        'duplicate' => true
                    ]);
                    exit;
                }
                redirect_to('archives', 'error', "An item named '{$name}' already exists in that folder.");
            }

            // Restore node and all descendants if folder
            $allAffectedIds = get_descendant_node_ids($pdo, [$nodeId], false);
            $placeholders = implode(',', array_fill(0, count($allAffectedIds), '?'));
            $pdo->prepare("UPDATE dms_nodes SET is_deleted = 0, status = 'active', updated_at = NOW() WHERE id IN ($placeholders)")->execute($allAffectedIds);

            log_activity('node.restore', 'node', $nodeId, [
                'name'              => $name,
                'parent_id'         => $parentId,
                'affected_node_ids' => $allAffectedIds
            ], 'success');

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item restored to active workspace.']);
                exit;
            }
            redirect_to('archives', 'success', 'Item restored successfully.');
        }
    }

    // 1B. Batch Restore Items
    if ($action === 'batch_restore') {
        $postIds = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids'])) : [];
        $nodeIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if (empty($nodeIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No items selected for restore.']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $itemsStmt = $pdo->prepare("SELECT id, name, parent_id FROM dms_nodes WHERE id IN ($placeholders) AND is_deleted = 1");
        $itemsStmt->execute($nodeIds);
        $movingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $conflictingNames = [];
        foreach ($movingItems as $mItem) {
            $pid = $mItem['parent_id'] !== null ? (int)$mItem['parent_id'] : null;
            if (is_node_duplicate($pdo, $pid, $mItem['name'], (int)$mItem['id'])) {
                $conflictingNames[] = $mItem['name'];
            }
        }

        if (!empty($conflictingNames)) {
            $conflictList = implode(', ', array_map(fn($n) => "\"{$n}\"", array_unique($conflictingNames)));
            header('Content-Type: application/json');
            echo json_encode([
                'success'   => false,
                'message'   => "Cannot restore: item(s) named {$conflictList} already exist in the active destination folder.",
                'duplicate' => true
            ]);
            exit;
        }

        $allAffectedIds = get_descendant_node_ids($pdo, $nodeIds, false);
        if (!empty($allAffectedIds)) {
            $descPlaceholders = implode(',', array_fill(0, count($allAffectedIds), '?'));
            $pdo->prepare("UPDATE dms_nodes SET is_deleted = 0, status = 'active', updated_at = NOW() WHERE id IN ($descPlaceholders)")->execute($allAffectedIds);

            log_activity('node.batch_restore', 'node', null, [
                'selected_node_ids' => $nodeIds,
                'affected_node_ids' => $allAffectedIds,
                'count'             => count($nodeIds)
            ], 'success');
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) restored successfully.'
        ]);
        exit;
    }

    // 2. Permanently Delete Item (Hard delete from database & disk) — Super Admin only
    if ($action === 'purge_node') {
        if (!$isSuperAdmin) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Super Administrators can permanently delete archived items.']);
                exit;
            }
            redirect_to('archives', 'error', 'Unauthorized. Only Super Administrators can permanently delete archived items.');
        }

        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($nodeId <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
                exit;
            }
        } else {
            $nodeStmt = $pdo->prepare("SELECT name, node_type FROM dms_nodes WHERE id = :id");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeData = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            $nodesToPurge = get_descendant_node_ids($pdo, [$nodeId], false);

            if (!empty($nodesToPurge)) {
                $placeholders = implode(',', array_fill(0, count($nodesToPurge), '?'));
                
                // Fetch physical files in a single batch
                $fStmt = $pdo->prepare("SELECT file_path FROM dms_nodes WHERE id IN ($placeholders) AND file_path IS NOT NULL");
                $fStmt->execute($nodesToPurge);
                $files = $fStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($files as $filePath) {
                    if (!empty($filePath)) {
                        $physicalPath = __DIR__ . '/../' . ltrim($filePath, '/\\');
                        if (file_exists($physicalPath)) {
                            @unlink($physicalPath);
                        }
                    }
                }

                // Delete associated records in batch
                $pdo->prepare("DELETE FROM dms_annotations WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
                $pdo->prepare("DELETE FROM dms_annotation_layers WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
                $pdo->prepare("DELETE FROM dms_annotation_history WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
                $pdo->prepare("DELETE FROM dms_nodes WHERE id IN ($placeholders)")->execute($nodesToPurge);

                log_activity('node.purge', $nodeData['node_type'] ?? 'document', $nodeId, [
                    'name'             => $nodeData['name'] ?? '',
                    'purged_node_ids'  => $nodesToPurge,
                    'files_unlinked'   => count($files)
                ], 'success');
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item permanently deleted.']);
                exit;
            }
            redirect_to('archives', 'success', 'Item permanently purged from database.');
        }
    }

    // 2B. Batch Permanently Delete Items — Super Admin only
    if ($action === 'batch_purge') {
        if (!$isSuperAdmin) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Super Administrators can permanently delete archived items.']);
            exit;
        }

        $postIds = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids'])) : [];
        $nodeIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if (empty($nodeIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No items selected for permanent deletion.']);
            exit;
        }

        $nodesToPurge = get_descendant_node_ids($pdo, $nodeIds, false);

        if (!empty($nodesToPurge)) {
            $placeholders = implode(',', array_fill(0, count($nodesToPurge), '?'));

            // Fetch physical files in a single batch
            $fStmt = $pdo->prepare("SELECT file_path FROM dms_nodes WHERE id IN ($placeholders) AND file_path IS NOT NULL");
            $fStmt->execute($nodesToPurge);
            $files = $fStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($files as $filePath) {
                if (!empty($filePath)) {
                    $physicalPath = __DIR__ . '/../' . ltrim($filePath, '/\\');
                    if (file_exists($physicalPath)) {
                        @unlink($physicalPath);
                    }
                }
            }

            // Batch purge annotations and nodes
            $pdo->prepare("DELETE FROM dms_annotations WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
            $pdo->prepare("DELETE FROM dms_annotation_layers WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
            $pdo->prepare("DELETE FROM dms_annotation_history WHERE document_id IN ($placeholders)")->execute($nodesToPurge);
            $pdo->prepare("DELETE FROM dms_nodes WHERE id IN ($placeholders)")->execute($nodesToPurge);

            log_activity('node.batch_purge', 'node', null, [
                'selected_node_ids' => $nodeIds,
                'purged_node_ids'   => $nodesToPurge,
                'files_unlinked'    => count($files)
            ], 'success');
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) permanently deleted.'
        ]);
        exit;
    }
}

// 1. Capture request parameters
$folderId = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode = isset($_GET['mode']) && in_array($_GET['mode'], ['project', 'document'], true) ? $_GET['mode'] : 'project';
$typeFilt = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ  = isset($_GET['q']) ? trim($_GET['q']) : '';

// Resolve Breadcrumbs & Current Folder if in project mode inside an archived folder
$breadcrumbs = [];
$currentFolder = null;

if ($viewMode === 'project' && $folderId !== null) {
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

        <div class="toolbar-actions" style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 12px; color: var(--muted); font-weight: 500;">
                Repository: <strong style="color: var(--amber-dark);">Archives (Obsolete)</strong>
            </span>
        </div>
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
    <button type="button" class="popover-item" id="popoverArchiveRestore">
        <?php echo render_icon('refresh'); ?>
        <span id="popoverArchiveRestoreLabel">Restore to Workspace</span>
    </button>
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

<script>
let archivesSearchTimer = null;
let currentArchiveActiveItem = null;

const archivePopover = document.getElementById('archiveActionPopover');
const archivePopoverHeaderLabel = document.getElementById('archivePopoverHeaderLabel');
const popoverArchiveRestore = document.getElementById('popoverArchiveRestore');
const popoverArchiveRestoreLabel = document.getElementById('popoverArchiveRestoreLabel');
const popoverArchivePurge = document.getElementById('popoverArchivePurge');
const popoverArchivePurgeLabel = document.getElementById('popoverArchivePurgeLabel');
const archivesCardList = document.getElementById('archivesCardList');

// Selection State Map
const selectedArchiveMap = new Map();
let isArchiveMarqueeDragging = false;
let archiveMarqueeStartX = 0;
let archiveMarqueeStartY = 0;
let archiveMarqueeEl = null;
let isArchiveContextMenuActive = false;

function hideArchivePopover() {
    if (archivePopover) archivePopover.style.display = 'none';
    isArchiveContextMenuActive = false;
}

function updateArchiveSelectionUI() {
    if (!archivesCardList) return;
    archivesCardList.querySelectorAll('.explorer-item-card').forEach(card => {
        const id = card.dataset.id;
        const isSel = selectedArchiveMap.has(id);
        card.classList.toggle('is-selected', isSel);
        const cb = card.querySelector('.item-checkbox');
        if (cb) cb.checked = isSel;
    });
}

function clearArchiveSelection() {
    selectedArchiveMap.clear();
    updateArchiveSelectionUI();
}

function getArchiveItemData(card) {
    if (!card) return null;
    return {
        id: card.dataset.id,
        name: card.dataset.name,
        type: card.dataset.type || 'document',
        location: card.dataset.location || 'Projects'
    };
}

// Delegated Click for 3-Dots Action Popover (Always Single Selection)
archivesCardList.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-archive-actions');
    if (!btn || !archivePopover) return;
    e.preventDefault();
    e.stopPropagation();

    isArchiveContextMenuActive = false; // Strictly single item action

    currentArchiveActiveItem = {
        id: btn.dataset.id,
        name: btn.dataset.name,
        type: btn.dataset.type || 'document'
    };

    if (archivePopoverHeaderLabel) archivePopoverHeaderLabel.style.display = 'none';
    if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = 'Restore to Workspace';
    if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = 'Permanently Delete';

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

// Checkbox Direct Toggle
archivesCardList.addEventListener('change', function(e) {
    const cb = e.target.closest('.item-checkbox');
    if (!cb) return;
    const card = cb.closest('.explorer-item-card');
    if (!card) return;
    const itemData = getArchiveItemData(card);
    if (!itemData) return;

    if (cb.checked) {
        selectedArchiveMap.set(itemData.id, itemData);
    } else {
        selectedArchiveMap.delete(itemData.id);
    }
    updateArchiveSelectionUI();
});

// Double-click row card to open Workspace (Documents) or navigate inside (Folders)
archivesCardList.addEventListener('dblclick', function(e) {
    if (e.target.closest('input, button, a, .item-checkbox, .btn-action, .btn-archive-actions, .edms-action-popover, .edms-modal-backdrop')) {
        return;
    }
    const card = e.target.closest('.explorer-item-card');
    if (!card) return;

    const id = card.dataset.id;
    const type = card.dataset.type || 'document';

    if (type === 'folder') {
        const folderHidden = document.getElementById('archivesCurrentFolderId') || document.getElementById('archivesFilterFolder');
        if (folderHidden) folderHidden.value = id;
        submitArchivesAjax(1, null, 'project', id);
    } else {
        window.location.href = 'view.php?id=' + encodeURIComponent(id);
    }
});

// Marquee Drag Selection Engine
archivesCardList.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return; // Left click only
    if (e.target.closest('button, a, input, select, textarea, .btn-action, .edms-action-popover, .edms-modal-backdrop')) {
        return;
    }

    const clickedCard = e.target.closest('.explorer-item-card');
    const isModifier = e.ctrlKey || e.metaKey;

    if (clickedCard) {
        const itemData = getArchiveItemData(clickedCard);
        if (isModifier) {
            if (selectedArchiveMap.has(itemData.id)) {
                selectedArchiveMap.delete(itemData.id);
            } else {
                selectedArchiveMap.set(itemData.id, itemData);
            }
            updateArchiveSelectionUI();
            return;
        } else if (!selectedArchiveMap.has(itemData.id)) {
            selectedArchiveMap.clear();
            selectedArchiveMap.set(itemData.id, itemData);
            updateArchiveSelectionUI();
        }
    } else if (!isModifier) {
        clearArchiveSelection();
    }

    isArchiveMarqueeDragging = true;
    archiveMarqueeStartX = e.clientX;
    archiveMarqueeStartY = e.clientY;

    if (!archiveMarqueeEl) {
        archiveMarqueeEl = document.createElement('div');
        archiveMarqueeEl.className = 'selection-marquee';
        archiveMarqueeEl.style.display = 'none';
        document.body.appendChild(archiveMarqueeEl);
    }
});

window.addEventListener('mousemove', function(e) {
    if (!isArchiveMarqueeDragging || !archiveMarqueeEl) return;

    const currentX = e.clientX;
    const currentY = e.clientY;
    const x = Math.min(archiveMarqueeStartX, currentX);
    const y = Math.min(archiveMarqueeStartY, currentY);
    const w = Math.abs(currentX - archiveMarqueeStartX);
    const h = Math.abs(currentY - archiveMarqueeStartY);

    if (w > 4 || h > 4) {
        archiveMarqueeEl.style.display = 'block';
        archiveMarqueeEl.style.left = x + 'px';
        archiveMarqueeEl.style.top = y + 'px';
        archiveMarqueeEl.style.width = w + 'px';
        archiveMarqueeEl.style.height = h + 'px';

        const marqueeRect = archiveMarqueeEl.getBoundingClientRect();
        const cards = archivesCardList.querySelectorAll('.explorer-item-card');

        cards.forEach(card => {
            const cardRect = card.getBoundingClientRect();
            const intersects = !(
                marqueeRect.right < cardRect.left ||
                marqueeRect.left > cardRect.right ||
                marqueeRect.bottom < cardRect.top ||
                marqueeRect.top > cardRect.bottom
            );

            const itemData = getArchiveItemData(card);
            if (intersects) {
                selectedArchiveMap.set(itemData.id, itemData);
            } else if (!e.ctrlKey && !e.metaKey) {
                selectedArchiveMap.delete(itemData.id);
            }
        });

        updateArchiveSelectionUI();
    }
});

window.addEventListener('mouseup', function() {
    if (isArchiveMarqueeDragging) {
        isArchiveMarqueeDragging = false;
        if (archiveMarqueeEl) {
            archiveMarqueeEl.style.display = 'none';
        }
    }
});

// Right-Click Context Menu (Single & Bulk)
archivesCardList.addEventListener('contextmenu', function(e) {
    if (e.target.closest('.edms-modal-dialog')) return;

    e.preventDefault();
    isArchiveContextMenuActive = true;
    const clickedCard = e.target.closest('.explorer-item-card');

    if (clickedCard) {
        const itemData = getArchiveItemData(clickedCard);
        if (!selectedArchiveMap.has(itemData.id)) {
            selectedArchiveMap.clear();
            selectedArchiveMap.set(itemData.id, itemData);
            updateArchiveSelectionUI();
        }
    }

    const count = selectedArchiveMap.size;
    if (count === 0) {
        hideArchivePopover();
        return;
    }

    if (count > 1) {
        if (archivePopoverHeaderLabel) {
            archivePopoverHeaderLabel.style.display = 'block';
            archivePopoverHeaderLabel.textContent = `${count} items selected`;
        }
        if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = `Restore ${count} items`;
        if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = `Permanently Delete (${count})`;
    } else {
        const singleItem = Array.from(selectedArchiveMap.values())[0];
        currentArchiveActiveItem = singleItem;

        if (archivePopoverHeaderLabel) archivePopoverHeaderLabel.style.display = 'none';
        if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = 'Restore to Workspace';
        if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = 'Permanently Delete';
    }

    archivePopover.style.display = 'flex';
    const popoverWidth = 185;
    let left = e.clientX;
    let top = e.clientY;

    if (left + popoverWidth > window.innerWidth) {
        left = window.innerWidth - popoverWidth - 10;
    }
    if (top + 140 > window.innerHeight) {
        top = window.innerHeight - 140;
    }

    archivePopover.style.left = Math.max(10, left) + 'px';
    archivePopover.style.top = Math.max(10, top) + 'px';
});

// Popover item clicks
if (popoverArchiveRestore) {
    popoverArchiveRestore.addEventListener('click', function() {
        const isBulk = isArchiveContextMenuActive && selectedArchiveMap.size > 1;
        const itemsToRestore = isBulk ? Array.from(selectedArchiveMap.values()) : (currentArchiveActiveItem ? [currentArchiveActiveItem] : []);
        hideArchivePopover();
        if (itemsToRestore.length > 0) {
            openRestoreModalForItems(itemsToRestore);
        }
    });
}

if (popoverArchivePurge) {
    popoverArchivePurge.addEventListener('click', function() {
        const isBulk = isArchiveContextMenuActive && selectedArchiveMap.size > 1;
        const itemsToPurge = isBulk ? Array.from(selectedArchiveMap.values()) : (currentArchiveActiveItem ? [currentArchiveActiveItem] : []);
        hideArchivePopover();
        if (itemsToPurge.length > 0) {
            openPurgeModalForItems(itemsToPurge);
        }
    });
}

// Close popover when clicking anywhere else
document.addEventListener('click', function(e) {
    if (archivePopover && !archivePopover.contains(e.target)) {
        hideArchivePopover();
    }
});

// Modal Openers for Single / Bulk Items
function openRestoreModalForItems(items) {
    if (!items || items.length === 0) return;
    const isBatch = items.length > 1;
    document.getElementById('restoreDocAction').value = isBatch ? 'batch_restore' : 'restore_node';
    document.getElementById('restoreDocId').value = isBatch ? '' : items[0].id;
    document.getElementById('restoreDocIds').value = isBatch ? items.map(i => i.id).join(',') : '';
    document.getElementById('restoreDocNameDisplay').textContent = isBatch ? `${items.length} selected items` : items[0].name;
    document.getElementById('btnSubmitRestoreText').textContent = isBatch ? `Restore ${items.length} Items` : 'Confirm Restore';
    document.getElementById('modalRestoreDoc').classList.add('is-open');
}

function closeRestoreModal() {
    document.getElementById('modalRestoreDoc').classList.remove('is-open');
}

function openPurgeModalForItems(items) {
    if (!items || items.length === 0) return;
    const isBatch = items.length > 1;
    document.getElementById('purgeDocAction').value = isBatch ? 'batch_purge' : 'purge_node';
    document.getElementById('purgeDocId').value = isBatch ? '' : items[0].id;
    document.getElementById('purgeDocIds').value = isBatch ? items.map(i => i.id).join(',') : '';
    document.getElementById('purgeDocNameDisplay').textContent = isBatch ? `${items.length} selected items` : items[0].name;
    document.getElementById('btnSubmitPurgeText').textContent = isBatch ? `Delete ${items.length} Items Permanently` : 'Delete Permanently';
    document.getElementById('modalPurgeDoc').classList.add('is-open');
}

function closePurgeModal() {
    document.getElementById('modalPurgeDoc').classList.remove('is-open');
}

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
                clearArchiveSelection();
                submitArchivesAjax();
            } else {
                if (typeof window.showBottomToast === 'function') {
                    window.showBottomToast(data.message || 'Failed to restore document(s).', 'error');
                } else {
                    alert(data.message || 'Failed to restore document(s).');
                }
            }
        })
        .catch(err => {
            console.error('Restore error:', err);
            if (typeof window.showBottomToast === 'function') {
                window.showBottomToast('An error occurred while restoring.', 'error');
            } else {
                alert('An error occurred while restoring.');
            }
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
                clearArchiveSelection();
                submitArchivesAjax();
            } else {
                if (typeof window.showBottomToast === 'function') {
                    window.showBottomToast(data.message || 'Failed to delete document(s).', 'error');
                } else {
                    alert(data.message || 'Failed to delete document(s).');
                }
            }
        })
        .catch(err => {
            console.error('Purge error:', err);
            if (typeof window.showBottomToast === 'function') {
                window.showBottomToast('An error occurred during deletion.', 'error');
            } else {
                alert('An error occurred during deletion.');
            }
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

function handleArchivesModeClick(e, mode) {
    if (e) e.preventDefault();
    const modeHidden1 = document.getElementById('archivesSearchMode');
    const modeHidden2 = document.getElementById('archivesFilterMode');
    if (modeHidden1) modeHidden1.value = mode;
    if (modeHidden2) modeHidden2.value = mode;

    document.querySelectorAll('#archivesPillTabs .pill-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.mode === mode);
    });

    submitArchivesAjax(1, null, mode);
}

function handleArchivesTypeFilter() {
    submitArchivesAjax(1);
}

function handleArchivesPerPage(val) {
    submitArchivesAjax(1, val);
}

function navigateArchivesPage(page) {
    submitArchivesAjax(page);
}

function submitArchivesAjax(page = 1, perPage = null, overrideMode = null, targetFolder = null) {
    const q = document.getElementById('archivesSearchInput')?.value || '';
    const type = document.getElementById('archivesFilterType')?.value || '';
    const mode = overrideMode || document.getElementById('archivesFilterMode')?.value || document.getElementById('archivesSearchMode')?.value || 'project';
    const folder = (mode === 'project') ? (targetFolder !== null ? targetFolder : (document.getElementById('archivesCurrentFolderId')?.value || '')) : '';
    const selectedPerPage = perPage || document.getElementById('archivesPerPageSelect')?.value || 25;

    const params = new URLSearchParams();
    params.set('ajax', '1');
    params.set('mode', mode);
    if (folder && mode === 'project') params.set('folder', folder);
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
        clearArchiveSelection();
    })
    .catch(err => console.error('Archives filter error:', err));
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
        clearArchiveSelection();
        document.querySelectorAll('.edms-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
