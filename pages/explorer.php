<?php
$pageTitle = 'Document Explorer';
$activeNav = 'explorer';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/header.php';

// 1. Capture request parameters
$parentId   = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewMode   = isset($_GET['mode']) && in_array($_GET['mode'], ['project', 'document'], true) ? $_GET['mode'] : 'project';
$statusFilt = isset($_GET['status']) && in_array($_GET['status'], ['active', 'hold', 'suspend', 'published', 'obsolete'], true) ? $_GET['status'] : null;
$typeFilt   = isset($_GET['type']) && trim($_GET['type']) !== '' ? trim($_GET['type']) : null;
$searchQ    = isset($_GET['q']) ? trim($_GET['q']) : '';

$pdo = db();

// Fetch distinct document types for the dropdown filter
$docTypesStmt = $pdo->query("SELECT DISTINCT doc_type FROM dms_nodes WHERE doc_type IS NOT NULL AND doc_type != '' AND is_deleted = 0 ORDER BY doc_type ASC");
$availableDocTypes = $docTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Predefined fallback types if table has few
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

// 3. Query items according to Mode
$items = [];

if ($searchQ !== '') {
    // Global search mode
    $sql = 'SELECT * FROM dms_nodes WHERE is_deleted = 0 AND (name LIKE :q1 OR code LIKE :q2)';
    $params = [
        'q1' => '%' . $searchQ . '%',
        'q2' => '%' . $searchQ . '%',
    ];

    if ($statusFilt !== null) {
        $sql .= ' AND status = :status';
        $params['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $sql .= ' AND doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
    }

    $sql .= ' ORDER BY node_type = "folder" DESC, name ASC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} elseif ($viewMode === 'document') {
    // Flat All Documents View
    $sql = 'SELECT * FROM dms_nodes WHERE node_type = "document" AND is_deleted = 0';
    $params = [];

    if ($statusFilt !== null) {
        $sql .= ' AND status = :status';
        $params['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $sql .= ' AND doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
    }

    $sql .= ' ORDER BY name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} elseif ($parentId === null) {
    // Root level view (Project Tree Root)
    $sql = 'SELECT * FROM dms_nodes WHERE parent_id IS NULL AND is_deleted = 0 AND node_type = "project"';
    $params = [];

    if ($statusFilt !== null) {
        $sql .= ' AND status = :status';
        $params['status'] = $statusFilt;
    }

    $sql .= ' ORDER BY name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} else {
    // Inside a specific folder/project
    $sql = 'SELECT * FROM dms_nodes WHERE parent_id = :parent_id AND is_deleted = 0';
    $params = ['parent_id' => $parentId];

    if ($statusFilt !== null) {
        $sql .= ' AND status = :status';
        $params['status'] = $statusFilt;
    }
    if ($typeFilt !== null) {
        $sql .= ' AND doc_type = :doc_type';
        $params['doc_type'] = $typeFilt;
    }

    $sql .= ' ORDER BY node_type = "folder" DESC, name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
}

// Helper badge color
function get_status_class(string $status): string {
    return match ($status) {
        'published'  => 'badge-publish',
        'active'   => 'badge-active',
        'hold'     => 'badge-hold',
        'suspend'  => 'badge-suspend',
        'obsolete' => 'badge-obsolete',
        default    => 'badge-default',
    };
}
?>

<div class="explorer-wrapper">

    <!-- Top Action & Search Bar -->
    <div class="explorer-toolbar-card">
        <form method="GET" action="" class="explorer-search-form">
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
                <input type="text" name="q" value="<?php echo e($searchQ); ?>" placeholder="Search documents, projects, or reference codes..." class="search-input">
                <?php if ($searchQ !== ''): ?>
                    <a href="?mode=<?php echo e($viewMode); ?><?php echo $parentId ? '&folder=' . $parentId : ''; ?>" class="search-clear" title="Clear search">&times;</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="toolbar-actions">
            <?php if ($viewMode === 'project'): ?>
                <button type="button" class="btn btn-secondary" onclick="alert('Feature coming in the next step: Create Subfolder');">
                    <?php echo render_icon('plus'); ?>
                    <span>New Folder</span>
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="alert('Feature coming in the next step: Upload Document Modal');">
                <?php echo render_icon('files'); ?>
                <span>Upload Document</span>
            </button>
        </div>
    </div>

    <!-- Filter & Navigation Card: Projects vs Documents Mode + Filters -->
    <div class="explorer-filter-card">
        
        <div class="explorer-nav-left">
            <!-- Main View Switcher (Projects vs Documents) -->
            <?php if ($parentId === null || $viewMode === 'document'): ?>
                <div class="pill-tabs">
                    <a href="?mode=project<?php echo $statusFilt ? '&status=' . $statusFilt : ''; ?><?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" class="pill-tab <?php echo $viewMode === 'project' ? 'active' : ''; ?>">
                        <?php echo render_icon('folder'); ?>
                        <span>Projects</span>
                    </a>
                    <a href="?mode=document<?php echo $statusFilt ? '&status=' . $statusFilt : ''; ?><?php echo $typeFilt ? '&type=' . urlencode($typeFilt) : ''; ?>" class="pill-tab <?php echo $viewMode === 'document' ? 'active' : ''; ?>">
                        <?php echo render_icon('files'); ?>
                        <span>Documents</span>
                    </a>
                </div>
            <?php else: ?>
                <!-- Breadcrumbs (inside a project folder) -->
                <nav class="explorer-breadcrumbs" aria-label="Breadcrumb">
                    <a href="?mode=project" class="breadcrumb-item root-link">
                        <?php echo render_icon('folder'); ?>
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

                    <?php if ($searchQ !== ''): ?>
                        <span class="breadcrumb-sep"><?php echo render_icon('chevron-right'); ?></span>
                        <span class="breadcrumb-item current">Search Results: "<?php echo e($searchQ); ?>"</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>

        <!-- Filter Controls: Document Type & Status Dropdowns -->
        <div class="explorer-filters-right">
            <form method="GET" action="" class="filter-dropdown-form">
                <input type="hidden" name="mode" value="<?php echo e($viewMode); ?>">
                <?php if ($parentId !== null && $viewMode === 'project'): ?>
                    <input type="hidden" name="folder" value="<?php echo e((string)$parentId); ?>">
                <?php endif; ?>
                <?php if ($searchQ !== ''): ?>
                    <input type="hidden" name="q" value="<?php echo e($searchQ); ?>">
                <?php endif; ?>

                <div class="filter-dropdown-group">
                    <!-- Document Type Dropdown -->
                    <div class="select-wrapper">
                        <select name="type" class="filter-select" onchange="this.form.submit()">
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
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $statusFilt === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="published" <?php echo $statusFilt === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="hold" <?php echo $statusFilt === 'hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="suspend" <?php echo $statusFilt === 'suspend' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="obsolete" <?php echo $statusFilt === 'obsolete' ? 'selected' : ''; ?>>Obsolete</option>
                        </select>
                    </div>

                    <!-- Reset Filter Button if active -->
                    <?php if ($typeFilt !== null || $statusFilt !== null): ?>
                        <?php
                        $resetParams = ['mode' => $viewMode];
                        if ($parentId !== null && $viewMode === 'project') $resetParams['folder'] = $parentId;
                        if ($searchQ !== '') $resetParams['q'] = $searchQ;
                        ?>
                        <a href="?<?php echo http_build_query($resetParams); ?>" class="btn-reset-filters" title="Reset all filters">
                            &times; Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        </div>

    </div>

    <!-- Main Card-by-Card Document List -->
    <div class="explorer-card-list">

        <!-- Back Button (if inside subfolder in project mode) -->
        <?php if ($viewMode === 'project' && $parentId !== null && $currentNode): ?>
            <?php
            $backUrl = $currentNode['parent_id'] !== null ? '?mode=project&folder=' . $currentNode['parent_id'] : '?mode=project';
            ?>
            <a href="<?php echo e($backUrl); ?>" class="explorer-item-card back-card">
                <div class="item-icon-col back-icon">
                    <?php echo render_icon('arrow-left'); ?>
                </div>
                <div class="item-main-col">
                    <span class="item-title text-muted">.. [Back to Parent Directory]</span>
                </div>
            </a>
        <?php endif; ?>

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
            <?php foreach ($items as $item): ?>
                <?php
                $isFolder = in_array($item['node_type'], ['project', 'doctype', 'folder'], true);
                $itemLink = $isFolder ? '?mode=project&folder=' . $item['id'] : '#doc-' . $item['id'];
                $extIcon  = $isFolder ? 'folder' : ($item['file_extension'] ?? 'files');
                ?>
                <div class="explorer-item-card <?php echo $isFolder ? 'is-folder' : 'is-document'; ?>" id="node-<?php echo e((string)$item['id']); ?>">
                    
                    <!-- Selection Checkbox -->
                    <div class="item-checkbox-col">
                        <input type="checkbox" class="item-checkbox" aria-label="Select item">
                    </div>

                    <!-- Type Icon -->
                    <a href="<?php echo e($itemLink); ?>" class="item-icon-col icon-<?php echo e($extIcon); ?>">
                        <?php echo render_icon($extIcon); ?>
                    </a>

                    <!-- Main Info Column -->
                    <div class="item-main-col">
                        <div class="item-title-row">
                            <a href="<?php echo e($itemLink); ?>" class="item-title">
                                <?php echo e($item['name']); ?>
                            </a>
                            <?php if (!empty($item['code'])): ?>
                                <span class="item-code-badge"><?php echo e($item['code']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['doc_type'])): ?>
                                <span class="item-type-badge"><?php echo e($item['doc_type']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Version Badge (Document Only) -->
                    <div class="item-version-col">
                        <?php if (!$isFolder && !empty($item['version'])): ?>
                            <span class="version-badge"><?php echo e($item['version']); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Status Badge -->
                    <div class="item-status-col">
                        <?php if (!$isFolder): ?>
                            <span class="status-badge <?php echo get_status_class($item['status']); ?>">
                                <?php echo ucfirst(e($item['status'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="folder-type-label">
                                <?php echo ucfirst(e($item['node_type'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Date & Timestamp -->
                    <div class="item-date-col">
                        <span><?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                    </div>

                    <!-- Quick Action Tools -->
                    <div class="item-actions-col">
                        <?php if (!$isFolder): ?>
                            <button type="button" class="btn-action" title="View / Annotate" onclick="alert('Viewing specimen: <?php echo addslashes($item['name']); ?>');">
                                <?php echo render_icon('view'); ?>
                            </button>
                            <button type="button" class="btn-action" title="Download" onclick="alert('Downloading: <?php echo addslashes($item['name']); ?>');">
                                <?php echo render_icon('download'); ?>
                            </button>
                        <?php else: ?>
                            <a href="<?php echo e($itemLink); ?>" class="btn-action" title="Open Folder">
                                <?php echo render_icon('chevron-right'); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
