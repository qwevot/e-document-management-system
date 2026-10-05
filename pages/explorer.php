<?php
$pageTitle = 'Document Explorer';
$activeNav = 'explorer';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// ============================================================
// POST Actions: Create Folder
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Create Folder
    if ($action === 'create_folder') {
        $folderName = trim($_POST['name'] ?? '');
        $postParentId = isset($_POST['parent_id']) && is_numeric($_POST['parent_id']) && (int)$_POST['parent_id'] > 0 
            ? (int)$_POST['parent_id'] 
            : null;

        if ($folderName === '') {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Folder name cannot be empty.']);
                exit;
            }
        } else {
            // Verify parent folder exists if specified
            if ($postParentId !== null) {
                $checkStmt = $pdo->prepare("SELECT id, name FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
                $checkStmt->execute(['id' => $postParentId]);
                if (!$checkStmt->fetch()) {
                    $postParentId = null;
                }
            }

            $userId = current_user()['id'] ?? 1;

            $insertStmt = $pdo->prepare("
                INSERT INTO dms_nodes (parent_id, node_type, name, status, version, is_locked, is_deleted, created_by, created_at, updated_at)
                VALUES (:parent_id, 'folder', :name, 'active', 'v0.1', 0, 0, :created_by, NOW(), NOW())
            ");
            $insertStmt->execute([
                'parent_id'  => $postParentId,
                'name'       => $folderName,
                'created_by' => $userId,
            ]);
            $newFolderId = $pdo->lastInsertId();

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Folder created successfully.',
                    'id'      => $newFolderId,
                    'parent_id' => $postParentId
                ]);
                exit;
            }

            $redirectUrl = 'explorer.php?mode=project' . ($postParentId ? '&folder=' . $postParentId : '');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // 2. Rename Item (Folder / Document)
    if ($action === 'rename_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        $newName = trim($_POST['name'] ?? '');

        if ($nodeId <= 0 || $newName === '') {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'A valid name is required.']);
                exit;
            }
        } else {
            $stmt = $pdo->prepare("UPDATE dms_nodes SET name = :name, updated_at = NOW() WHERE id = :id AND is_deleted = 0");
            $stmt->execute(['name' => $newName, 'id' => $nodeId]);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item renamed successfully.']);
                exit;
            }
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
            exit;
        }
    }

    // 3. Get Move Destinations (Hierarchical Tree for Google Drive Navigation)
    if ($action === 'get_move_destinations') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        $nodeType = trim($_POST['type'] ?? 'document');

        $stmt = $pdo->query("SELECT id, parent_id, name FROM dms_nodes WHERE node_type = 'folder' AND is_deleted = 0 ORDER BY name ASC");
        $allFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $excludedIds = [];
        if ($nodeType === 'folder' && $nodeId > 0) {
            $excludedIds[$nodeId] = true;
            $childrenMap = [];
            foreach ($allFolders as $f) {
                $pid = (int)($f['parent_id'] ?? 0);
                $childrenMap[$pid][] = (int)$f['id'];
            }
            $queue = [$nodeId];
            while (!empty($queue)) {
                $curr = array_shift($queue);
                if (!empty($childrenMap[$curr])) {
                    foreach ($childrenMap[$curr] as $childId) {
                        $excludedIds[$childId] = true;
                        $queue[] = $childId;
                    }
                }
            }
        }

        // Return sanitized list with exclusion flag
        $validFolders = [];
        foreach ($allFolders as $f) {
            $validFolders[] = [
                'id' => (int)$f['id'],
                'parent_id' => !empty($f['parent_id']) ? (int)$f['parent_id'] : null,
                'name' => $f['name'],
                'disabled' => isset($excludedIds[$f['id']])
            ];
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'folders' => $validFolders
        ]);
        exit;
    }

    // 4. Move Item
    if ($action === 'move_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        $targetFolderId = isset($_POST['target_folder_id']) && is_numeric($_POST['target_folder_id']) && (int)$_POST['target_folder_id'] > 0
            ? (int)$_POST['target_folder_id']
            : null;

        if ($nodeId <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
                exit;
            }
        } else {
            if ($targetFolderId !== null) {
                if ($targetFolderId === $nodeId) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Cannot move a folder into itself.']);
                    exit;
                }
                $checkTarget = $pdo->prepare("SELECT id FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
                $checkTarget->execute(['id' => $targetFolderId]);
                if (!$checkTarget->fetch()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Target destination folder does not exist.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("UPDATE dms_nodes SET parent_id = :parent_id, updated_at = NOW() WHERE id = :id AND is_deleted = 0");
            $stmt->execute(['parent_id' => $targetFolderId, 'id' => $nodeId]);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item moved successfully.']);
                exit;
            }
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
            exit;
        }
    }

    // 5. Delete Item (Soft Delete)
    if ($action === 'delete_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;

        if ($nodeId <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
                exit;
            }
        } else {
            $stmt = $pdo->prepare("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $nodeId]);

            // If it was a folder, recursively soft-delete all children and mark obsolete
            $queue = [$nodeId];
            while (!empty($queue)) {
                $currId = array_shift($queue);
                $cStmt = $pdo->prepare("SELECT id FROM dms_nodes WHERE parent_id = :parent_id AND is_deleted = 0");
                $cStmt->execute(['parent_id' => $currId]);
                $childIds = $cStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($childIds)) {
                    $inClause = implode(',', array_map('intval', $childIds));
                    $pdo->exec("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id IN ($inClause)");
                    foreach ($childIds as $cid) {
                        $queue[] = (int)$cid;
                    }
                }
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item deleted and marked obsolete.']);
                exit;
            }
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
            exit;
        }
    }

    // 6. Upload Document Specimen
    if ($action === 'upload_document') {
        require_once __DIR__ . '/../includes/metadata.php';

        $docName     = trim($_POST['name'] ?? '');
        $docType     = trim($_POST['doc_type'] ?? 'Specification');
        $status      = in_array($_POST['status'] ?? '', ['active', 'published', 'hold'], true) ? $_POST['status'] : 'active';
        $postParentId = isset($_POST['parent_id']) && is_numeric($_POST['parent_id']) && (int)$_POST['parent_id'] > 0
            ? (int)$_POST['parent_id']
            : null;

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size limit.',
                UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE   => 'No file was selected for upload.',
                default              => 'File upload failed with error code: ' . $errorCode
            };
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $errMessage]);
                exit;
            }
        } else {
            $file = $_FILES['file'];
            $originalName = $file['name'];
            $fileSize     = (int)$file['size'];
            $tmpPath      = $file['tmp_name'];

            // Calculate SHA-256 genetic hash of the specimen
            $fileHash = hash_file('sha256', $tmpPath);

            // Determine extension and clean mime type
            $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($fileExt === '') {
                $fileExt = 'dat';
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpPath) ?: 'application/octet-stream';
            finfo_close($finfo);

            // Default document name if omitted
            if ($docName === '') {
                $docName = pathinfo($originalName, PATHINFO_FILENAME);
            }

            // Extract built-in metadata (DOCX, XLSX, PDF, EXIF)
            $extractedMeta = DocumentMetadataExtractor::extract($tmpPath, $fileExt);

            // Prepare safe physical storage directory
            $yearMonth = date('Y/m');
            $uploadDir = __DIR__ . '/../uploads/documents/' . $yearMonth;
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            // Generate randomized unique storage filename to prevent directory collisions
            $safeStorageName = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $destinationPath = $uploadDir . '/' . $safeStorageName;
            $relativeStoragePath = 'uploads/documents/' . $yearMonth . '/' . $safeStorageName;

            if (!move_uploaded_file($tmpPath, $destinationPath)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to store file in physical repository.']);
                    exit;
                }
            } else {
                // Verify parent folder exists if specified
                if ($postParentId !== null) {
                    $chk = $pdo->prepare("SELECT id FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
                    $chk->execute(['id' => $postParentId]);
                    if (!$chk->fetch()) {
                        $postParentId = null;
                    }
                }

                $userId = current_user()['id'] ?? 1;

                $stmt = $pdo->prepare("
                    INSERT INTO dms_nodes (
                        parent_id, node_type, name, doc_type, status, version,
                        file_path, file_original_name, file_size, file_extension,
                        mime_type, file_hash, is_locked, is_deleted, created_by,
                        created_at, updated_at
                    ) VALUES (
                        :parent_id, 'document', :name, :doc_type, :status, 'v0.1',
                        :file_path, :file_original_name, :file_size, :file_extension,
                        :mime_type, :file_hash, 0, 0, :created_by,
                        NOW(), NOW()
                    )
                ");

                $stmt->execute([
                    'parent_id'          => $postParentId,
                    'name'               => $docName,
                    'doc_type'           => $docType,
                    'status'             => $status,
                    'file_path'          => $relativeStoragePath,
                    'file_original_name' => $originalName,
                    'file_size'          => $fileSize,
                    'file_extension'     => $fileExt,
                    'mime_type'          => $mimeType,
                    'file_hash'          => $fileHash,
                    'created_by'         => $userId,
                ]);

                $newDocId = $pdo->lastInsertId();

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'        => true,
                        'message'        => 'Document uploaded and registered successfully.',
                        'id'             => $newDocId,
                        'name'           => $docName,
                        'extracted_meta' => $extractedMeta
                    ]);
                    exit;
                }

                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }
        }
    }
}

// 1. Capture request parameters
$parentId   = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode   = isset($_GET['mode']) && in_array($_GET['mode'], ['project', 'document'], true) ? $_GET['mode'] : 'project';
$statusFilt = isset($_GET['status']) && in_array($_GET['status'], ['active', 'hold', 'suspend', 'published', 'obsolete'], true) ? $_GET['status'] : null;
$typeFilt   = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ    = isset($_GET['q']) ? trim($_GET['q']) : '';

// Pagination parameters
$page       = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$allowedPerPages = [10, 25, 50, 100];
$perPage    = isset($_GET['per_page']) && is_numeric($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPages, true) ? (int)$_GET['per_page'] : 25;

// Fetch distinct document types for the dropdown filter
$docTypesStmt = $pdo->query("SELECT DISTINCT doc_type FROM dms_nodes WHERE doc_type IS NOT NULL AND doc_type != '' AND is_deleted = 0 ORDER BY doc_type ASC");
$availableDocTypes = $docTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$standardTypes = ['SOP', 'Specification', 'Drawing', 'Contract', 'Report', 'Manual'];
$allFilterTypes = array_unique(array_merge($availableDocTypes, $standardTypes));
sort($allFilterTypes);

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
    $whereClause = ' WHERE dms_nodes.is_deleted = 0 AND dms_nodes.name LIKE :q';
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
        $whereClause .= ' AND dms_nodes.doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
        $countParams['doc_type'] = $typeFilt;
    }

    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.name ASC';
} elseif ($viewMode === 'document') {
    // Flat All Documents View
    $whereClause = ' WHERE dms_nodes.node_type = "document" AND dms_nodes.is_deleted = 0';

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $whereClause .= ' AND dms_nodes.doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
        $countParams['doc_type'] = $typeFilt;
    }

    $orderBy = ' ORDER BY dms_nodes.name ASC';
} elseif ($parentId === null) {
    // Root level view (ALL Root Folders and Root Documents)
    $whereClause = ' WHERE dms_nodes.parent_id IS NULL AND dms_nodes.is_deleted = 0';

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $whereClause .= ' AND dms_nodes.doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
        $countParams['doc_type'] = $typeFilt;
    }

    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.name ASC';
} else {
    // Inside a specific folder
    $whereClause = ' WHERE dms_nodes.parent_id = :parent_id AND dms_nodes.is_deleted = 0';
    $params['parent_id'] = $parentId;
    $countParams['parent_id'] = $parentId;

    if ($statusFilt !== null) {
        $whereClause .= ' AND dms_nodes.status = :status';
        $params['status'] = $statusFilt;
        $countParams['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $whereClause .= ' AND dms_nodes.doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
        $countParams['doc_type'] = $typeFilt;
    }

    $orderBy = ' ORDER BY dms_nodes.node_type = "folder" DESC, dms_nodes.name ASC';
}

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
        'obsolete'  => 'badge-obsolete',
        default     => 'badge-default',
    };
}

if (!function_exists('get_initials')) {
    function get_initials(string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach ($parts as $p) {
            if ($p !== '') {
                $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                if (mb_strlen($initials) >= 2) break;
            }
        }
        return $initials ?: 'U';
    }
}

// Helper function to render cards HTML (shared between normal load and AJAX)
function render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page = 1, $perPage = 25, $totalCount = 0, $totalPages = 1): void {
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
                <?php echo $searchQ !== '' ? 'No documents or folders matched your query.' : 'No specimens found matching the selected filters. Upload a document or adjust filters.'; ?>
            </p>
        </div>
    <?php else: ?>
        <!-- Google Drive-Style Table Header -->
        <div class="explorer-table-header">
            <div class="col-hdr-checkbox-icon"></div>
            <div class="col-hdr-name">Name</div>
            <div class="col-hdr-owner">Owner</div>
            <div class="col-hdr-modified">Date Modified</div>
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
            <div class="explorer-item-card <?php echo $isFolder ? 'is-folder' : 'is-document'; ?>" id="node-<?php echo e((string)$item['id']); ?>">
                
                <!-- Selection Checkbox -->
                <div class="item-checkbox-col">
                    <input type="checkbox" class="item-checkbox" aria-label="Select item">
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
                        <?php if (!empty($item['doc_type'])): ?>
                            <span class="item-type-badge"><?php echo e($item['doc_type']); ?></span>
                        <?php endif; ?>
                        <?php if (!$isFolder && !empty($item['version'])): ?>
                            <span class="version-badge"><?php echo e($item['version']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Owner / Created By Column -->
                <div class="item-owner-col" title="Created by <?php echo e($item['creator_name']); ?>">
                    <span class="user-avatar-sm"><?php echo e(get_initials($item['creator_name'])); ?></span>
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
                        <span class="location-badge" title="Located at Root">
                            <?php echo render_icon('folder'); ?>
                            <span class="loc-name">Root</span>
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
    render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page, $perPage, $totalCount, $totalPages);
    exit;
}

// Normal full page load
require_once __DIR__ . '/../includes/header.php';
?>

<div class="explorer-wrapper">

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
                    <!-- Document Type Dropdown -->
                    <div class="select-wrapper">
                        <select name="type" id="filterTypeSelect" class="filter-select">
                            <option value="">All Document Types</option>
                            <?php foreach ($allFilterTypes as $dType): ?>
                                <option value="<?php echo e($dType); ?>" <?php echo $typeFilt === $dType ? 'selected' : ''; ?>>
                                    <?php echo e($dType); ?>
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
        <?php render_card_list_html($items, $viewMode, $parentId, $currentNode, $breadcrumbs, $searchQ, $page, $perPage, $totalCount, $totalPages); ?>
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

                <div class="upload-form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="uploadDocType" class="form-label">Document Type</label>
                        <div class="select-wrapper">
                            <select id="uploadDocType" name="doc_type" class="form-input">
                                <option value="SOP">SOP</option>
                                <option value="Specification" selected>Specification</option>
                                <option value="Drawing">Drawing</option>
                                <option value="Contract">Contract</option>
                                <option value="Report">Report</option>
                                <option value="Manual">Manual</option>
                                <option value="Architecture">Architecture</option>
                                <option value="Design">Design</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label for="uploadDocStatus" class="form-label">Initial Status</label>
                        <div class="select-wrapper">
                            <select id="uploadDocStatus" name="status" class="form-input">
                                <option value="active" selected>Active (Draft)</option>
                                <option value="published">Published</option>
                                <option value="hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                </div>

                <p class="form-help" id="uploadLocationHelp">
                    <?php if ($currentNode && $viewMode === 'project'): ?>
                        Target location: Inside <strong><?php echo e($currentNode['name']); ?></strong>
                    <?php else: ?>
                        Target location: <strong>Root Level</strong>
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
                            Target location: <strong>Root Level</strong>
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

<!-- Floating Action Popover Menu (3-Dots Context Menu) -->
<div class="edms-action-popover" id="edmsActionPopover" style="display: none;">
    <a href="#" class="popover-item" id="popoverActionView" style="text-decoration: none;">
        <?php echo render_icon('view'); ?>
        <span>Open Workspace</span>
    </a>
    <a href="#" class="popover-item" id="popoverActionDownload" style="text-decoration: none;">
        <?php echo render_icon('download'); ?>
        <span>Download</span>
    </a>
    <button type="button" class="popover-item" id="popoverActionRename">
        <?php echo render_icon('edit'); ?>
        <span>Rename</span>
    </button>
    <button type="button" class="popover-item" id="popoverActionMove">
        <?php echo render_icon('move'); ?>
        <span>Move to...</span>
    </button>
    <div class="popover-divider"></div>
    <button type="button" class="popover-item is-archive" id="popoverActionDelete">
        <?php echo render_icon('archive'); ?>
        <span>Move to Archives</span>
    </button>
</div>

<!-- Modal: Rename Item -->
<div class="edms-modal-backdrop" id="modalRenameNode">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('edit'); ?>
                <span>Rename Item</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseRenameModal" title="Close modal">&times;</button>
        </div>
        <form id="formRenameNode" method="POST" action="explorer.php">
            <input type="hidden" name="action" value="rename_node">
            <input type="hidden" name="id" id="renameNodeId" value="">
            <div class="edms-modal-body">
                <div class="form-group">
                    <label for="renameNodeName" class="form-label">Item Name</label>
                    <input type="text" id="renameNodeName" name="name" class="form-input" required autocomplete="off">
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelRename">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRename">
                    <span>Save Name</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Move Item (Google Drive Style Navigator) -->
<div class="edms-modal-backdrop" id="modalMoveNode">
    <div class="edms-modal-dialog modal-dialog-drive">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <span>Moving: <strong id="moveModalTitleName">Item</strong></span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseMoveModal" title="Close modal">&times;</button>
        </div>
        <form id="formMoveNode" method="POST" action="explorer.php">
            <input type="hidden" name="action" value="move_node">
            <input type="hidden" name="id" id="moveNodeId" value="">
            <input type="hidden" name="target_folder_id" id="moveTargetFolderId" value="">
            
            <div class="edms-modal-body" style="padding-bottom: 8px;">
                <div class="drive-move-header">
                    <span class="drive-move-label">Current Location:</span>
                    <strong id="moveItemLocationDisplay" class="drive-move-target-name">Projects</strong>
                </div>

                <!-- Interactive Breadcrumb Directory for Destination -->
                <div class="drive-move-path-bar">
                    <div class="drive-move-breadcrumbs" id="driveMoveBreadcrumbs">
                        <!-- Populated by JS: e.g. Root > Folder A > Subfolder B -->
                    </div>
                </div>

                <!-- Folder Navigation Container -->
                <div class="drive-move-explorer">
                    <div class="drive-move-list" id="driveMoveFolderList">
                        <!-- Populated dynamically with folders -->
                    </div>
                </div>
                
                <div class="drive-move-current-target">
                    <span>Destination: </span>
                    <strong id="driveMoveSelectedName">Projects</strong>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelMove">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitMove">
                    <span>Move Here</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Move to Archives / Obsolete Confirmation -->
<div class="edms-modal-backdrop" id="modalDeleteNode">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title" style="color: var(--amber-dark, var(--ink));">
                <span>Move to Archives</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseDeleteModal" title="Close modal">&times;</button>
        </div>
        <form id="formDeleteNode" method="POST" action="explorer.php">
            <input type="hidden" name="action" value="delete_node">
            <input type="hidden" name="id" id="deleteNodeId" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Move <strong id="deleteItemNameDisplay"></strong> to Archives?
                </p>
                <p class="form-help" style="color: var(--muted); line-height: 1.5;">
                    This document will be marked as <strong>OBSOLETE</strong> and archived away from the active workspace. It can be viewed in the Archives repository.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelDelete">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitDelete">
                    <?php echo render_icon('archive'); ?>
                    <span>Archive Item</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Document Viewer (PDF & Inline Viewer) -->
<div class="edms-modal-backdrop modal-backdrop-viewer" id="modalDocViewer">
    <div class="edms-modal-dialog modal-dialog-viewer">
        <div class="edms-modal-header modal-header-viewer">
            <div class="viewer-meta-info">
                <span class="viewer-doc-icon" id="viewerDocIcon">
                    <?php echo render_icon('pdf'); ?>
                </span>
                <span class="viewer-doc-title" id="viewerDocTitle">Document Title</span>
                <span class="viewer-badge badge-ext" id="viewerDocExtBadge">PDF</span>
                <span class="viewer-badge badge-version" id="viewerDocVersionBadge">v1.0</span>
            </div>
            <div class="viewer-header-actions">
                <a href="#" target="_blank" class="btn btn-secondary btn-sm" id="btnViewerNewTab" title="Open in New Tab">
                    <?php echo render_icon('external-link'); ?>
                    <span>New Tab</span>
                </a>
                <a href="#" class="btn btn-primary btn-sm" id="btnViewerDownload" title="Download Document" download>
                    <?php echo render_icon('download'); ?>
                    <span>Download</span>
                </a>
                <button type="button" class="btn-modal-close" id="btnCloseViewerModal" title="Close viewer">&times;</button>
            </div>
        </div>
        <div class="edms-modal-body modal-body-viewer">
            <div class="viewer-loading-indicator" id="viewerLoadingIndicator">
                <div class="viewer-spinner"></div>
                <span>Loading preview...</span>
            </div>
            <iframe id="docViewerFrame" class="doc-viewer-iframe" src="about:blank" title="Document Preview"></iframe>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
