<?php
// Dedicated Fullscreen Document Viewer & Collaborative Annotation Workspace
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/icons.php';

require_login();

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
    WHERE n.id = :id AND n.node_type = 'document'
");
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die('Document data not found.');
}

$isArchived = ($doc['status'] === 'obsolete' || (int)$doc['is_deleted'] === 1);
$backUrl = $isArchived ? 'archives.php' : ('explorer.php?mode=project' . (!empty($doc['parent_id']) ? '&folder=' . (int)$doc['parent_id'] : ''));

$currentVersion = $doc['version'] ?? 'v1.0';
$nextMinorVer = calculate_next_version($currentVersion, 'minor');
$nextMajorVer = calculate_next_version($currentVersion, 'major');

// Check if viewing a historical version snapshot
$reqVersionId = isset($_GET['version_id']) && is_numeric($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$isHistoricalSnapshot = false;
$activeVersionName = $currentVersion;

if ($reqVersionId > 0) {
    $vStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE id = :vid AND document_id = :did");
    $vStmt->execute(['vid' => $reqVersionId, 'did' => $docId]);
    $vDoc = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($vDoc) {
        $isHistoricalSnapshot = true;
        $activeVersionName = $vDoc['version_number'];
        $doc['file_path'] = $vDoc['file_path'];
        $doc['file_original_name'] = $vDoc['file_original_name'];
        $doc['file_size'] = $vDoc['file_size'];
        $doc['file_extension'] = $vDoc['file_extension'];
        $doc['mime_type'] = $vDoc['mime_type'];
    }
}

$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$streamUrl = 'stream.php?id=' . $doc['id'] . ($reqVersionId > 0 ? '&version_id=' . $reqVersionId : '');
$downloadUrl = 'download.php?id=' . $doc['id'] . ($reqVersionId > 0 ? '&version_id=' . $reqVersionId : '');
$currentUser = current_user() ?? ['id' => 1, 'full_name' => 'Dr. Ruan Mei'];
$userId = (int)($currentUser['id'] ?? 1);
$userName = $currentUser['full_name'] ?? 'Collaborator';

// Review and workflow query
$reviews = get_document_reviews($docId);
$isDocHold = ($doc['status'] === 'hold');
$isDocActive = ($doc['status'] === 'active');
$isDocPublished = ($doc['status'] === 'published');

$userPendingReview = null;
$approvedCount = 0;
$rejectedCount = 0;
$pendingCount = 0;

foreach ($reviews as $rev) {
    if ($rev['status'] === 'approved') $approvedCount++;
    elseif ($rev['status'] === 'rejected') $rejectedCount++;
    else $pendingCount++;

    if ((int)$rev['reviewer_id'] === $userId && $rev['status'] === 'pending') {
        $userPendingReview = $rev;
    }
}
$allReviewersCount = count($reviews);
$isAdmin = in_array(strtolower($currentUser['role'] ?? ''), ['super admin', 'admin'], true);
$canActOnReview = $isDocHold && ($userPendingReview !== null || $isAdmin);
$eligibleReviewers = get_eligible_reviewers($userId);

// Check if file type can be rendered in the canvas/preview engine
$imageExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp', 'ico'];
$officeExtensions = ['docx', 'doc', 'rtf', 'odt', 'xlsx', 'xls', 'csv', 'pptx', 'ppt', 'txt'];
$hasLibreOffice = is_libreoffice_available();

$isImage = in_array($ext, $imageExtensions, true);
$isPdf = ($ext === 'pdf');
$isOfficeDoc = in_array($ext, $officeExtensions, true);

if ($isPdf || ($isOfficeDoc && $hasLibreOffice)) {
    $renderMode = 'pdf';
} elseif ($isImage) {
    $renderMode = 'image';
} elseif ($isOfficeDoc && ($ext === 'docx' || $ext === 'doc')) {
    $renderMode = 'docx_fallback';
} else {
    $renderMode = 'unsupported';
}

$isSupportedViewer = ($renderMode !== 'unsupported');

// --- AJAX Annotation Persistence API ---
$action = $_REQUEST['action'] ?? null;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
    || isset($_GET['action']);

if ($action) {
    header('Content-Type: application/json');

    // 1. Fetch all annotations & layers for this document (grouped by user and layer)
    if ($action === 'get_annotations') {
        $layersStmt = $pdo->prepare("
            SELECT l.id, l.user_id, l.layer_name, l.color, l.is_visible,
                   COALESCE(u.full_name, u.username, 'Collaborator') AS author_name,
                   u.avatar AS user_avatar
            FROM dms_annotation_layers l
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.document_id = :doc_id
            ORDER BY l.id ASC
        ");
        $layersStmt->execute(['doc_id' => $docId]);
        $dbLayers = $layersStmt->fetchAll(PDO::FETCH_ASSOC);

        // If current user has zero layers for this document, automatically seed their first layer (e.g. "Layer 1")
        $hasSelfLayer = false;
        foreach ($dbLayers as $dl) {
            if ((int)$dl['user_id'] === $userId) {
                $hasSelfLayer = true;
                break;
            }
        }
        if (!$hasSelfLayer) {
            $insertSeed = $pdo->prepare("
                INSERT INTO dms_annotation_layers (document_id, user_id, layer_name, color, is_visible)
                VALUES (:doc_id, :user_id, 'Layer 1', '#16a34a', 1)
            ");
            $insertSeed->execute([
                'doc_id'  => $docId,
                'user_id' => $userId
            ]);
            $seedLayerId = (int)$pdo->lastInsertId();
            $dbLayers[] = [
                'id'          => $seedLayerId,
                'user_id'     => $userId,
                'layer_name'  => 'Layer 1',
                'color'       => '#16a34a',
                'is_visible'  => 1,
                'author_name' => $userName
            ];
        }

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
            $layersMap[$lid] = [
                'layerId'    => $lid,
                'userId'     => $uid,
                'userName'   => $l['author_name'],
                'userAvatar' => $l['user_avatar'] ?? null,
                'layerName'  => $l['layer_name'],
                'color'      => $l['color'],
                'visible'    => (bool)$l['is_visible'],
                'isSelf'     => ($uid === $userId),
                'strokes'    => []
            ];
        }

        // Map annotations into respective layer
        foreach ($dbAnnos as $a) {
            $lid = (int)$a['layer_id'];
            if (isset($layersMap[$lid])) {
                $payload = json_decode($a['payload_json'], true);
                if ($payload) {
                    $payload['id'] = (int)$a['id'];
                    $payload['page'] = (int)$a['page_number'];
                    $payload['layerId'] = $lid;
                    $layersMap[$lid]['strokes'][] = $payload;
                }
            }
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
            'layers'  => array_values($layersMap),
            'history' => $historyLogs
        ]);
        exit;
    }

    // 1b. Create New Layer Group (+ Add Annotation Group)
    if ($action === 'create_layer') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $customName = trim($data['name'] ?? '');
        $layerColor = trim($data['color'] ?? '#16a34a');

        // Determine layer number if name is default
        if ($customName === '') {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :user_id");
            $cntStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
            $count = (int)$cntStmt->fetchColumn();
            $customName = 'Layer ' . ($count + 1);
        }

        $ins = $pdo->prepare("
            INSERT INTO dms_annotation_layers (document_id, user_id, layer_name, color, is_visible)
            VALUES (:doc_id, :user_id, :name, :color, 1)
        ");
        $ins->execute([
            'doc_id'  => $docId,
            'user_id' => $userId,
            'name'    => $customName,
            'color'   => $layerColor
        ]);

        $newLayerId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'layer'   => [
                'layerId'   => $newLayerId,
                'userId'    => $userId,
                'userName'  => $userName,
                'layerName' => $customName,
                'color'     => $layerColor,
                'visible'   => true,
                'isSelf'    => true,
                'strokes'   => []
            ]
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
        $targetLayerId = isset($data['layerId']) && is_numeric($data['layerId']) ? (int)$data['layerId'] : 0;

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

        // Verify target layer belongs to current user and matches this document
        $layerId = 0;
        if ($targetLayerId > 0) {
            $checkLayer = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE id = :lid AND document_id = :doc_id AND user_id = :uid");
            $checkLayer->execute(['lid' => $targetLayerId, 'doc_id' => $docId, 'uid' => $userId]);
            if ($chk = $checkLayer->fetch(PDO::FETCH_ASSOC)) {
                $layerId = (int)$chk['id'];
            }
        }

        // Fallback: Use latest active layer of user or create Layer 1
        if ($layerId === 0) {
            $layerStmt = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :user_id ORDER BY id DESC LIMIT 1");
            $layerStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
            $existingLayer = $layerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingLayer) {
                $insertLayer = $pdo->prepare("INSERT INTO dms_annotation_layers (document_id, user_id, layer_name, color, is_visible) VALUES (:doc_id, :user_id, 'Layer 1', :color, 1)");
                $insertLayer->execute([
                    'doc_id'  => $docId,
                    'user_id' => $userId,
                    'color'   => $color
                ]);
                $layerId = (int)$pdo->lastInsertId();
            } else {
                $layerId = (int)$existingLayer['id'];
            }
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

    // 4. Delete current annotation group (removes annotations and layer record)
    if ($action === 'delete_layer_group') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $reqLayerId = isset($data['layer_id']) && is_numeric($data['layer_id']) ? (int)$data['layer_id'] : 0;

        if ($reqLayerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid layer ID.']);
            exit;
        }

        // Verify layer belongs to current user
        $findL = $pdo->prepare("SELECT layer_name FROM dms_annotation_layers WHERE id = :id AND document_id = :doc_id AND user_id = :uid");
        $findL->execute(['id' => $reqLayerId, 'doc_id' => $docId, 'uid' => $userId]);
        $targetLayer = $findL->fetch(PDO::FETCH_ASSOC);

        if (!$targetLayer) {
            echo json_encode(['success' => false, 'message' => 'Layer group not found or unauthorized.']);
            exit;
        }

        $groupName = $targetLayer['layer_name'];

        // Delete all annotations within this layer
        $delAnno = $pdo->prepare("DELETE FROM dms_annotations WHERE layer_id = :lid AND document_id = :doc_id");
        $delAnno->execute(['lid' => $reqLayerId, 'doc_id' => $docId]);

        // Check how many layers user has on this document
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :uid");
        $cntStmt->execute(['doc_id' => $docId, 'uid' => $userId]);
        $userLayerCount = (int)$cntStmt->fetchColumn();

        $seededNewLayer = null;
        if ($userLayerCount <= 1) {
            // If this is the only layer, rename/reset it to "Layer 1" rather than leaving 0 layers
            $updStmt = $pdo->prepare("UPDATE dms_annotation_layers SET layer_name = 'Layer 1', updated_at = NOW() WHERE id = :lid");
            $updStmt->execute(['lid' => $reqLayerId]);
            $seededNewLayer = [
                'layerId'   => $reqLayerId,
                'userId'    => $userId,
                'userName'  => $userName,
                'layerName' => 'Layer 1',
                'color'     => '#16a34a',
                'visible'   => true,
                'isSelf'    => true,
                'strokes'   => []
            ];
        } else {
            // Delete the layer entry itself
            $delLayer = $pdo->prepare("DELETE FROM dms_annotation_layers WHERE id = :lid AND document_id = :doc_id AND user_id = :uid");
            $delLayer->execute(['lid' => $reqLayerId, 'doc_id' => $docId, 'uid' => $userId]);
        }

        $logStmt = $pdo->prepare("
            INSERT INTO dms_annotation_history (document_id, user_id, action_type, tool_type, page_number, details)
            VALUES (:doc_id, :user_id, 'clear', NULL, 1, :details)
        ");
        $logStmt->execute([
            'doc_id'  => $docId,
            'user_id' => $userId,
            'details' => 'Deleted annotation group: "' . $groupName . '"'
        ]);

        echo json_encode([
            'success'          => true,
            'deleted_layer_id' => $reqLayerId,
            'reset_layer'      => $seededNewLayer,
            'history_event'    => [
                'id'             => (int)$pdo->lastInsertId(),
                'user_id'        => $userId,
                'author_name'    => $userName,
                'action_type'    => 'clear',
                'tool_type'      => null,
                'page_number'    => 1,
                'details'        => 'Deleted annotation group: "' . $groupName . '"',
                'formatted_time' => date('M d, H:i')
            ]
        ]);
        exit;
    }

    // 5. Rename annotation group
    if ($action === 'rename_layer') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $reqLayerId = isset($data['layer_id']) && is_numeric($data['layer_id']) ? (int)$data['layer_id'] : 0;
        $newName = trim($data['name'] ?? '');

        if ($reqLayerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid layer ID.']);
            exit;
        }

        if ($newName === '') {
            echo json_encode(['success' => false, 'message' => 'Layer name cannot be empty.']);
            exit;
        }

        // Limit name length to 60 characters
        if (mb_strlen($newName) > 60) {
            $newName = mb_substr($newName, 0, 60);
        }

        // Verify layer belongs to current user
        $findL = $pdo->prepare("SELECT layer_name FROM dms_annotation_layers WHERE id = :id AND document_id = :doc_id AND user_id = :uid");
        $findL->execute(['id' => $reqLayerId, 'doc_id' => $docId, 'uid' => $userId]);
        $targetLayer = $findL->fetch(PDO::FETCH_ASSOC);

        if (!$targetLayer) {
            echo json_encode(['success' => false, 'message' => 'Layer group not found or unauthorized.']);
            exit;
        }

        $oldName = $targetLayer['layer_name'];

        $upd = $pdo->prepare("UPDATE dms_annotation_layers SET layer_name = :name, updated_at = NOW() WHERE id = :id AND user_id = :uid");
        $upd->execute(['name' => $newName, 'id' => $reqLayerId, 'uid' => $userId]);

        // History record if name changed
        if ($oldName !== $newName) {
            $logStmt = $pdo->prepare("
                INSERT INTO dms_annotation_history (document_id, user_id, action_type, tool_type, page_number, details)
                VALUES (:doc_id, :user_id, 'edit', NULL, 1, :details)
            ");
            $logStmt->execute([
                'doc_id'  => $docId,
                'user_id' => $userId,
                'details' => 'Renamed group "' . $oldName . '" to "' . $newName . '"'
            ]);
        }

        echo json_encode([
            'success'    => true,
            'layer_id'   => $reqLayerId,
            'layer_name' => $newName
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
                <?php if ($isArchived): ?>
                    <span class="workspace-badge" style="background:var(--slate-soft); color:var(--slate-ink); border:1px solid var(--slate-line);">Obsolete</span>
                <?php elseif ($doc['status'] === 'published'): ?>
                    <span class="workspace-badge" style="background:#dcfce7; color:#15803d; border:1px solid #86efac;">Published</span>
                <?php elseif ($doc['status'] === 'hold'): ?>
                    <span class="workspace-badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">Under Review (Hold)</span>
                <?php elseif ($doc['status'] === 'suspend'): ?>
                    <span class="workspace-badge" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">Suspended</span>
                <?php endif; ?>
                <?php if (!empty($ext)): ?>
                    <span class="workspace-badge badge-type">.<?php echo e($ext); ?></span>
                <?php endif; ?>
                <?php if ($isHistoricalSnapshot): ?>
                    <span class="workspace-badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;" title="Viewing historical version snapshot">Snapshot: <?php echo e($activeVersionName); ?></span>
                    <a href="view.php?id=<?php echo $docId; ?>" class="btn-workspace-action" style="padding: 2px 8px; font-size: 11px; height: 24px;" title="Switch to latest active revision">Latest (<?php echo e($currentVersion); ?>)</a>
                <?php else: ?>
                    <button type="button" class="workspace-badge badge-version" id="btnBadgeVersion" title="View Revision Lineage" style="cursor:pointer; background: var(--green-tint-surface); border: 1px dashed var(--green-tint-border);">
                        <?php echo e($doc['version'] ?? 'v1.0'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Center Controls: Zoom & Page Info (Only for supported viewable formats) -->
        <div class="workspace-nav-center">
            <?php if ($isSupportedViewer): ?>
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
            <?php endif; ?>
        </div>

        <!-- Right Controls: Active Collaborators, Submit Review, Version History, Layer Drawer Toggle, Download -->
        <div class="workspace-nav-right">

            <?php if ($isSupportedViewer): ?>
                <!-- Active Collaborator Presence Indicator -->
                <div class="collaborator-presence-strip" title="Active Collaborators">
                    <div class="presence-avatar is-self" title="<?php echo e($currentUser['full_name']); ?> (You)">
                        <?php echo e(get_initials($currentUser['full_name'])); ?>
                    </div>
                </div>

                <!-- Side Panel Toggle -->
                <button type="button" class="btn-workspace-action is-active" id="btnToggleRightDrawer" title="Toggle Side Panel">
                    <?php echo render_icon('layers'); ?>
                    <span>Side Panel</span>
                </button>

                <!-- Download Dropdown Menu for All Supported Formats -->
                <div class="workspace-download-dropdown" id="workspaceDownloadDropdown">
                    <button type="button" class="btn-workspace-action" id="btnDownloadDropdownToggle" title="Download Options">
                        <?php echo render_icon('download'); ?>
                        <span>Download</span>
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px; transition: transform 0.2s;" id="downloadDropdownArrow">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="workspace-download-menu" id="workspaceDownloadMenu">
                        <a href="<?php echo e($downloadUrl); ?>" class="download-menu-item" download>
                            <?php echo render_icon('files'); ?>
                            <div class="menu-item-text">
                                <span class="menu-item-title"><?php 
                                    if ($isOfficeDoc) echo 'Original (.' . e($ext) . ')';
                                    elseif ($isImage) echo 'Original Image (.' . e($ext) . ')';
                                    else echo 'Original Document'; 
                                ?></span>
                                <span class="menu-item-sub">Download source file</span>
                            </div>
                        </a>
                        <?php if ($isOfficeDoc): ?>
                            <a href="download.php?id=<?php echo e((string)$doc['id']); ?>&as_pdf=1" class="download-menu-item" download>
                                <?php echo render_icon('pdf'); ?>
                                <div class="menu-item-text">
                                    <span class="menu-item-title">Download As PDF</span>
                                    <span class="menu-item-sub">Convert to clean vector PDF</span>
                                </div>
                            </a>
                        <?php else: ?>
                            <button type="button" class="download-menu-item" id="btnDownloadWithAnnotations">
                                <?php echo render_icon('edit'); ?>
                                <div class="menu-item-text">
                                    <span class="menu-item-title"><?php echo $isImage ? ('With Annotations (.' . e($ext) . ')') : 'With Annotations (PDF)'; ?></span>
                                    <span class="menu-item-sub">Flatten visible drawings &amp; notes</span>
                                </div>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Clean direct download button when viewer is not applicable -->
                <a href="<?php echo e($downloadUrl); ?>" class="btn-workspace-action is-active" download title="Download File">
                    <?php echo render_icon('download'); ?>
                    <span>Download</span>
                </a>
            <?php endif; ?>

            <?php if ($isDocActive): ?>
                <!-- Submit for Review Trigger -->
                <button type="button" class="btn-workspace-action" id="btnOpenSubmitReviewModal" title="Submit document for review &amp; formal approval" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.4); color: var(--ink);">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 11 17 15 13"></polyline></svg>
                    <span>Submit Review</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($isDocHold): ?>
        <!-- Review & Verification Workflow Header Bar -->
        <div class="workspace-review-bar" style="background: #fffbeb; border-bottom: 1px solid #fde68a; padding: 6px 16px; display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: #92400e; z-index: 9;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; color: #b45309;">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    Under Review
                </span>
                <span style="color: #78350f;">Target Release: <strong><?php echo e($nextMajorVer); ?></strong></span>
                <span style="color: #d97706;">•</span>
                <span>Reviewers (<strong><?php echo $approvedCount; ?>/<?php echo max(1, $allReviewersCount); ?> Approved</strong>):</span>
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <?php foreach ($reviews as $r): ?>
                        <span class="workspace-badge" style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; font-size: 11px; <?php 
                            if ($r['status'] === 'approved') echo 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
                            elseif ($r['status'] === 'rejected') echo 'background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;';
                            else echo 'background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1;';
                        ?>" title="<?php echo e($r['reviewer_name']) . ' (' . ucfirst($r['status']) . ')' . ($r['comments'] ? ': ' . e($r['comments']) : ''); ?>">
                            <?php if ($r['status'] === 'approved'): ?>✔<?php elseif ($r['status'] === 'rejected'): ?>✖<?php else: ?>⏳<?php endif; ?>
                            <?php echo e($r['reviewer_name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?php if ($canActOnReview): ?>
                    <button type="button" class="btn btn-primary" id="btnOpenApproveVerdictModal" style="background: #16a34a; border-color: #16a34a; padding: 3px 12px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                        ✔ Approve &amp; Publish
                    </button>
                    <button type="button" class="btn btn-secondary" id="btnOpenRejectVerdictModal" style="color: #dc2626; border-color: #fca5a5; background: #fff; padding: 3px 12px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                        ✖ Request Changes
                    </button>
                <?php else: ?>
                    <span style="font-size: 11px; color: #92400e; font-style: italic;">Awaiting designated reviewer sign-offs</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isSupportedViewer): ?>
        <!-- 2. Main Workspace Layout Area (Supported Viewer) -->
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
                    <button type="button" class="dock-btn" id="btnClearMyLayer" title="Delete Current Group">
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
                        <span id="loadingStatusText">Rendering pages...</span>
                    </div>

                    <!-- Continuous Multi-Page PDF Container -->
                    <div class="pdf-pages-feed" id="pdfPagesFeed">
                        <!-- Dynamic page wrappers injected by PDF.js engine -->
                    </div>

                </div>

            </main>

            <!-- Right Side Drawer: Annotation Layers & Version Lineage Tabs -->
            <aside class="workspace-right-drawer is-open" id="workspaceRightDrawer">
                <div class="drawer-header" style="padding: 10px 14px; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 4px; background: var(--surface-light, #f1f5f9); padding: 3px; border-radius: 6px; border: 1px solid var(--border); flex: 1;">
                        <button type="button" class="btn-drawer-tab is-active" id="tabBtnAnnotations" style="flex: 1; padding: 5px 8px; font-size: 11.5px; font-weight: 600; border: none; border-radius: 4px; background: var(--surface, #ffffff); color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,0.05); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;">
                            <?php echo render_icon('layers'); ?>
                            <span>Layers</span>
                        </button>
                        <button type="button" class="btn-drawer-tab" id="tabBtnVersions" style="flex: 1; padding: 5px 8px; font-size: 11.5px; font-weight: 600; border: none; border-radius: 4px; background: transparent; color: var(--muted); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;">
                            <?php echo render_icon('history'); ?>
                            <span>Versions</span>
                        </button>
                    </div>
                    <button type="button" class="btn-drawer-close" id="btnCloseRightDrawer" title="Collapse Drawer">&times;</button>
                </div>

                <div class="drawer-body">
                    
                    <!-- 1. Annotations Tab Content -->
                    <div id="drawerTabAnnotations" class="drawer-tab-pane">
                        <div class="layer-section-header-row">
                            <div class="layer-section-title" style="margin-bottom: 0;">Contributors</div>
                            <button type="button" class="btn-add-layer" id="btnAddLayerGroup" title="Add New Annotation Group">
                                <?php echo render_icon('plus'); ?>
                                <span>New Annotation</span>
                            </button>
                        </div>
                        <div class="layers-list" id="layersList">
                            <!-- Dynamic Layer Items rendered by JS -->
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
                        </div>
                        <div class="annotation-history-feed is-collapsed" id="annotationHistoryFeed">
                            <div class="history-empty-state" id="historyEmptyState">
                                <span>No annotation events recorded yet.</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Version History Tab Content -->
                    <div id="drawerTabVersions" class="drawer-tab-pane" style="display: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                            <div class="layer-section-title" style="margin-bottom: 0;">Revision Lineage</div>
                            <button type="button" class="btn btn-primary" id="btnOpenUploadRevisionModal" style="padding: 4px 10px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                <?php echo render_icon('plus'); ?>
                                <span>Upload Revision</span>
                            </button>
                        </div>

                        <!-- Version Timeline Feed -->
                        <div id="versionTimelineContainer" style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="text-align: center; padding: 20px; color: var(--slate-muted); font-size: 13px;">
                                Loading version history...
                            </div>
                        </div>
                    </div>

                </div>
            </aside>

        </div>
    <?php else: ?>
        <!-- Simple Unsupported Filetype Screen -->
        <main class="workspace-center-stage workspace-unsupported-stage">
            <div class="workspace-unsupported-card">
                <div class="unsupported-icon-circle">
                    <?php echo render_icon(!empty($ext) ? $ext : 'files'); ?>
                </div>
                <h2 class="unsupported-title">No preview available</h2>
                <p class="unsupported-desc">
                    The viewer does not support <strong><?php echo !empty($ext) ? '.' . e($ext) : 'this'; ?></strong> files. You can download the file to view it on your device.
                </p>
                <div class="unsupported-actions">
                    <a href="<?php echo e($downloadUrl); ?>" class="btn-unsupported-download" download>
                        <?php echo render_icon('download'); ?>
                        <span>Download <?php echo !empty($ext) ? '.' . strtoupper(e($ext)) : 'File'; ?></span>
                    </a>
                    <a href="<?php echo e($backUrl); ?>" class="btn-unsupported-back">
                        <span>Back</span>
                    </a>
                </div>
            </div>
        </main>
    <?php endif; ?>

</div>

<!-- 2. Modal: Upload Document Revision (Matches explorer.php style) -->
<div class="edms-modal-backdrop" id="modalUploadRevision">
    <div class="edms-modal-dialog" style="max-width: 500px;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('cloud-upload'); ?>
                <span>Upload Document Revision</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseUploadRevisionModal" title="Close modal">&times;</button>
        </div>
        <form id="formUploadRevision" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_revision">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body">
                <!-- Dropzone Area -->
                <div class="upload-dropzone" id="uploadDropzoneRevision">
                    <input type="file" name="file" id="uploadFileInputRevision" class="upload-file-input" required>
                    <div class="dropzone-content">
                        <div class="dropzone-icon">
                            <?php echo render_icon('cloud-upload'); ?>
                        </div>
                        <p class="dropzone-text">
                            <strong>Click to browse</strong> or drag &amp; drop your file here
                        </p>
                        <span class="dropzone-hint">Will be saved as revision <strong><?php echo e($nextMinorVer); ?></strong></span>
                    </div>
                </div>

                <!-- Selected File Pill (Shown when file is selected) -->
                <div class="selected-file-preview" id="selectedFilePreviewRevision" style="display: none; margin-top: 14px;">
                    <div class="preview-file-icon">
                        <?php echo render_icon('files'); ?>
                    </div>
                    <div class="preview-file-details">
                        <span class="preview-file-name" id="previewFileNameRevision">filename.ext</span>
                        <span class="preview-file-size" id="previewFileSizeRevision">0 KB</span>
                    </div>
                    <button type="button" class="btn-clear-selected-file" id="btnClearSelectedFileRevision" title="Remove file">&times;</button>
                </div>

                <!-- Revision Notes -->
                <div class="form-group" style="margin-top: 14px;">
                    <label for="revisionNotes" class="form-label">Revision Summary (Optional)</label>
                    <textarea id="revisionNotes" name="change_summary" rows="2" class="form-input" placeholder="What changed in this revision?"></textarea>
                </div>
            </div>

            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelRevisionUpload">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRevision">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Revision (<?php echo e($nextMinorVer); ?>)</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal: Submit for Review & Approval -->
<div class="edms-modal-backdrop" id="modalSubmitReview">
    <div class="edms-modal-dialog" style="max-width: 520px;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 11 17 15 13"></polyline></svg>
                <span>Submit Review</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseSubmitReviewModal" title="Close modal">&times;</button>
        </div>
        <form id="formSubmitReview">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body">

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label">Assign Reviewers &amp; Approvers</label>
                    <div style="max-height: 160px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; padding: 8px; display: flex; flex-direction: column; gap: 6px; background: var(--surface);">
                        <?php if (empty($eligibleReviewers)): ?>
                            <span style="font-size: 12px; color: var(--muted); padding: 8px; text-align: center;">No other eligible reviewers found (requires Admin or Engineer role).</span>
                        <?php else: ?>
                            <?php foreach ($eligibleReviewers as $r): ?>
                                <label style="display: flex; align-items: center; justify-content: space-between; padding: 6px 8px; border-radius: 4px; cursor: pointer; transition: background 0.1s; font-size: 12.5px;" onmouseover="this.style.background='var(--slate-soft)'" onmouseout="this.style.background='transparent'">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="reviewer_ids[]" value="<?php echo $r['id']; ?>" checked style="cursor: pointer;">
                                        <span style="font-weight: 600; color: var(--ink);"><?php echo e($r['full_name']); ?></span>
                                        <span style="font-size: 11px; color: var(--muted);">@<?php echo e($r['username']); ?></span>
                                    </div>
                                    <span class="workspace-badge" style="font-size: 10.5px; background: var(--slate-soft); color: var(--slate-ink);"><?php echo ucfirst(e($r['role'])); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <span class="form-help">Selected reviewers will receive this document in their workspace review queue.</span>
                </div>

                <div class="form-group">
                    <label for="reviewSubmissionNotes" class="form-label">Submission Notes</label>
                    <textarea id="reviewSubmissionNotes" name="notes" rows="2" class="form-input" placeholder="e.g. Please review architectural section 3."></textarea>
                </div>
            </div>

            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelSubmitReview">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitReviewAction">
                    <span>Submit</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal: Submit Review Verdict (Approve or Reject) -->
<div class="edms-modal-backdrop" id="modalReviewVerdict">
    <div class="edms-modal-dialog" style="max-width: 480px;">
        <div class="edms-modal-header">
            <div class="edms-modal-title" id="verdictModalTitle">
                <span>Record Review Verdict</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseVerdictModal" title="Close modal">&times;</button>
        </div>
        <form id="formReviewVerdict">
            <input type="hidden" name="action" value="submit_verdict">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">
            <input type="hidden" name="verdict" id="verdictTypeValue" value="approved">

            <div class="edms-modal-body">
                <div id="verdictModalBanner" style="padding: 10px 12px; border-radius: 6px; font-size: 12.5px; margin-bottom: 12px;">
                    <!-- Populated dynamically via JS -->
                </div>

                <div class="form-group">
                    <label for="verdictComments" class="form-label" id="verdictCommentsLabel">Feedback / Sign-Off Remarks</label>
                    <textarea id="verdictComments" name="comments" rows="3" class="form-input" placeholder="Enter review remarks..."></textarea>
                    <span class="form-help" id="verdictCommentsHelp">Comments will be logged permanently in the system audit trail.</span>
                </div>
            </div>

            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelVerdict">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitVerdict">
                    <span>Confirm Verdict</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Pass Initial Document Configuration to JS -->
<script>
window.EDMS_WORKSPACE_CONFIG = {
    docId: <?php echo (int)$doc['id']; ?>,
    docName: <?php echo json_encode($doc['name']); ?>,
    fileExt: <?php echo json_encode($ext); ?>,
    renderMode: <?php echo json_encode($renderMode); ?>,
    hasLibreOffice: <?php echo $hasLibreOffice ? 'true' : 'false'; ?>,
    isSupportedViewer: <?php echo $isSupportedViewer ? 'true' : 'false'; ?>,
    streamUrl: <?php echo json_encode($streamUrl); ?>,
    downloadUrl: <?php echo json_encode($downloadUrl); ?>,
    currentUserId: <?php echo (int)$currentUser['id']; ?>,
    currentUserName: <?php echo json_encode($currentUser['full_name']); ?>,
    currentUserAvatar: <?php echo json_encode($currentUser['avatar'] ?? null); ?>
};
</script>
<?php if ($isSupportedViewer): ?>
<?php if ($renderMode === 'pdf'): ?>
<script src="../assets/vendor/pdfjs/pdf.min.js"></script>
<script src="../assets/vendor/pdf-lib.min.js"></script>
<?php elseif ($renderMode === 'docx_fallback'): ?>
<script src="../assets/vendor/jszip.min.js"></script>
<script src="../assets/vendor/docx-preview.min.js"></script>
<?php endif; ?>
<script src="../assets/js/viewer.js" defer></script>
<?php endif; ?>
</body>
</html>
