<?php
/**
 * EDMS Explorer Action Controller
 * Handles AJAX actions: audit history, folder creation, renaming, move, batch move, batch archive, deletion, and document upload.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/contributors.php';
$pdo = db();

/**
 * Check if candidate folder is equal to or a descendant of ancestor folder (prevents circular loops)
 */
function is_folder_descendant(int $candidateDescendantId, int $ancestorId, PDO $pdo): bool {
    if ($candidateDescendantId <= 0 || $ancestorId <= 0) return false;
    if ($candidateDescendantId === $ancestorId) return true;
    $stmt = $pdo->prepare("SELECT parent_id FROM dms_nodes WHERE id = ? AND node_type = 'folder'");
    $visited = [];
    for ($curr = $candidateDescendantId; $curr && !isset($visited[$curr]); ) {
        $visited[$curr] = true;
        $stmt->execute([$curr]);
        $parentId = (int)($stmt->fetchColumn() ?: 0);
        if ($parentId === $ancestorId) return true;
        $curr = $parentId;
    }
    return false;
}
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') ||
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

// --- AJAX Endpoints ---
$reqAction = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($reqAction === 'get_audit_history') {
    require_login();
    header('Content-Type: application/json');
    $docId = isset($_REQUEST['doc_id']) ? (int)$_REQUEST['doc_id'] : (isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0);
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.', 'events' => []]);
        exit;
    }
    echo json_encode(array_merge(['success' => true], get_document_audit_trail($docId)));
    exit;
}

// ============================================================
// POST Actions: Create Folder
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_login();
    if (!csrf_valid()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired). Please refresh and try again.']);
            exit;
        }
        redirect_to('explorer', 'error', 'Security validation failed. Please try again.');
    }
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Content-Type: application/json');
        $serverMax = ini_get('post_max_size') ?: 'server limit';
        echo json_encode(['success' => false, 'message' => "Upload failed: file size exceeds the server limit ({$serverMax})."]);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_star') {
        header('Content-Type: application/json');
        $uid = (int)(current_user()['id'] ?? 0);
        $nid = (int)($_POST['id'] ?? 0);
        if ($uid <= 0 || $nid <= 0) { echo json_encode(['success' => false]); exit; }
        $del = $pdo->prepare("DELETE FROM dms_starred_nodes WHERE user_id = ? AND node_id = ?");
        $del->execute([$uid, $nid]);
        if ($del->rowCount() === 0) {
            $pdo->prepare("INSERT IGNORE INTO dms_starred_nodes (user_id, node_id) VALUES (?, ?)")->execute([$uid, $nid]);
            log_activity('node.star', 'document', $nid);
            echo json_encode(['success' => true, 'starred' => true]);
        } else {
            log_activity('node.unstar', 'document', $nid);
            echo json_encode(['success' => true, 'starred' => false]);
        }
        exit;
    }

    // Disallow mutating operations if current user does not have edit permissions (e.g., viewers)
    if (!can_edit_dms() && in_array($action, ['create_folder', 'rename_node', 'batch_move', 'batch_archive', 'move_node', 'delete_node', 'restore_node', 'upload_document'], true)) {
        log_activity('auth.access_denied', 'node', null, [
            'attempted_action' => $action,
            'reason'           => 'Read-only viewer attempted repository modification'
        ]);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied: Viewers have read-only permissions and cannot modify or upload documents.']);
            exit;
        }
        redirect_to('explorer', 'error', 'Access denied: Viewers have read-only permissions.');
    }

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

            // Check for duplicate name under same parent
            if (is_node_duplicate($pdo, $postParentId, $folderName)) {
                log_activity('folder.create_failed', 'folder', null, [
                    'name'      => $folderName,
                    'parent_id' => $postParentId,
                    'reason'    => 'Duplicate folder name collision'
                ]);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => "A folder named \"{$folderName}\" already exists here.", 'duplicate' => true]);
                    exit;
                }
                header('Location: explorer.php?mode=project' . ($postParentId ? '&folder=' . $postParentId : ''));
                exit;
            }

            $userId = current_user()['id'] ?? 1;

            $insertStmt = $pdo->prepare("
                INSERT INTO dms_nodes (parent_id, node_type, name, status, version, is_deleted, created_by, created_at, updated_at)
                VALUES (:parent_id, 'folder', :name, 'active', 'v0.1', 0, :created_by, NOW(), NOW())
            ");
            $insertStmt->execute([
                'parent_id'  => $postParentId,
                'name'       => $folderName,
                'created_by' => $userId,
            ]);
            $newFolderId = (int)$pdo->lastInsertId();

            log_activity('folder.create', 'folder', $newFolderId, [
                'name'      => $folderName,
                'parent_id' => $postParentId
            ]);

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
            $currUid = (int)(current_user()['id'] ?? 0);
            if (!can_manage_node($nodeId, $currUid)) {
                log_activity('auth.access_denied', 'node', $nodeId, ['attempted_action' => 'rename_node']);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Access denied: Only the owner, invited contributors, or administrators can rename this item.']);
                    exit;
                }
                redirect_to('explorer', 'error', 'Access denied: You do not have permission to rename this item.');
            }
            $nodeStmt = $pdo->prepare("SELECT name, node_type, parent_id FROM dms_nodes WHERE id = :id");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeData = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$nodeData) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Item not found.']);
                    exit;
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }

            $nodeParentId = $nodeData['parent_id'] ?? null;
            $oldName = $nodeData['name'] ?? '';
            $nodeType = $nodeData['node_type'] ?? 'document';

            if (is_node_duplicate($pdo, $nodeParentId, $newName, $nodeId)) {
                log_activity('node.rename_failed', $nodeType, $nodeId, [
                    'current_name'   => $oldName,
                    'attempted_name' => $newName,
                    'reason'         => 'Item with this name already exists in destination folder'
                ]);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => "An item named \"{$newName}\" already exists in this folder.", 'duplicate' => true]);
                    exit;
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }

            $stmt = $pdo->prepare("UPDATE dms_nodes SET name = :name, updated_at = NOW() WHERE id = :id AND is_deleted = 0");
            $stmt->execute(['name' => $newName, 'id' => $nodeId]);

            log_activity('node.rename', $nodeType, $nodeId, [
                'old_name' => $oldName,
                'new_name' => $newName
            ]);

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
        $postIds = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids'])) : [];
        $nodeIds = array_filter(array_map('intval', $postIds));
        if ($nodeId > 0 && !in_array($nodeId, $nodeIds, true)) {
            $nodeIds[] = $nodeId;
        }

        $stmt = $pdo->query("SELECT id, parent_id, name FROM dms_nodes WHERE node_type = 'folder' AND is_deleted = 0 ORDER BY name ASC");
        $allFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $excludedIds = [];
        if (!empty($nodeIds)) {
            $childrenMap = [];
            $folderMap = [];
            foreach ($allFolders as $f) {
                $folderMap[(int)$f['id']] = true;
                $pid = (int)($f['parent_id'] ?? 0);
                $childrenMap[$pid][] = (int)$f['id'];
            }
            $queue = [];
            foreach ($nodeIds as $nid) {
                if (isset($folderMap[$nid]) || $nodeType === 'folder') {
                    $excludedIds[$nid] = true;
                    $queue[] = $nid;
                }
            }
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

    // 3B. Batch Move Items
    if ($action === 'batch_move') {
        $postIds = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids'])) : [];
        $nodeIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        $targetFolderId = isset($_POST['target_folder_id']) && is_numeric($_POST['target_folder_id']) && (int)$_POST['target_folder_id'] > 0
            ? (int)$_POST['target_folder_id']
            : null;

        if (empty($nodeIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No items selected for move.']);
            exit;
        }

        $currUid = (int)(current_user()['id'] ?? 0);
        foreach ($nodeIds as $nid) {
            if (!can_manage_node($nid, $currUid)) {
                log_activity('auth.access_denied', 'node', $nid, ['attempted_action' => 'batch_move', 'selected_ids' => $nodeIds]);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access denied: You do not have permission to move one or more selected items.']);
                exit;
            }
        }

        if ($targetFolderId !== null) {
            $checkTarget = $pdo->prepare("SELECT id FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
            $checkTarget->execute(['id' => $targetFolderId]);
            if (!$checkTarget->fetch()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Target destination folder does not exist.']);
                exit;
            }

            if (in_array($targetFolderId, $nodeIds, true)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Cannot move items into one of the selected folders.']);
                exit;
            }
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));

        // Retrieve items being moved and prevent circular hierarchy
        $itemsStmt = $pdo->prepare("SELECT id, name, node_type, parent_id FROM dms_nodes WHERE id IN ($placeholders) AND is_deleted = 0");
        $itemsStmt->execute($nodeIds);
        $movingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($movingItems)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No active items found to move.']);
            exit;
        }

        if ($targetFolderId !== null) {
            foreach ($movingItems as $mItem) {
                if ($mItem['node_type'] === 'folder' && is_folder_descendant($targetFolderId, (int)$mItem['id'], $pdo)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Cannot move folder "' . $mItem['name'] . '" into one of its own subfolders.']);
                    exit;
                }
            }
        }

        // Duplicate name verification in destination folder
        $conflictingNames = [];
        foreach ($movingItems as $mItem) {
            if (is_node_duplicate($pdo, $targetFolderId, $mItem['name'], (int)$mItem['id'])) {
                $conflictingNames[] = $mItem['name'];
            }
        }

        if (!empty($conflictingNames)) {
            $conflictList = implode(', ', array_map(fn($n) => "\"{$n}\"", array_unique($conflictingNames)));
            log_activity('node.move_failed', 'folder', $targetFolderId, [
                'moved_node_ids' => $nodeIds,
                'conflicts'      => array_unique($conflictingNames),
                'reason'         => 'Duplicate name collision in destination folder'
            ]);
            header('Content-Type: application/json');
            echo json_encode([
                'success'   => false,
                'message'   => "Cannot move: item(s) named {$conflictList} already exist in the destination folder.",
                'duplicate' => true
            ]);
            exit;
        }

        $updateParams = array_merge([$targetFolderId], $nodeIds);
        $stmt = $pdo->prepare("UPDATE dms_nodes SET parent_id = ?, updated_at = NOW() WHERE id IN ($placeholders) AND is_deleted = 0");
        $stmt->execute($updateParams);

        log_activity('node.batch_move', 'folder', $targetFolderId, [
            'moved_node_ids'   => $nodeIds,
            'target_folder_id' => $targetFolderId,
            'count'            => count($nodeIds)
        ]);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) moved successfully.'
        ]);
        exit;
    }

    // 3C. Batch Archive Items
    if ($action === 'batch_archive') {
        $postIds = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids'])) : [];
        $nodeIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if (empty($nodeIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No items selected for archive.']);
            exit;
        }

        $currUid = (int)(current_user()['id'] ?? 0);
        foreach ($nodeIds as $nid) {
            if (!can_manage_node($nid, $currUid)) {
                log_activity('auth.access_denied', 'node', $nid, ['attempted_action' => 'batch_archive', 'selected_ids' => $nodeIds]);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access denied: You do not have permission to archive one or more selected items.']);
                exit;
            }
        }

        // Collect all target items and their nested descendants
        $allAffectedIds = get_descendant_node_ids($pdo, $nodeIds, false);
        if (!empty($allAffectedIds)) {
            $placeholders = implode(',', array_fill(0, count($allAffectedIds), '?'));
            $stmt = $pdo->prepare("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($allAffectedIds);

            log_activity('node.batch_archive', 'node', null, [
                'selected_node_ids' => $nodeIds,
                'affected_node_ids' => $allAffectedIds,
                'count'             => count($nodeIds)
            ]);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) moved to Archives.'
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
            $currUid = (int)(current_user()['id'] ?? 0);
            if (!can_manage_node($nodeId, $currUid)) {
                log_activity('auth.access_denied', 'node', $nodeId, ['attempted_action' => 'move_node']);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Access denied: Only the owner, invited contributors, or administrators can move this item.']);
                    exit;
                }
                redirect_to('explorer', 'error', 'Access denied: You do not have permission to move this item.');
            }
            $nodeStmt = $pdo->prepare("SELECT name, node_type, parent_id FROM dms_nodes WHERE id = :id AND is_deleted = 0");
            $nodeStmt->execute(['id' => $nodeId]);
            $currentNode = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentNode) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Item not found.']);
                    exit;
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }

            if ((string)$currentNode['parent_id'] === (string)$targetFolderId) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Item is already in this location.']);
                    exit;
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }

            if ($targetFolderId !== null) {
                if ($currentNode['node_type'] === 'folder' && is_folder_descendant($targetFolderId, $nodeId, $pdo)) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Cannot move a folder into itself or one of its subfolders.']);
                        exit;
                    }
                    redirect_to('explorer', 'error', 'Cannot move a folder into itself or one of its subfolders.');
                }
                $checkTarget = $pdo->prepare("SELECT id FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
                $checkTarget->execute(['id' => $targetFolderId]);
                if (!$checkTarget->fetch()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Target destination folder does not exist.']);
                    exit;
                }
            }

            $itemName = $currentNode['name'];

            if (is_node_duplicate($pdo, $targetFolderId, $itemName, $nodeId)) {
                log_activity('node.move_failed', $currentNode['node_type'] ?? 'document', $nodeId, [
                    'name'             => $itemName,
                    'target_folder_id' => $targetFolderId,
                    'reason'           => 'Duplicate name collision in destination folder'
                ]);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'   => false,
                        'message'   => "Cannot move: An item named \"{$itemName}\" already exists in the destination folder.",
                        'duplicate' => true
                    ]);
                    exit;
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }

            $stmt = $pdo->prepare("UPDATE dms_nodes SET parent_id = :parent_id, updated_at = NOW() WHERE id = :id AND is_deleted = 0");
            $stmt->execute(['parent_id' => $targetFolderId, 'id' => $nodeId]);

            log_activity('node.move', $currentNode['node_type'] ?? 'document', $nodeId, [
                'name'          => $itemName,
                'old_parent_id' => $currentNode['parent_id'],
                'new_parent_id' => $targetFolderId
            ]);

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
            $currUid = (int)(current_user()['id'] ?? 0);
            if (!can_manage_node($nodeId, $currUid)) {
                log_activity('auth.access_denied', 'node', $nodeId, ['attempted_action' => 'delete_node']);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Access denied: Only the owner, invited contributors, or administrators can archive this item.']);
                    exit;
                }
                redirect_to('explorer', 'error', 'Access denied: You do not have permission to archive this item.');
            }
            $nodeStmt = $pdo->prepare("SELECT name, node_type FROM dms_nodes WHERE id = :id");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeData = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            // If it was a folder, recursively soft-delete all children and mark obsolete
            $allDeletedIds = get_descendant_node_ids($pdo, [$nodeId], false);
            if (!empty($allDeletedIds)) {
                $placeholders = implode(',', array_fill(0, count($allDeletedIds), '?'));
                $pdo->prepare("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id IN ($placeholders)")->execute($allDeletedIds);
            } else {
                $allDeletedIds = [$nodeId];
                $stmt = $pdo->prepare("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id = :id");
                $stmt->execute(['id' => $nodeId]);
            }

            log_activity('node.archive', $nodeData['node_type'] ?? 'document', $nodeId, [
                'name'               => $nodeData['name'] ?? '',
                'affected_node_ids'  => $allDeletedIds
            ]);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item deleted and marked obsolete.']);
                exit;
            }
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
            exit;
        }
    }

    // 5B. Restore Item (from Workspace / Archives)
    if ($action === 'restore_node') {
        $nodeId = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
        $currUid = (int)(current_user()['id'] ?? 0);
        $isAdmin = in_array(current_role(), ['super admin', 'admin'], true) ? 1 : 0;

        $nStmt = $pdo->prepare("SELECT id, name, parent_id, node_type FROM dms_nodes WHERE id = ? AND (created_by = ? OR ? = 1)");
        $nStmt->execute([$nodeId, $currUid, $isAdmin]);
        $targetNode = $nStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetNode) {
            log_activity('auth.access_denied', 'node', $nodeId ?: null, [
                'reason' => 'Item not found or unauthorized.'
            ]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Item not found or unauthorized.']);
            exit;
        }

        $restoreRes = restore_archived_nodes($pdo, [$nodeId]);
        if (!($restoreRes['success'] ?? false)) {
            $conflictName = $restoreRes['conflicts'][0] ?? $targetNode['name'];
            $errMsg = "Cannot restore: an active item named \"{$conflictName}\" already exists in the destination folder.";
            log_activity('node.restore_failed', 'node', $nodeId, [
                'conflict_name' => $conflictName,
                'reason'        => $errMsg
            ]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }

        log_activity('node.restore', $targetNode['node_type'] ?? 'document', $nodeId, [
            'name'              => $targetNode['name'],
            'affected_node_ids' => $restoreRes['affected_ids']
        ]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Document restored to active workspace.']);
        exit;
    }

    // 6. Upload Document Specimen
    if ($action === 'upload_document') {
        $docName     = trim($_POST['name'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active', 'published', 'hold'], true) ? $_POST['status'] : 'active';
        $postParentId = isset($_POST['parent_id']) && is_numeric($_POST['parent_id']) && (int)$_POST['parent_id'] > 0
            ? (int)$_POST['parent_id']
            : null;

        $rawReviewerIds = $_POST['reviewer_ids'] ?? [];
        if (!is_array($rawReviewerIds)) {
            $rawReviewerIds = explode(',', (string)$rawReviewerIds);
        }
        $cleanReviewerIds = array_values(array_unique(array_filter(array_map('intval', $rawReviewerIds))));

        if ($status === 'hold' && empty($cleanReviewerIds)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Please assign at least one reviewer when setting initial status to On Hold.']);
                exit;
            }
            $_SESSION['error'] = 'Please assign at least one reviewer when setting initial status to On Hold.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
            exit;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size limit.',
                UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE   => 'No file was selected for upload.',
                default              => 'File upload failed with error code: ' . $errorCode
            };
            if ($errorCode !== UPLOAD_ERR_NO_FILE) {
                log_activity('document.upload_failed', 'document', null, [
                    'error_code' => $errorCode,
                    'reason'     => $errMessage
                ]);
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $errMessage]);
                exit;
            }
        } else {
            $stored = store_uploaded_document_file($_FILES['file']);
            $originalName = $_FILES['file']['name'] ?? '';

            if (!($stored['success'] ?? false)) {
                log_activity('document.upload_failed', 'document', null, [
                    'filename' => $originalName,
                    'reason'   => $stored['message'] ?? 'Failed to move file to physical storage directory'
                ]);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $stored['message'] ?? 'Failed to store file in physical repository.']);
                    exit;
                }
            } else {
                $fileSize            = $stored['file_size'];
                $fileHash            = $stored['file_hash'];
                $fileExt             = $stored['file_extension'];
                $mimeType            = $stored['mime_type'];
                $destinationPath     = $stored['full_path'];
                $relativeStoragePath = $stored['file_path'];

                if ($docName === '') {
                    $docName = preg_replace('/\.(docx?|xlsx?|pptx?|pdf|odt|ods|odp|rtf|csv|txt)$/i', '', pathinfo($originalName, PATHINFO_FILENAME));
                }
                // Verify parent folder exists if specified
                if ($postParentId !== null) {
                    $chk = $pdo->prepare("SELECT id FROM dms_nodes WHERE id = :id AND is_deleted = 0 AND node_type = 'folder'");
                    $chk->execute(['id' => $postParentId]);
                    if (!$chk->fetch()) {
                        $postParentId = null;
                    }
                }

                // Check for duplicate document/folder name under the same parent_id
                if (is_node_duplicate($pdo, $postParentId, $docName)) {
                    if (file_exists($destinationPath)) {
                        @unlink($destinationPath);
                    }
                    log_activity('document.upload_failed', 'document', null, [
                        'name'      => $docName,
                        'parent_id' => $postParentId,
                        'reason'    => 'Duplicate document name collision'
                    ]);
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success'   => false,
                            'message'   => "A document named \"{$docName}\" already exists in this folder.",
                            'duplicate' => true
                        ]);
                        exit;
                    }
                    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                    exit;
                }

                $userId = current_user()['id'] ?? 1;
                $initialNodeStatus = ($status === 'published') ? 'published' : 'active';
                $initialVersionNum = ($status === 'published') ? 'v1.0' : 'v0.1';
                $initialReviewStat = ($status === 'published') ? 'approved' : 'draft';
                $initialSummary    = ($status === 'published') ? 'Initial official release.' : 'Initial document baseline upload.';

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO dms_nodes (
                            parent_id, node_type, name, status, version,
                            file_path, file_original_name, file_size, file_extension,
                            mime_type, file_hash, is_deleted, created_by,
                            created_at, updated_at
                        ) VALUES (
                            :parent_id, 'document', :name, :status, :version,
                            :file_path, :file_original_name, :file_size, :file_extension,
                            :mime_type, :file_hash, 0, :created_by,
                            NOW(), NOW()
                        )
                    ");

                    $stmt->execute([
                        'parent_id'          => $postParentId,
                        'name'               => $docName,
                        'status'             => $initialNodeStatus,
                        'version'            => $initialVersionNum,
                        'file_path'          => $relativeStoragePath,
                        'file_original_name' => $originalName,
                        'file_size'          => $fileSize,
                        'file_extension'     => $fileExt,
                        'mime_type'          => $mimeType,
                        'file_hash'          => $fileHash,
                        'created_by'         => $userId,
                    ]);

                    $newDocId = (int)$pdo->lastInsertId();

                    // Seed baseline version into dms_document_versions
                    $verStmt = $pdo->prepare("
                        INSERT INTO dms_document_versions (
                            document_id, version_number, file_path, file_original_name,
                            file_size, file_extension, mime_type, file_hash,
                            change_summary, is_current, review_status, created_by, created_at
                        ) VALUES (
                            :document_id, :version_number, :file_path, :file_original_name,
                            :file_size, :file_extension, :mime_type, :file_hash,
                            :change_summary, 1, :review_status, :created_by, NOW()
                        )
                    ");
                    $verStmt->execute([
                        'document_id'        => $newDocId,
                        'version_number'     => $initialVersionNum,
                        'file_path'          => $relativeStoragePath,
                        'file_original_name' => $originalName,
                        'file_size'          => $fileSize,
                        'file_extension'     => $fileExt,
                        'mime_type'          => $mimeType,
                        'file_hash'          => $fileHash,
                        'change_summary'     => $initialSummary,
                        'review_status'      => $initialReviewStat,
                        'created_by'         => $userId,
                    ]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (file_exists($destinationPath)) {
                        @unlink($destinationPath);
                    }
                    log_activity('document.upload_failed', 'document', null, [
                        'name'   => $docName,
                        'reason' => $e->getMessage()
                    ]);
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to register document in database.']);
                        exit;
                    }
                    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                    exit;
                }

                log_activity('document.upload', 'document', $newDocId, [
                    'name'          => $docName,
                    'original_name' => $originalName,
                    'size'          => $fileSize,
                    'extension'     => $fileExt,
                    'parent_id'     => $postParentId,
                    'status'        => $status
                ]);

                if ($status === 'hold') {
                    submit_document_for_review(
                        $newDocId,
                        $cleanReviewerIds,
                        $userId,
                        'v1.0',
                        'Initial submission upon document upload'
                    );
                }

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Document uploaded and registered successfully.',
                        'id'      => $newDocId,
                        'name'    => $docName
                    ]);
                    exit;
                }

                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'explorer.php'));
                exit;
            }
        }
    }
}