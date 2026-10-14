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
$defaultBackUrl = $isArchived ? 'archives.php' : ('explorer.php?mode=project' . (!empty($doc['parent_id']) ? '&folder=' . (int)$doc['parent_id'] : ''));

// Determine smart back URL: prioritize genuine origin page (workspace, archives, search, etc.)
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$sessionKey = 'doc_origin_url_' . $docId;

if (!empty($referer)) {
    $refParts = parse_url($referer);
    $currHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $refHost = $refParts['host'] ?? $currHost;
    $refPath = $refParts['path'] ?? '';

    // If referrer is internal to our application and not view.php itself, record it as origin
    if ($refHost === $currHost && !str_ends_with($refPath, 'view.php')) {
        $_SESSION[$sessionKey] = $referer;
    }
}

$backUrl = $_SESSION[$sessionKey] ?? $defaultBackUrl;

$currentVersion = $doc['version'] ?? 'v1.0';
$nextMinorVer = calculate_next_version($currentVersion, 'minor');

// Check if viewing a historical version snapshot
$reqVersionId = isset($_GET['version_id']) && is_numeric($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$isHistoricalSnapshot = false;
$activeVersionName = $currentVersion;
$activeVersionId = 0;

if ($reqVersionId > 0) {
    $vStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE id = :vid AND document_id = :did");
    $vStmt->execute(['vid' => $reqVersionId, 'did' => $docId]);
    $vDoc = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($vDoc) {
        $activeVersionId = (int)$vDoc['id'];
        $isHistoricalSnapshot = ((int)$vDoc['is_current'] !== 1);
        $activeVersionName = $vDoc['version_number'];
        $activeReviewStatus = $vDoc['review_status'] ?? 'draft';
        $doc['file_path'] = $vDoc['file_path'];
        $doc['file_original_name'] = $vDoc['file_original_name'];
        $doc['file_size'] = $vDoc['file_size'];
        $doc['file_extension'] = $vDoc['file_extension'];
        $doc['mime_type'] = $vDoc['mime_type'];
    }
} else {
    $verStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE document_id = :did ORDER BY is_current DESC, id DESC LIMIT 1");
    $verStmt->execute(['did' => $docId]);
    $verRow = $verStmt->fetch(PDO::FETCH_ASSOC);
    if ($verRow) {
        $activeVersionId = (int)$verRow['id'];
        $activeVersionName = $verRow['version_number'];
        $activeReviewStatus = $verRow['review_status'] ?? ($doc['status'] === 'published' ? 'approved' : ($doc['status'] === 'hold' ? 'pending' : 'draft'));
        $doc['file_path'] = $verRow['file_path'];
        $doc['file_original_name'] = $verRow['file_original_name'];
        $doc['file_size'] = $verRow['file_size'];
        $doc['file_extension'] = $verRow['file_extension'];
        $doc['mime_type'] = $verRow['mime_type'];
    }
}

$effectiveVersionId = $reqVersionId > 0 ? $reqVersionId : ($activeVersionId > 0 ? $activeVersionId : null);
$versionQueryParam = ($effectiveVersionId ? '&version_id=' . $effectiveVersionId : '') 
    . ($activeVersionName !== '' ? '&v=' . urlencode($activeVersionName) : '');

$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$streamUrl = 'stream.php?id=' . $doc['id'] . $versionQueryParam;
$downloadUrl = 'download.php?id=' . $doc['id'] . ($effectiveVersionId ? '&version_id=' . $effectiveVersionId : '');
$currentUser = current_user() ?? ['id' => 1, 'full_name' => 'Dr. Ruan Mei'];
$userId = (int)($currentUser['id'] ?? 1);
$userName = $currentUser['full_name'] ?? 'Collaborator';

// Record document access telemetry (throttled to avoid redundant spam on page reload)
$viewThrottleKey = 'last_doc_view_' . $docId . '_' . $userId;
if (!isset($_SESSION[$viewThrottleKey]) || (time() - (int)$_SESSION[$viewThrottleKey]) > 60) {
    $_SESSION[$viewThrottleKey] = time();
    log_activity('document.view', 'document', $docId, [
        'name'    => $doc['name'],
        'version' => $activeVersionName
    ], 'success');
}

// Review and workflow query
$reviews = get_document_reviews($docId);
$isDocHold = ($doc['status'] === 'hold');
$isDocActive = ($doc['status'] === 'active');
$isDocPublished = ($doc['status'] === 'published');

$userPendingReview = null;
$approvedCount = 0;
$rejectedCount = 0;
$pendingCount = 0;

$submittedByUserId = 0;
foreach ($reviews as $rev) {
    if ($rev['status'] === 'approved') $approvedCount++;
    elseif ($rev['status'] === 'rejected') $rejectedCount++;
    else $pendingCount++;

    if ((int)$rev['reviewer_id'] === $userId && $rev['status'] === 'pending') {
        $userPendingReview = $rev;
    }
    if ($submittedByUserId === 0 && !empty($rev['requested_by'])) {
        $submittedByUserId = (int)$rev['requested_by'];
    }
}
$allReviewersCount = count($reviews);
if ($submittedByUserId === 0) {
    $submittedByUserId = (int)$doc['created_by'];
}

$isAdmin = in_array(strtolower($currentUser['role'] ?? ''), ['super admin', 'admin'], true);
$isOwner = ((int)$doc['created_by'] === $userId || $isAdmin);
$accessMode = $doc['access_mode'] ?? 'restricted';
$isContributor = is_document_contributor($docId, $userId);
$canAnnotate = can_user_annotate_document($doc, $userId);
$canUploadRevision = can_user_upload_revision($doc, $userId);
$canSubmitReview = ($isDocActive && $isContributor);
$canManageAccess = $isOwner;

$isSubmitter = ($userId === $submittedByUserId || (int)$doc['created_by'] === $userId);

// Reviewers / Approvers can act on the review (Approve / Request Changes)
$canActOnReview = $isDocHold && ($userPendingReview !== null || ($isAdmin && !$isSubmitter));

// Only the submitter who requested the review can retract/cancel it
$canRetractReview = $isDocHold && $isSubmitter;
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

    $ajaxVersionId = isset($_REQUEST['version_id']) && is_numeric($_REQUEST['version_id']) && (int)$_REQUEST['version_id'] > 0
        ? (int)$_REQUEST['version_id']
        : ($activeVersionId > 0 ? $activeVersionId : null);

    // 0. Fetch consolidated document audit history
    if ($action === 'get_audit_history') {
        echo json_encode(array_merge(['success' => true], get_document_audit_trail($docId)));
        exit;
    }

    // 1. Fetch all annotations & layers for this document and version (grouped by user and layer)
    if ($action === 'get_annotations') {
        if ($ajaxVersionId) {
            $layersStmt = $pdo->prepare("
                SELECT l.id, l.user_id, l.layer_name, l.color, l.is_visible,
                       COALESCE(u.full_name, u.username, 'Collaborator') AS author_name,
                       u.avatar AS user_avatar
                FROM dms_annotation_layers l
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.document_id = :doc_id AND l.version_id = :ver_id
                ORDER BY l.id ASC
            ");
            $layersStmt->execute(['doc_id' => $docId, 'ver_id' => $ajaxVersionId]);
        } else {
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
        }
        $dbLayers = $layersStmt->fetchAll(PDO::FETCH_ASSOC);

        // If current user is allowed to annotate and has zero layers for this version, seed their first layer (e.g. "Layer 1")
        $hasSelfLayer = false;
        foreach ($dbLayers as $dl) {
            if ((int)$dl['user_id'] === $userId) {
                $hasSelfLayer = true;
                break;
            }
        }
        if ($canAnnotate && !$isHistoricalSnapshot && !$hasSelfLayer) {
            $dbLayers[] = [
                'id'          => 0,
                'user_id'     => $userId,
                'layer_name'  => 'Layer 1',
                'color'       => '#16a34a',
                'is_visible'  => 1,
                'author_name' => $userName,
                'user_avatar' => $currentUser['avatar'] ?? null
            ];
        }

        if ($ajaxVersionId) {
            $annosStmt = $pdo->prepare("
                SELECT id, layer_id, page_number, tool_type, payload_json, created_at
                FROM dms_annotations
                WHERE document_id = :doc_id AND (version_id = :ver_id OR (version_id IS NULL AND layer_id IN (SELECT id FROM dms_annotation_layers WHERE version_id = :ver_id_sub)))
                ORDER BY id ASC
            ");
            $annosStmt->execute([
                'doc_id'     => $docId,
                'ver_id'     => $ajaxVersionId,
                'ver_id_sub' => $ajaxVersionId
            ]);
        } else {
            $annosStmt = $pdo->prepare("
                SELECT id, layer_id, page_number, tool_type, payload_json, created_at
                FROM dms_annotations
                WHERE document_id = :doc_id
                ORDER BY id ASC
            ");
            $annosStmt->execute(['doc_id' => $docId]);
        }
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

        // Filter layers:
        // 1. Keep personal layers if $canAnnotate OR if user already has saved strokes
        // 2. For other users, only include their layer if it has at least 1 stroke
        $filteredLayers = [];
        foreach ($layersMap as $lid => $layer) {
            $isSelf = ($layer['userId'] === $userId);
            $hasStrokes = !empty($layer['strokes']);
            if ($isSelf) {
                if (($canAnnotate && !$isHistoricalSnapshot) || $hasStrokes) {
                    $filteredLayers[] = $layer;
                }
            } else {
                if ($hasStrokes) {
                    $filteredLayers[] = $layer;
                }
            }
        }

        // Fetch recent annotation history for this version
        if ($ajaxVersionId) {
            $histStmt = $pdo->prepare("
                SELECT h.id, h.user_id, h.action_type, h.tool_type, h.page_number, h.details,
                    DATE_FORMAT(h.created_at, '%b %d, %H:%i') AS formatted_time,
                    COALESCE(u.full_name, u.username, 'Collaborator') AS author_name
                FROM dms_annotation_history h
                LEFT JOIN users u ON h.user_id = u.id
                WHERE h.document_id = :doc_id AND (h.version_id = :ver_id OR h.version_id IS NULL)
                ORDER BY h.id DESC
                LIMIT 50
            ");
            $histStmt->execute(['doc_id' => $docId, 'ver_id' => $ajaxVersionId]);
        } else {
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
        }
        $historyLogs = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'layers'  => $filteredLayers,
            'history' => $historyLogs
        ]);
        exit;
    }

    // 1b. Create New Layer Group (+ Add Annotation Group)
    if ($action === 'create_layer') {
        if (!$canAnnotate || $isHistoricalSnapshot) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to annotate this version.']);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $customName = trim($data['name'] ?? '');
        $layerColor = trim($data['color'] ?? '#16a34a');

        // Determine layer number if name is default
        if ($customName === '') {
            if ($ajaxVersionId) {
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND version_id = :ver_id AND user_id = :user_id");
                $cntStmt->execute(['doc_id' => $docId, 'ver_id' => $ajaxVersionId, 'user_id' => $userId]);
            } else {
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :user_id");
                $cntStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
            }
            $count = (int)$cntStmt->fetchColumn();
            $customName = 'Layer ' . ($count + 1);
        }

        $ins = $pdo->prepare("
            INSERT INTO dms_annotation_layers (document_id, version_id, user_id, layer_name, color, is_visible)
            VALUES (:doc_id, :ver_id, :user_id, :name, :color, 1)
        ");
        $ins->execute([
            'doc_id'  => $docId,
            'ver_id'  => $ajaxVersionId,
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
        if (!$canAnnotate || $isHistoricalSnapshot) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to annotate this version.']);
            exit;
        }

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
            if ($ajaxVersionId) {
                $layerStmt = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE document_id = :doc_id AND version_id = :ver_id AND user_id = :user_id ORDER BY id DESC LIMIT 1");
                $layerStmt->execute(['doc_id' => $docId, 'ver_id' => $ajaxVersionId, 'user_id' => $userId]);
            } else {
                $layerStmt = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :user_id ORDER BY id DESC LIMIT 1");
                $layerStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
            }
            $existingLayer = $layerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingLayer) {
                $insertLayer = $pdo->prepare("INSERT INTO dms_annotation_layers (document_id, version_id, user_id, layer_name, color, is_visible) VALUES (:doc_id, :ver_id, :user_id, 'Layer 1', :color, 1)");
                $insertLayer->execute([
                    'doc_id'  => $docId,
                    'ver_id'  => $ajaxVersionId,
                    'user_id' => $userId,
                    'color'   => $color
                ]);
                $layerId = (int)$pdo->lastInsertId();
            } else {
                $layerId = (int)$existingLayer['id'];
            }
        }

        $insertAnno = $pdo->prepare("
            INSERT INTO dms_annotations (layer_id, document_id, version_id, page_number, tool_type, payload_json)
            VALUES (:layer_id, :doc_id, :ver_id, :page, :tool, :payload)
        ");
        $insertAnno->execute([
            'layer_id' => $layerId,
            'doc_id'   => $docId,
            'ver_id'   => $ajaxVersionId,
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
            INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details)
            VALUES (:doc_id, :ver_id, :user_id, 'create', :tool, :page, :details)
        ");
        $logStmt->execute([
            'doc_id'  => $docId,
            'ver_id'  => $ajaxVersionId,
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
        if (!$canAnnotate || $isHistoricalSnapshot) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to annotate this version.']);
            exit;
        }

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
                INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details)
                VALUES (:doc_id, :ver_id, :user_id, 'delete', :tool, :page, :details)
            ");
            $logStmt->execute([
                'doc_id'  => $docId,
                'ver_id'  => $ajaxVersionId,
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
        if (!$canAnnotate || $isHistoricalSnapshot) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to annotate this version.']);
            exit;
        }

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

        // Check how many layers user has on this document/version
        if ($ajaxVersionId) {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND version_id = :ver_id AND user_id = :uid");
            $cntStmt->execute(['doc_id' => $docId, 'ver_id' => $ajaxVersionId, 'uid' => $userId]);
        } else {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM dms_annotation_layers WHERE document_id = :doc_id AND user_id = :uid");
            $cntStmt->execute(['doc_id' => $docId, 'uid' => $userId]);
        }
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
            INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details)
            VALUES (:doc_id, :ver_id, :user_id, 'clear', NULL, 1, :details)
        ");
        $logStmt->execute([
            'doc_id'  => $docId,
            'ver_id'  => $ajaxVersionId,
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
        if (!$canAnnotate || $isHistoricalSnapshot) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to annotate this version.']);
            exit;
        }

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
                INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details)
                VALUES (:doc_id, :ver_id, :user_id, 'edit', NULL, 1, :details)
            ");
            $logStmt->execute([
                'doc_id'  => $docId,
                'ver_id'  => $ajaxVersionId,
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

$reviewerUserIds = array_values(array_unique(array_map('intval', array_column($reviews, 'reviewer_id'))));

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
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <script>
    function handleWorkspaceBack(e, fallbackUrl) {
        if (window.history.length > 1 && document.referrer && !document.referrer.includes('view.php')) {
            if (e) e.preventDefault();
            window.history.back();
            return;
        }
        if (fallbackUrl && e && e.defaultPrevented) {
            window.location.href = fallbackUrl;
        }
    }
    </script>
</head>
<body class="edms-viewer-body">

<div class="workspace-viewport-app" id="workspaceViewerApp">

    <!-- 1. Top Global Navigation Header -->
    <header class="workspace-top-bar">
        <div class="workspace-nav-left">
            <a href="<?php echo e($backUrl); ?>" class="btn-workspace-back" title="Back" onclick="handleWorkspaceBack(event, '<?php echo e($backUrl); ?>')">
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

                <?php if ($accessMode === 'public'): ?>
                    <span class="workspace-badge" style="background:var(--info-blue-bg); color:var(--info-blue); border:1px solid var(--info-blue-line);" title="Public Document (Anyone can contribute)">Public</span>
                <?php endif; ?>

                <?php if ($isHistoricalSnapshot): ?>
                    <span class="workspace-badge" style="background:var(--amber-soft); color:var(--amber-ink); border:1px solid var(--amber-line);" title="Viewing historical version snapshot">Snapshot: <?php echo e($activeVersionName); ?></span>
                    <a href="view.php?id=<?php echo $docId; ?>" class="btn-workspace-action" style="padding: 2px 8px; font-size: 11px; height: 24px;" title="Switch to latest active revision">Latest (<?php echo e($currentVersion); ?>)</a>
                <?php else: ?>
                    <button type="button" class="workspace-badge badge-version" id="btnBadgeVersion" title="View Revision Lineage" style="cursor:pointer; background: var(--green-tint-surface); border: 1px dashed var(--green-tint-border); display: inline-flex; align-items: center; gap: 4px;">
                        <span><?php echo e($doc['version'] ?? 'v1.0'); ?></span>
                        <?php if ($activeReviewStatus === 'rejected'): ?>
                            <span style="color:var(--red); font-weight:700;">(REJECTED)</span>
                        <?php elseif ($activeReviewStatus === 'approved' || $doc['status'] === 'published'): ?>
                            <span style="color:var(--green); font-weight:700;">(APPROVED)</span>
                        <?php elseif ($doc['status'] === 'hold'): ?>
                            <span style="color:var(--amber-ink); font-weight:700;">(HOLD)</span>
                        <?php endif; ?>
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

            <?php if ($canManageAccess): ?>
                <!-- Document Contributor Access & Sharing -->
                <button type="button" class="btn-workspace-action" id="btnOpenDocAccessModal" title="Manage Document Contributors &amp; Access Mode" style="color: var(--ink);">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                    <span>Access</span>
                </button>
            <?php endif; ?>

            <?php if ($canSubmitReview): ?>
                <!-- Submit for Review Trigger -->
                <button type="button" class="btn-workspace-action" id="btnOpenSubmitReviewModal" title="Submit document for review &amp; formal approval" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.4); color: var(--ink);">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 11 17 15 13"></polyline></svg>
                    <span>Submit Review</span>
                </button>
            <?php elseif ($isDocHold): ?>
                <?php if ($canActOnReview): ?>
                    <!-- Finish Review Trigger (Replaces Submit Review for Approvers) -->
                    <button type="button" class="btn-workspace-action is-active" id="btnOpenFinishReviewModal" title="Finish Review &amp; Record Verdict" style="background: var(--green); border-color: var(--green); color: #fff; font-weight: 600;">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>Finish Review</span>
                    </button>
                <?php endif; ?>
                <?php if ($canRetractReview): ?>
                    <!-- Cancel Submission Trigger for Author / Submitter -->
                    <button type="button" class="btn-workspace-action" id="btnRetractReviewSubmission" title="Retract review submission and return to draft" style="color: var(--red); border-color: var(--red-line); background: var(--surface);">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Cancel Submission</span>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

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
                    <button type="button" class="color-swatch is-selected" data-color="#16a34a" style="--swatch-color: var(--green);" title="Emerald Green"></button>
                    <button type="button" class="color-swatch" data-color="#f59e0b" style="--swatch-color: var(--amber);" title="Amber Warning"></button>
                    <button type="button" class="color-swatch" data-color="#dc2626" style="--swatch-color: var(--red);" title="Crimson Red"></button>
                    <button type="button" class="color-swatch" data-color="#2563eb" style="--swatch-color: var(--info-blue);" title="Sky Blue"></button>
                    <button type="button" class="color-swatch" data-color="#7c3aed" style="--swatch-color: var(--cad-purple);" title="Purple"></button>
                    <button type="button" class="color-swatch" data-color="#0f172a" style="--swatch-color: var(--ink);" title="Ink Dark"></button>
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
                <!-- Poppable Arrow Toggle Button on Drawer Left Edge -->
                <button type="button" class="drawer-edge-toggle" id="btnToggleRightDrawerEdge" title="Toggle Side Panel">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>

                <div class="drawer-header" style="padding: 10px 14px; gap: 8px;">
                    <div class="pill-tabs" style="display: flex; flex: 1; justify-content: stretch;">
                        <button type="button" class="pill-tab active" id="tabBtnAnnotations" style="flex: 1; justify-content: center; border: none; cursor: pointer;">
                            <?php echo render_icon('layers'); ?>
                            <span>Annotations</span>
                        </button>
                        <button type="button" class="pill-tab" id="tabBtnVersions" style="flex: 1; justify-content: center; border: none; cursor: pointer;">
                            <?php echo render_icon('history'); ?>
                            <span>Versions</span>
                        </button>
                    </div>
                </div>

                <div class="drawer-body">
                    
                    <!-- 1. Annotations Tab Content -->
                    <div id="drawerTabAnnotations" class="drawer-tab-pane">
                        <div class="layer-section-header-row">
                            <div class="layer-section-title" style="margin-bottom: 0;">Contributors</div>
                            <?php if ($canAnnotate && !$isHistoricalSnapshot && !$isArchived): ?>
                            <button type="button" class="btn-add-layer" id="btnAddLayerGroup" title="Add New Annotation Group">
                                <?php echo render_icon('plus'); ?>
                                <span>New Annotation</span>
                            </button>
                            <?php endif; ?>
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
                        <div class="history-accordion-header is-open" id="btnToggleVersionHistoryDropdown" title="Toggle Version History" style="margin-top: 0; margin-bottom: 10px; cursor: pointer;">
                            <div class="history-accordion-title">
                                <span class="history-accordion-arrow" id="versionHistoryAccordionArrow">
                                    <?php echo render_icon('chevron-right'); ?>
                                </span>
                                <span>Version History</span>
                            </div>
                            <?php if ($isDocHold): ?>
                                <!-- leave blank -->
                            <?php elseif ($canUploadRevision && !$isHistoricalSnapshot && !$isArchived): ?>
                                <button type="button" class="btn btn-primary" id="btnOpenUploadRevisionModal" style="padding: 4px 10px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                    <?php echo render_icon('plus'); ?>
                                    <span>Upload Revision</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Version Timeline Feed -->
                        <div id="versionTimelineContainer" class="annotation-history-feed" style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="text-align: center; padding: 20px; color: var(--slate-muted); font-size: 13px;">
                                Loading version history...
                            </div>
                        </div>

                        <!-- Audit History Dropdown Accordion -->
                        <div class="history-accordion-header" id="btnToggleAuditHistoryDropdown" title="Toggle Audit History" style="margin-top: 20px; cursor: pointer;">
                            <div class="history-accordion-title">
                                <span class="history-accordion-arrow" id="auditHistoryAccordionArrow">
                                    <?php echo render_icon('chevron-right'); ?>
                                </span>
                                <span>Audit History</span>
                            </div>
                        </div>
                        <div class="annotation-history-feed is-collapsed" id="auditHistoryFeed">
                            <div class="history-empty-state" id="auditHistoryEmptyState">
                                <span>No audit events recorded yet.</span>
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
                    <a href="<?php echo e($backUrl); ?>" class="btn-unsupported-back" onclick="handleWorkspaceBack(event, '<?php echo e($backUrl); ?>')">
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

<!-- 3. Modal: Submit for Review & Approval (Google Share Style) -->
<div class="edms-modal-backdrop" id="modalSubmitReview">
    <div class="edms-modal-dialog" style="max-width: 520px;">
        <div class="edms-modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="9 15 11 17 15 13"></polyline></svg>
                <span>Submit for Review</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseSubmitReviewModal" title="Close modal">&times;</button>
        </div>
        <form id="formSubmitReview">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body" style="padding: 18px 20px;">
                
                <!-- Google Style Search Input -->
                <div style="position: relative; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; background: var(--surface); box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <span style="color: var(--muted); display: flex; align-items: center;"><?php echo render_icon('search'); ?></span>
                        <input type="text" id="searchReviewerInput" placeholder="Add people or search reviewers..." style="border: none; background: transparent; outline: none; width: 100%; font-size: 13px; color: var(--ink);" autocomplete="off">
                    </div>
                </div>

                <!-- Reviewers List (Google Drive style 'People with access') -->
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px;">
                        People with review access
                    </div>
                    
                    <div id="reviewersListContainer" style="max-height: 190px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; border: 1px solid var(--border); border-radius: 8px; padding: 6px; background: var(--surface);">
                        <?php if (empty($eligibleReviewers)): ?>
                            <div style="font-size: 12px; color: var(--muted); padding: 14px; text-align: center;">
                                No eligible reviewers found (requires Admin or Engineer role).
                            </div>
                        <?php else: ?>
                            <?php foreach ($eligibleReviewers as $r): 
                                $rName = !empty($r['full_name']) ? $r['full_name'] : $r['username'];
                                $rInitials = get_initials($rName);
                                $rAvatar = !empty($r['avatar']) && is_file(__DIR__ . '/../' . $r['avatar']) ? BASE_URL . '/' . e($r['avatar']) : null;
                            ?>
                                <label class="reviewer-item-row" data-name="<?php echo e(mb_strtolower($rName)); ?>" data-username="<?php echo e(mb_strtolower($r['username'])); ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 7px 10px; border-radius: 6px; cursor: pointer; transition: background 0.12s ease;" onmouseover="this.style.background='var(--surface-light)'" onmouseout="this.style.background='transparent'">
                                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                        <!-- Avatar Circle -->
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; overflow: hidden;">
                                            <?php if ($rAvatar): ?>
                                                <img src="<?php echo $rAvatar; ?>" alt="<?php echo e($rName); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <span><?php echo e($rInitials); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <div style="font-size: 13px; font-weight: 600; color: var(--ink); line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo e($rName); ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--muted); line-height: 1.2;">
                                                @<?php echo e($r['username']); ?> &bull; <?php echo e($r['department'] ?? 'Engineering'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                        <span class="workspace-badge" style="font-size: 10.5px; background: var(--slate-soft); color: var(--slate-ink);">
                                            <?php echo ucfirst(e($r['role'])); ?>
                                        </span>
                                        <input type="checkbox" name="reviewer_ids[]" value="<?php echo $r['id']; ?>" style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--green);">
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Message / Notes Input -->
                <div class="form-group" style="margin-bottom: 0;">
                    <textarea id="reviewSubmissionNotes" name="notes" rows="2" class="form-input" placeholder="Notify reviewers (optional message)..." style="font-size: 12.5px; resize: vertical; border-radius: 6px;"></textarea>
                </div>
            </div>

            <div class="edms-modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="btnCancelSubmitReview" style="padding: 6px 14px; font-size: 12.5px;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitReviewAction" style="padding: 6px 16px; font-size: 12.5px;">
                    <span>Submit</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Modal: Finish Review & Submit Verdict -->
<div class="edms-modal-backdrop" id="modalReviewVerdict">
    <div class="edms-modal-dialog" style="max-width: 480px;">
        <div class="edms-modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>Finish Document Review</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseVerdictModal" title="Close modal">&times;</button>
        </div>
        <form id="formReviewVerdict">
            <input type="hidden" name="action" value="submit_verdict">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">
            <input type="hidden" name="verdict" id="verdictTypeValue" value="approved">

            <div class="edms-modal-body" style="padding: 18px 20px;">
                <!-- Specimen Info Strip -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--ink);"><?php echo e($doc['name']); ?></div>
                        <div style="font-size: 11px; color: var(--muted);">Version <?php echo e($doc['version'] ?? 'v1.0'); ?> &bull; Sign-Offs: <?php echo $approvedCount; ?>/<?php echo max(1, $allReviewersCount); ?> Approved</div>
                    </div>
                    <span class="workspace-badge" style="background: var(--amber-soft); color: var(--amber-ink); border: 1px solid var(--amber-line);">
                        Hold
                    </span>
                </div>

                <!-- Feedback Comments -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="verdictComments" class="form-label" style="font-size: 12px; font-weight: 600;">Remarks / Notes (Optional)</label>
                    <textarea id="verdictComments" name="comments" rows="3" class="form-input" placeholder="Add optional remarks or notes..." style="font-size: 12.5px; resize: vertical; border-radius: 6px;"></textarea>
                    <span class="form-help" style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Visual canvas annotations made during review remain attached to this version.</span>
                </div>
            </div>

            <div class="edms-modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="btnCancelVerdict" style="padding: 6px 14px; font-size: 12.5px;">Cancel</button>
                <button type="button" class="btn btn-secondary" id="btnRejectVerdict" style="padding: 6px 14px; font-size: 12.5px; color: var(--red); border-color: var(--red-line); background: var(--surface); font-weight: 600;">
                    ✖ Reject
                </button>
                <button type="button" class="btn btn-primary" id="btnApproveVerdict" style="padding: 6px 16px; font-size: 12.5px; background: var(--green); border-color: var(--green); font-weight: 600;">
                    ✔ Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 5. Modal: Document Access & Contributors (Google Drive Share Style) -->
<div class="edms-modal-backdrop" id="modalDocumentAccess">
    <div class="edms-modal-dialog" style="max-width: 520px;">
        <div class="edms-modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                <span>Document Access &amp; Contributors</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseAccessModal" title="Close modal">&times;</button>
        </div>
        <form id="formDocumentAccess">
            <input type="hidden" name="action" value="save_access_data">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body" style="padding: 18px 20px;">
                
                <!-- Access Mode Segmented Switch -->
                <div style="margin-bottom: 18px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px;">
                        General Access
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface);">
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="radio" name="access_mode" value="restricted" id="modeRestricted" <?php echo $accessMode === 'restricted' ? 'checked' : ''; ?> style="margin-top: 3px; accent-color: var(--green);">
                            <div>
                                <div style="font-size: 13px; font-weight: 600; color: var(--ink);">Restricted</div>
                                <div style="font-size: 11.5px; color: var(--muted); line-height: 1.3;">Only the Document Owner and invited Contributors can annotate, update versions, and submit reviews.</div>
                            </div>
                        </label>
                        <div style="height: 1px; background: var(--border);"></div>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="radio" name="access_mode" value="public" id="modePublic" <?php echo $accessMode === 'public' ? 'checked' : ''; ?> style="margin-top: 3px; accent-color: var(--green);">
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 13px; font-weight: 600; color: var(--ink);">Public (Anyone can Edit)</span>
                                </div>
                                <div style="font-size: 11.5px; color: var(--muted); line-height: 1.3;">All authenticated users can annotate, upload revisions, and submit for review.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Add Contributor Input -->
                <div id="contributorsManagementSection" style="<?php echo $accessMode === 'public' ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px;">
                        Manage Specific Contributors
                    </div>
                    
                    <div style="position: relative; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; background: var(--surface);">
                            <span style="color: var(--muted); display: flex; align-items: center;"><?php echo render_icon('search'); ?></span>
                            <input type="text" id="searchContributorInput" placeholder="Search people to add as contributors..." style="border: none; background: transparent; outline: none; width: 100%; font-size: 13px; color: var(--ink);" autocomplete="off">
                        </div>
                    </div>

                    <!-- Contributors List Container -->
                    <div id="contributorsListContainer" style="max-height: 170px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; border: 1px solid var(--border); border-radius: 8px; padding: 6px; background: var(--surface);">
                        <div style="text-align: center; padding: 12px; color: var(--muted); font-size: 12px;">Loading contributors...</div>
                    </div>
                </div>

            </div>

            <div class="edms-modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="btnCancelAccessModal" style="padding: 6px 14px; font-size: 12.5px;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveAccessSettings" style="padding: 6px 18px; font-size: 12.5px; background: var(--green); border-color: var(--green);">
                    <span>Save Changes</span>
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
    docStatus: <?php echo json_encode($doc['status']); ?>,
    accessMode: <?php echo json_encode($accessMode); ?>,
    isDocHold: <?php echo $isDocHold ? 'true' : 'false'; ?>,
    isDocPublished: <?php echo $isDocPublished ? 'true' : 'false'; ?>,
    isOwner: <?php echo $isOwner ? 'true' : 'false'; ?>,
    isContributor: <?php echo $isContributor ? 'true' : 'false'; ?>,
    canAnnotate: <?php echo $canAnnotate ? 'true' : 'false'; ?>,
    canUploadRevision: <?php echo $canUploadRevision ? 'true' : 'false'; ?>,
    canSubmitReview: <?php echo $canSubmitReview ? 'true' : 'false'; ?>,
    canManageAccess: <?php echo $canManageAccess ? 'true' : 'false'; ?>,
    hasLibreOffice: <?php echo $hasLibreOffice ? 'true' : 'false'; ?>,
    isSupportedViewer: <?php echo $isSupportedViewer ? 'true' : 'false'; ?>,
    streamUrl: <?php echo json_encode($streamUrl); ?>,
    downloadUrl: <?php echo json_encode($downloadUrl); ?>,
    reviewerUserIds: <?php echo json_encode($reviewerUserIds); ?>,
    currentUserId: <?php echo (int)$currentUser['id']; ?>,
    currentUserName: <?php echo json_encode($currentUser['full_name']); ?>,
    currentUserAvatar: <?php echo json_encode($currentUser['avatar'] ?? null); ?>,
    activeVersionId: <?php echo (int)$activeVersionId; ?>,
    activeVersionName: <?php echo json_encode($activeVersionName); ?>,
    isHistoricalSnapshot: <?php echo $isHistoricalSnapshot ? 'true' : 'false'; ?>
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
