<?php
/**
 * EDMS Explorer Action Controller
 * Handles AJAX actions: audit history, folder creation, renaming, move, batch move, batch archive, deletion, and document upload.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
$pdo = db();
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') ||
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// --- AJAX Endpoints ---
$reqAction = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($reqAction === 'get_audit_history') {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Content-Type: application/json');
        $serverMax = ini_get('post_max_size') ?: 'server limit';
        echo json_encode(['success' => false, 'message' => "Upload failed: file size exceeds the server limit ({$serverMax})."]);
        exit;
    }
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

            // Check for duplicate name under same parent
            if (is_node_duplicate($pdo, $postParentId, $folderName)) {
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
                INSERT INTO dms_nodes (parent_id, node_type, name, status, version, is_locked, is_deleted, created_by, created_at, updated_at)
                VALUES (:parent_id, 'folder', :name, 'active', 'v0.1', 0, 0, :created_by, NOW(), NOW())
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
            ], 'success');

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
            $nodeStmt = $pdo->prepare("SELECT name, node_type, parent_id FROM dms_nodes WHERE id = :id");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeData = $nodeStmt->fetch(PDO::FETCH_ASSOC);
            $nodeParentId = $nodeData['parent_id'] ?? null;
            $oldName = $nodeData['name'] ?? '';
            $nodeType = $nodeData['node_type'] ?? 'document';

            if (is_node_duplicate($pdo, $nodeParentId, $newName, $nodeId)) {
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
            ], 'success');

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

        // Retrieve names and current parent_ids of the items being moved
        $itemsStmt = $pdo->prepare("SELECT id, name, parent_id FROM dms_nodes WHERE id IN ($placeholders) AND is_deleted = 0");
        $itemsStmt->execute($nodeIds);
        $movingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($movingItems)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No active items found to move.']);
            exit;
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
        ], 'success');

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
            ], 'success');
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

            $itemName = $currentNode['name'];

            if (is_node_duplicate($pdo, $targetFolderId, $itemName, $nodeId)) {
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
            ], 'success');

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
            $nodeStmt = $pdo->prepare("SELECT name, node_type FROM dms_nodes WHERE id = :id");
            $nodeStmt->execute(['id' => $nodeId]);
            $nodeData = $nodeStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE dms_nodes SET is_deleted = 1, status = 'obsolete', updated_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $nodeId]);

            // If it was a folder, recursively soft-delete all children and mark obsolete
            $queue = [$nodeId];
            $allDeletedIds = [$nodeId];
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
                        $allDeletedIds[] = (int)$cid;
                    }
                }
            }

            log_activity('node.archive', $nodeData['node_type'] ?? 'document', $nodeId, [
                'name'               => $nodeData['name'] ?? '',
                'affected_node_ids'  => $allDeletedIds
            ], 'success');

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
        $docName     = trim($_POST['name'] ?? '');
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

                // Check for duplicate document/folder name under the same parent_id
                if (is_node_duplicate($pdo, $postParentId, $docName)) {
                    if (file_exists($destinationPath)) {
                        @unlink($destinationPath);
                    }
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

                $stmt = $pdo->prepare("
                    INSERT INTO dms_nodes (
                        parent_id, node_type, name, status, version,
                        file_path, file_original_name, file_size, file_extension,
                        mime_type, file_hash, is_locked, is_deleted, created_by,
                        created_at, updated_at
                    ) VALUES (
                        :parent_id, 'document', :name, :status, 'v0.1',
                        :file_path, :file_original_name, :file_size, :file_extension,
                        :mime_type, :file_hash, 0, 0, :created_by,
                        NOW(), NOW()
                    )
                ");

                $stmt->execute([
                    'parent_id'          => $postParentId,
                    'name'               => $docName,
                    'status'             => $status,
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
                try {
                    $verStmt = $pdo->prepare("
                        INSERT INTO dms_document_versions (
                            document_id, version_number, file_path, file_original_name,
                            file_size, file_extension, mime_type, file_hash,
                            change_summary, is_current, created_by, created_at
                        ) VALUES (
                            :document_id, 'v0.1', :file_path, :file_original_name,
                            :file_size, :file_extension, :mime_type, :file_hash,
                            'Initial document baseline upload.', 1, :created_by, NOW()
                        )
                    ");
                    $verStmt->execute([
                        'document_id'        => $newDocId,
                        'file_path'          => $relativeStoragePath,
                        'file_original_name' => $originalName,
                        'file_size'          => $fileSize,
                        'file_extension'     => $fileExt,
                        'mime_type'          => $mimeType,
                        'file_hash'          => $fileHash,
                        'created_by'         => $userId,
                    ]);
                } catch (Throwable $e) {
                    // Graceful fallback if table does not exist yet
                }

                log_activity('document.upload', 'document', $newDocId, [
                    'name'          => $docName,
                    'original_name' => $originalName,
                    'size'          => $fileSize,
                    'extension'     => $fileExt,
                    'parent_id'     => $postParentId,
                    'status'        => $status
                ], 'success');

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