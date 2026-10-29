<?php
/**
 * EDMS Document Viewer AJAX Persistence API
 * Handles: get_audit_history, get_annotations, create_layer, save_stroke, delete_stroke, delete_layer_group, rename_layer.
 * Extracted from pages/view.php for clean separation of API and UI layers.
 */

$action = $_REQUEST['action'] ?? null;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
    || isset($_GET['action']);

if ($action) {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired).']);
        exit;
    }

    // Viewers are barred from modifying annotations or layers unless explicitly permitted (e.g. assigned as reviewer)
    if (!$canAnnotate && !can_edit_dms($currentUser) && in_array($action, ['create_layer', 'save_stroke', 'delete_stroke', 'delete_layer_group', 'rename_layer'], true)) {
        log_activity('auth.access_denied', 'document', $docId, [
            'attempted_action' => $action,
            'reason'           => 'Read-only viewer attempted annotation modification'
        ]);
        echo json_encode(['success' => false, 'message' => 'Access denied: Viewers have read-only permissions and cannot create or modify annotations.']);
        exit;
    }

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

        if (!empty($data['is_first_session_stroke'])) {
            log_activity('document.annotate', 'document', $docId, [
                'version_id' => $ajaxVersionId,
                'details'    => 'Annotated document during review session'
            ]);
        }

        $page = isset($data['page']) ? (int)$data['page'] : 1;
        $tool = trim($data['tool'] ?? 'pen');
        $color = trim($data['color'] ?? '#16a34a');
        $targetLayerId = isset($data['layerId']) && is_numeric($data['layerId']) ? (int)$data['layerId'] : 0;

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

        $layerId = 0;
        if ($targetLayerId > 0) {
            $checkLayer = $pdo->prepare("SELECT id FROM dms_annotation_layers WHERE id = :lid AND document_id = :doc_id AND user_id = :uid");
            $checkLayer->execute(['lid' => $targetLayerId, 'doc_id' => $docId, 'uid' => $userId]);
            if ($chk = $checkLayer->fetch(PDO::FETCH_ASSOC)) {
                $layerId = (int)$chk['id'];
            }
        }

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
        $pdo->prepare("UPDATE dms_annotation_layers SET color = :color WHERE id = :lid AND (SELECT COUNT(*) FROM dms_annotations WHERE layer_id = :lid_sub) = 1")
            ->execute(['color' => $color, 'lid' => $layerId, 'lid_sub' => $layerId]);

        $rawText = trim($data['content'] ?? ($data['text'] ?? ''));
        $textSnippet = mb_strlen($rawText) > 35 ? mb_substr($rawText, 0, 32) . '...' : $rawText;
        $createDetails = ($tool === 'text')
            ? ('Added comment: "' . $textSnippet . '"')
            : (($tool === 'highlighter')
                ? 'Highlighted section'
                : (($tool === 'stamp' || $tool === 'image')
                    ? 'Placed stamp / signature'
                    : (in_array($tool, ['rect', 'circle', 'arrow', 'line'], true)
                        ? ('Added ' . $tool . ' shape')
                        : 'Drawn freehand')));

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

        if (!empty($data['is_first_session_stroke'])) {
            log_activity('document.annotate', 'document', $docId, [
                'version_id' => $ajaxVersionId,
                'details'    => 'Annotated document during review session'
            ]);
        }

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

        $findL = $pdo->prepare("SELECT layer_name FROM dms_annotation_layers WHERE id = :id AND document_id = :doc_id AND user_id = :uid");
        $findL->execute(['id' => $reqLayerId, 'doc_id' => $docId, 'uid' => $userId]);
        $targetLayer = $findL->fetch(PDO::FETCH_ASSOC);

        if (!$targetLayer) {
            echo json_encode(['success' => false, 'message' => 'Layer group not found or unauthorized.']);
            exit;
        }

        $groupName = $targetLayer['layer_name'];

        $delAnno = $pdo->prepare("DELETE FROM dms_annotations WHERE layer_id = :lid AND document_id = :doc_id");
        $delAnno->execute(['lid' => $reqLayerId, 'doc_id' => $docId]);

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

        if (mb_strlen($newName) > 60) {
            $newName = mb_substr($newName, 0, 60);
        }

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

        if ($oldName !== $newName) {
            $logStmt = $pdo->prepare("
                INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details)
                VALUES (:doc_id, :ver_id, :user_id, 'update', NULL, 1, :details)
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
