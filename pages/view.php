<?php
// Dedicated Fullscreen Document Viewer & Collaborative Annotation Workspace
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/icons.php';

$pdo = db();

$docId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId <= 0) {
    header('Location: explorer.php');
    exit;
}

// Fetch document metadata
$stmt = $pdo->prepare("
    SELECT n.*, COALESCE(u.full_name, u.username, 'System') AS creator_name, p.name AS parent_name
    FROM dms_nodes n
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN dms_nodes p ON n.parent_id = p.id
    WHERE n.id = :id AND n.node_type = 'document' AND n.is_deleted = 0
");
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die('Document specimen not found or has been archived.');
}

$backUrl = 'explorer.php?mode=project' . (!empty($doc['parent_id']) ? '&folder=' . (int)$doc['parent_id'] : '');
$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$streamUrl = 'stream.php?id=' . $doc['id'];
$downloadUrl = 'download.php?id=' . $doc['id'];
$currentUser = current_user() ?? ['id' => 1, 'full_name' => 'Dr. Ruan Mei'];
$userId = (int)($currentUser['id'] ?? 1);
$userName = $currentUser['full_name'] ?? 'Collaborator';

// --- AJAX Annotation Persistence API ---
$action = $_REQUEST['action'] ?? null;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
    || isset($_GET['action']);

if ($action) {
    header('Content-Type: application/json');

    // 1. Fetch all annotations & layers for this document
    if ($action === 'get_annotations') {
        $layersStmt = $pdo->prepare("
            SELECT l.id, l.user_id, l.layer_name, l.color, l.is_visible,
                   COALESCE(u.full_name, u.username, l.layer_name) AS author_name
            FROM dms_annotation_layers l
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.document_id = :doc_id
            ORDER BY l.id ASC
        ");
        $layersStmt->execute(['doc_id' => $docId]);
        $dbLayers = $layersStmt->fetchAll(PDO::FETCH_ASSOC);

        $annosStmt = $pdo->prepare("
            SELECT id, layer_id, page_number, tool_type, payload_json, created_at
            FROM dms_annotations
            WHERE document_id = :doc_id
            ORDER BY id ASC
        ");
        $annosStmt->execute(['doc_id' => $docId]);
        $dbAnnos = $annosStmt->fetchAll(PDO::FETCH_ASSOC);

        $layersMap = [];
        foreach ($dbLayers as $l) {
            $lid = (int)$l['id'];
            $uid = (int)$l['user_id'];
            $layersMap[$uid] = [
                'layerId'    => $lid,
                'userId'     => $uid,
                'name'       => $l['author_name'],
                'color'      => $l['color'],
                'visible'    => (bool)$l['is_visible'],
                'isSelf'     => ($uid === $userId),
                'strokes'    => []
            ];
        }

        // Map annotations into respective user layer
        foreach ($dbAnnos as $a) {
            $lid = (int)$a['layer_id'];
            // Find corresponding layer
            foreach ($layersMap as $uid => &$layerData) {
                if ($layerData['layerId'] === $lid) {
                    $payload = json_decode($a['payload_json'], true);
                    if ($payload) {
                        $payload['id'] = (int)$a['id'];
                        $payload['page'] = (int)$a['page_number'];
                        $layerData['strokes'][] = $payload;
                    }
                    break;
                }
            }
            unset($layerData);
        }

        // Fetch recent annotation history
        $histStmt = $pdo->prepare("
            SELECT h.id, h.user_id, h.action_type, h.tool_type, h.page_number, h.details,
                   DATE_FORMAT(h.created_at, '%b %d, %H:%i') AS formatted_time,
                   COALESCE(u.full_name, u.username, 'Collaborator') AS author_name
            FROM dms_annotation_history h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.document_id = :doc_id
            ORDER BY h.id DESC
            LIMIT 50
        ");
        $histStmt->execute(['doc_id' => $docId]);
        $historyLogs = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'layers'  => $layersMap,
            'history' => $historyLogs
        ]);
        exit;
    }

    // 2. Save a new stroke / text note
    if ($action === 'save_stroke') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data || empty($data['tool'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid stroke payload.']);
            exit;
        }

        $page = isset($data['page']) ? (int)$data['page'] : 1;
        $tool = in_array($data['tool'], ['pen', 'highlighter', 'text'], true) ? $data['tool'] : 'pen';
        $color = trim($data['color'] ?? '#16a34a');

        // If updating an existing stroke/note (e.g. dragged or moved)
        if (!empty($data['id']) && (int)$data['id'] > 0) {
            $updateAnno = $pdo->prepare("
                UPDATE dms_annotations a
                JOIN dms_annotation_layers l ON a.layer_id = l.id
                SET a.page_number = :page, a.tool_type = :tool, a.payload_json = :payload, a.updated_at = NOW()
                WHERE a.id = :id AND l.user_id = :user_id
            ");
            $updateAnno->execute([
                'page'     => $page,
                'tool'     => $tool,
                'payload'  => json_encode($data),
                'id'       => (int)$data['id'],
                'user_id'  => $userId
            ]);

            echo json_encode([
                'success'       => true,
                'annotation_id' => (int)$data['id'],
                'updated'       => true
            ]);
            exit;
        }

        // Find or create user layer
        $layerStmt = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :user_id");
        $layerStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
        $existingLayer = $layerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingLayer) {
            $insertLayer = $pdo->prepare("INSERT INTO dms_annotation_layers (document_id, user_id, layer_name, color, is_visible) VALUES (:doc_id, :user_id, :name, :color, 1)");
            $insertLayer->execute([
                'doc_id'  => $docId,
                'user_id' => $userId,
                'name'    => $userName,
                'color'   => $color
            ]);
            $layerId = (int)$pdo->lastInsertId();
        } else {
            $layerId = (int)$existingLayer['id'];
        }

        $insertAnno = $pdo->prepare("
            INSERT INTO dms_annotations (layer_id, document_id, page_number, tool_type, payload_json)
            VALUES (:layer_id, :doc_id, :page, :tool, :payload)
        ");
        $insertAnno->execute([
            'layer_id' => $layerId,
            'doc_id'   => $docId,
            'page'     => $page,
            'tool'     => $tool,
            'payload'  => json_encode($data)
        ]);

        $annoId = (int)$pdo->lastInsertId();

        // Log creation history
        $rawText = trim($data['content'] ?? ($data['text'] ?? ''));
        $textSnippet = mb_strlen($rawText) > 35 ? mb_substr($rawText, 0, 32) . '...' : $rawText;
        $createDetails = ($tool === 'text')
            ? ('Added comment: "' . $textSnippet . '"')
            : (($tool === 'highlighter') ? 'Highlighted section' : 'Drawn freehand');

        $logStmt = $pdo->prepare("
            INSERT INTO dms_annotation_history (document_id, user_id, action_type, tool_type, page_number, details)
            VALUES (:doc_id, :user_id, 'create', :tool, :page, :details)
        ");
        $logStmt->execute([
            'doc_id'  => $docId,
            'user_id' => $userId,
            'tool'    => $tool,
            'page'    => $page,
            'details' => $createDetails
        ]);

        echo json_encode([
            'success'       => true,
            'annotation_id' => $annoId,
            'layer_id'      => $layerId,
            'history_event' => [
                'id'             => (int)$pdo->lastInsertId(),
                'user_id'        => $userId,
                'author_name'    => $userName,
                'action_type'    => 'create',
                'tool_type'      => $tool,
                'page_number'    => $page,
                'details'        => $createDetails,
                'formatted_time' => date('M d, H:i')
            ]
        ]);
        exit;
    }

    // 3. Delete single stroke (Undo / Delete key)
    if ($action === 'delete_stroke') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $annoId = isset($data['id']) ? (int)$data['id'] : (int)($_POST['id'] ?? 0);

        if ($annoId > 0) {
            $findStmt = $pdo->prepare("SELECT tool_type, page_number, payload_json FROM dms_annotations WHERE id = :id");
            $findStmt->execute(['id' => $annoId]);
            $foundAnno = $findStmt->fetch(PDO::FETCH_ASSOC);
            $delTool = $foundAnno['tool_type'] ?? 'pen';
            $delPage = (int)($foundAnno['page_number'] ?? 1);

            $delStmt = $pdo->prepare("
                DELETE a FROM dms_annotations a
                JOIN dms_annotation_layers l ON a.layer_id = l.id
                WHERE a.id = :id AND l.user_id = :user_id
            ");
            $delStmt->execute(['id' => $annoId, 'user_id' => $userId]);

            if ($delTool === 'text') {
                $delPayload = json_decode($foundAnno['payload_json'] ?? '', true);
                $delContent = trim($delPayload['content'] ?? ($delPayload['text'] ?? ''));
                $delSnippet = mb_strlen($delContent) > 35 ? mb_substr($delContent, 0, 32) . '...' : $delContent;
                $delDetails = $delSnippet !== '' ? ('Deleted comment: "' . $delSnippet . '"') : 'Deleted comment';
            } else {
                $delDetails = 'Erased stroke';
            }

            $logStmt = $pdo->prepare("
                INSERT INTO dms_annotation_history (document_id, user_id, action_type, tool_type, page_number, details)
                VALUES (:doc_id, :user_id, 'delete', :tool, :page, :details)
            ");
            $logStmt->execute([
                'doc_id'  => $docId,
                'user_id' => $userId,
                'tool'    => $delTool,
                'page'    => $delPage,
                'details' => $delDetails
            ]);

            echo json_encode([
                'success'       => true,
                'history_event' => [
                    'id'             => (int)$pdo->lastInsertId(),
                    'user_id'        => $userId,
                    'author_name'    => $userName,
                    'action_type'    => 'delete',
                    'tool_type'      => $delTool,
                    'page_number'    => $delPage,
                    'details'        => $delDetails,
                    'formatted_time' => date('M d, H:i')
                ]
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid stroke ID.']);
        exit;
    }

    // 4. Clear all my annotations on this document
    if ($action === 'clear_my_layer') {
        $delStmt = $pdo->prepare("
            DELETE a FROM dms_annotations a
            JOIN dms_annotation_layers l ON a.layer_id = l.id
            WHERE a.document_id = :doc_id AND l.user_id = :user_id
        ");
        $delStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);

        $logStmt = $pdo->prepare("
            INSERT INTO dms_annotation_history (document_id, user_id, action_type, tool_type, page_number, details)
            VALUES (:doc_id, :user_id, 'clear', NULL, 1, 'Cleared personal annotations')
        ");
        $logStmt->execute([
            'doc_id'  => $docId,
            'user_id' => $userId
        ]);

        echo json_encode([
            'success'       => true,
            'history_event' => [
                'id'             => (int)$pdo->lastInsertId(),
                'user_id'        => $userId,
                'author_name'    => $userName,
                'action_type'    => 'clear',
                'tool_type'      => null,
                'page_number'    => 1,
                'details'        => 'Cleared personal annotations',
                'formatted_time' => date('M d, H:i')
            ]
        ]);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($doc['name']); ?> — Workspace Viewer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="edms-viewer-body">

<div class="workspace-viewport-app" id="workspaceViewerApp">

    <!-- 1. Top Global Navigation Header -->
    <header class="workspace-top-bar">
        <div class="workspace-nav-left">
            <a href="<?php echo e($backUrl); ?>" class="btn-workspace-back" title="Back to Projects">
                <?php echo render_icon('arrow-left'); ?>
                <span>Back</span>
            </a>
            
            <div class="workspace-doc-meta">
                <span class="workspace-file-icon icon-<?php echo e($ext ?: 'pdf'); ?>">
                    <?php echo render_icon($ext ?: 'pdf'); ?>
                </span>
                <span class="workspace-doc-title" title="<?php echo e($doc['name']); ?>">
                    <?php echo e($doc['name']); ?>
                </span>
                <?php if (!empty($doc['doc_type'])): ?>
                    <span class="workspace-badge badge-type"><?php echo e($doc['doc_type']); ?></span>
                <?php endif; ?>
                <span class="workspace-badge badge-version"><?php echo e($doc['version'] ?? 'v1.0'); ?></span>
            </div>
        </div>

        <!-- Center Controls: Zoom & Page Info -->
        <div class="workspace-nav-center">
            <div class="workspace-control-pill">
                <button type="button" class="btn-ctrl" id="btnPrevPage" title="Previous Page">
                    <?php echo render_icon('arrow-left'); ?>
                </button>
                <span class="page-indicator-text" id="pageIndicatorDisplay">Page 1 / 1</span>
                <button type="button" class="btn-ctrl" id="btnNextPage" title="Next Page">
                    <?php echo render_icon('chevron-right'); ?>
                </button>
                <div class="ctrl-sep"></div>
                <button type="button" class="btn-ctrl" id="btnZoomOut" title="Zoom Out">
                    <?php echo render_icon('zoom-out'); ?>
                </button>
                <span class="zoom-level-text" id="zoomLevelDisplay">100%</span>
                <button type="button" class="btn-ctrl" id="btnZoomIn" title="Zoom In">
                    <?php echo render_icon('zoom-in'); ?>
                </button>
                <div class="ctrl-sep"></div>
                <button type="button" class="btn-ctrl" id="btnZoomFit" title="Fit to Width">
                    <span>Fit</span>
                </button>
                <button type="button" class="btn-ctrl" id="btnZoomReset" title="Reset Zoom (100%)">
                    <span>100%</span>
                </button>
            </div>
        </div>

        <!-- Right Controls: Active Collaborators, Layer Drawer Toggle, Download -->
        <div class="workspace-nav-right">
            <!-- Active Collaborator Presence Indicator -->
            <div class="collaborator-presence-strip" title="Active Collaborators">
                <div class="presence-avatar is-self" title="<?php echo e($currentUser['full_name']); ?> (You)">
                    <?php echo e(get_initials($currentUser['full_name'])); ?>
                </div>
            </div>

            <!-- Layer Panel Toggle -->
            <button type="button" class="btn-workspace-action is-active" id="btnToggleLayerDrawer" title="Toggle Annotation Layers">
                <?php echo render_icon('layers'); ?>
                <span>Layers</span>
            </button>

            <!-- Direct Download Button -->
            <a href="<?php echo e($downloadUrl); ?>" class="btn-workspace-action" title="Download Document" download>
                <?php echo render_icon('download'); ?>
                <span>Download</span>
            </a>
        </div>
    </header>

    <!-- 2. Main Workspace Layout Area -->
    <div class="workspace-stage-wrapper">
        
        <!-- Floating Left Tool Palette -->
        <aside class="floating-tool-dock" id="floatingToolDock">
            <div class="tool-dock-section">
                <button type="button" class="dock-btn" data-tool="pan" id="toolPan" title="Pan / Navigate (Hand)">
                    <?php echo render_icon('hand'); ?>
                </button>
                <button type="button" class="dock-btn is-active" data-tool="pen" id="toolPen" title="Freehand Brush">
                    <?php echo render_icon('pen'); ?>
                </button>
                <button type="button" class="dock-btn" data-tool="highlighter" id="toolHighlighter" title="Highlight">
                    <?php echo render_icon('highlighter'); ?>
                </button>
                <button type="button" class="dock-btn" data-tool="text" id="toolText" title="Text Note / Callout">
                    <?php echo render_icon('type'); ?>
                </button>
            </div>

            <div class="tool-dock-divider"></div>

            <!-- Color Palette Swatches -->
            <div class="tool-color-palette" id="toolColorPalette">
                <button type="button" class="color-swatch is-selected" data-color="#16a34a" style="--swatch-color: #16a34a;" title="Emerald Green"></button>
                <button type="button" class="color-swatch" data-color="#f59e0b" style="--swatch-color: #f59e0b;" title="Amber Warning"></button>
                <button type="button" class="color-swatch" data-color="#dc2626" style="--swatch-color: #dc2626;" title="Crimson Red"></button>
                <button type="button" class="color-swatch" data-color="#2563eb" style="--swatch-color: #2563eb;" title="Sky Blue"></button>
                <button type="button" class="color-swatch" data-color="#7c3aed" style="--swatch-color: #7c3aed;" title="Purple"></button>
                <button type="button" class="color-swatch" data-color="#0f172a" style="--swatch-color: #0f172a;" title="Ink Dark"></button>
            </div>

            <!-- Stroke Width Options -->
            <div class="tool-dock-divider"></div>
            <div class="tool-stroke-group">
                <button type="button" class="stroke-btn is-active" data-stroke="2" title="Fine Stroke (2px)">
                    <span style="height: 2px; width: 14px; background: currentColor; border-radius: 1px;"></span>
                </button>
                <button type="button" class="stroke-btn" data-stroke="4" title="Medium Stroke (4px)">
                    <span style="height: 4px; width: 14px; background: currentColor; border-radius: 2px;"></span>
                </button>
                <button type="button" class="stroke-btn" data-stroke="8" title="Thick Stroke (8px)">
                    <span style="height: 8px; width: 14px; background: currentColor; border-radius: 3px;"></span>
                </button>
            </div>

            <div class="tool-dock-divider"></div>

            <!-- History Actions: Undo / Clear Layer -->
            <div class="tool-dock-section">
                <button type="button" class="dock-btn" id="btnUndoStroke" title="Undo Last Stroke">
                    <?php echo render_icon('undo'); ?>
                </button>
                <button type="button" class="dock-btn" id="btnClearMyLayer" title="Clear My Annotations">
                    <?php echo render_icon('trash'); ?>
                </button>
            </div>
        </aside>

        <!-- Center Stage: Scrollable Document & Multi-Layer Canvas Stage -->
        <main class="workspace-center-stage" id="workspaceCenterStage">
            
            <div class="workspace-document-container" id="workspaceDocContainer">
                
                <!-- Loading State Overlay -->
                <div class="workspace-doc-loading" id="workspaceDocLoading">
                    <div class="viewer-spinner"></div>
                    <span id="loadingStatusText">Rendering specimen pages...</span>
                </div>

                <!-- Continuous Multi-Page PDF Container -->
                <div class="pdf-pages-feed" id="pdfPagesFeed">
                    <!-- Dynamic page wrappers injected by PDF.js engine -->
                </div>

            </div>

        </main>

        <!-- Right Side Drawer: Layer Management & Activity -->
        <aside class="workspace-right-drawer is-open" id="workspaceRightDrawer">
            <div class="drawer-header">
                <div class="drawer-title">
                    <?php echo render_icon('layers'); ?>
                    <span>Annotation Layers</span>
                </div>
                <button type="button" class="btn-drawer-close" id="btnCloseRightDrawer" title="Collapse Drawer">&times;</button>
            </div>

            <div class="drawer-body">
                
                <div class="layer-section-title">Active Collaborators</div>
                <div class="layers-list" id="layersList">
                    
                    <!-- My Personal Layer -->
                    <div class="layer-item is-active-user" id="layerRowSelf">
                        <div class="layer-meta">
                            <span class="layer-color-indicator" style="background-color: #16a34a;"></span>
                            <div class="layer-name-info">
                                <span class="layer-author"><?php echo e($currentUser['full_name']); ?> <small>(You)</small></span>
                                <span class="layer-stroke-stat" id="selfStrokeCount">0 annotations</span>
                            </div>
                        </div>
                        <div class="layer-actions">
                            <button type="button" class="btn-layer-toggle is-visible" data-user="self" id="btnToggleSelfLayer" title="Toggle My Layer Visibility">
                                <?php echo render_icon('view'); ?>
                            </button>
                        </div>
                    </div>

                </div>

                <div class="layer-section-title" style="margin-top: 24px;">Layer Transparency</div>
                <div class="layer-opacity-box">
                    <div class="opacity-label-row">
                        <span>Overlay Opacity</span>
                        <span id="opacityDisplayVal">100%</span>
                    </div>
                    <input type="range" id="layerOpacitySlider" min="0" max="100" value="100" class="layer-opacity-slider">
                </div>

                <div class="history-accordion-header" id="btnToggleHistoryAccordion" title="Toggle Annotation History">
                    <div class="history-accordion-title">
                        <span class="history-accordion-arrow" id="historyAccordionArrow">
                            <?php echo render_icon('chevron-right'); ?>
                        </span>
                        <span>Annotation History</span>
                    </div>
                    <span class="history-count-badge" id="historyCountBadge">0 events</span>
                </div>
                <div class="annotation-history-feed is-collapsed" id="annotationHistoryFeed">
                    <div class="history-empty-state" id="historyEmptyState">
                        <span>No annotation events recorded yet.</span>
                    </div>
                </div>

            </div>
        </aside>

    </div>

</div>

<!-- Pass Initial Document Configuration to JS -->
<script>
window.EDMS_WORKSPACE_CONFIG = {
    docId: <?php echo (int)$doc['id']; ?>,
    docName: <?php echo json_encode($doc['name']); ?>,
    fileExt: <?php echo json_encode($ext); ?>,
    streamUrl: <?php echo json_encode($streamUrl); ?>,
    currentUserId: <?php echo (int)$currentUser['id']; ?>,
    currentUserName: <?php echo json_encode($currentUser['full_name']); ?>
};
</script>
<script src="../assets/vendor/pdfjs/pdf.min.js"></script>
<script src="../assets/js/viewer.js" defer></script>
</body>
</html>
