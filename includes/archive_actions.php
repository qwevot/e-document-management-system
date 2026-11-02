<?php
/**
 * EDMS Archive Action Handlers (Restore, Batch Restore, Purge, Batch Purge)
 * Extracted from pages/archives.php for modular clarity and zero duplication.
 */

/**
 * Permanently unlink physical binaries and delete node records (cascading annotations & versions).
 */
function purge_archived_nodes(PDO $pdo, array $rootNodeIds): array
{
    $nodesToPurge = get_descendant_node_ids($pdo, $rootNodeIds, false);
    if (empty($nodesToPurge)) {
        return ['purged_ids' => [], 'files_unlinked' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($nodesToPurge), '?'));

    // Collect physical file paths for both nodes and historical version snapshots
    $fStmt = $pdo->prepare("SELECT file_path FROM dms_nodes WHERE id IN ($placeholders) AND file_path IS NOT NULL");
    $fStmt->execute($nodesToPurge);
    $nodeFiles = $fStmt->fetchAll(PDO::FETCH_COLUMN);

    $vStmt = $pdo->prepare("SELECT file_path FROM dms_document_versions WHERE document_id IN ($placeholders) AND file_path IS NOT NULL");
    $vStmt->execute($nodesToPurge);
    $versionFiles = $vStmt->fetchAll(PDO::FETCH_COLUMN);

    $allFiles = array_unique(array_filter(array_merge($nodeFiles, $versionFiles)));
    foreach ($allFiles as $filePath) {
        $physicalPath = __DIR__ . '/../' . ltrim($filePath, '/\\');
        if (file_exists($physicalPath) && is_file($physicalPath)) {
            @unlink($physicalPath);
        }
    }

    // Foreign keys with ON DELETE CASCADE automatically clean up versions, layers, annotations, and history
    $pdo->prepare("DELETE FROM dms_nodes WHERE id IN ($placeholders)")->execute($nodesToPurge);

    return [
        'purged_ids'     => $nodesToPurge,
        'files_unlinked' => count($allFiles),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired). Please refresh and try again.']);
            exit;
        }
        redirect_to('archives', 'error', 'Security validation failed. Please try again.');
    }
    $action = $_POST['action'] ?? '';

    // Disallow mutating operations if current user does not have edit permissions
    if (!can_edit_dms() && in_array($action, ['restore_node', 'batch_restore', 'purge_node', 'batch_purge'], true)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied: Viewers have read-only permissions and cannot restore or alter archived items.']);
            exit;
        }
        redirect_to('archives', 'error', 'Access denied: Viewers have read-only permissions.');
    }

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
            $allAffectedIds = get_descendant_node_ids($pdo, [$nodeId], false, true);
            $conflictName = null;
            foreach ($pdo->query("SELECT id, name, parent_id FROM dms_nodes WHERE id IN (" . implode(',', $allAffectedIds) . ") AND is_deleted = 1")->fetchAll(PDO::FETCH_ASSOC) as $rNode) {
                if (is_node_duplicate($pdo, $rNode['parent_id'] !== null ? (int)$rNode['parent_id'] : null, $rNode['name'], (int)$rNode['id'])) {
                    $conflictName = $rNode['name'];
                    break;
                }
            }

            if ($conflictName !== null) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'   => false,
                        'message'   => "Cannot restore: An active item named \"{$conflictName}\" already exists in the destination folder.",
                        'duplicate' => true
                    ]);
                    exit;
                }
                redirect_to('archives', 'error', "An item named '{$conflictName}' already exists in that folder.");
            }

            $placeholders = implode(',', array_fill(0, count($allAffectedIds), '?'));
            $pdo->prepare("UPDATE dms_nodes SET is_deleted = 0, status = 'active', updated_at = NOW() WHERE id IN ($placeholders) AND is_deleted = 1")->execute($allAffectedIds);

            log_activity('node.restore', 'node', $nodeId, [
                'name'              => $name,
                'parent_id'         => $parentId,
                'affected_node_ids' => $allAffectedIds
            ]);

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

        $allAffectedIds = get_descendant_node_ids($pdo, $nodeIds, false, true);
        $movingItems = $allAffectedIds ? $pdo->query("SELECT id, name, parent_id FROM dms_nodes WHERE id IN (" . implode(',', $allAffectedIds) . ") AND is_deleted = 1")->fetchAll(PDO::FETCH_ASSOC) : [];

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

        if (!empty($allAffectedIds)) {
            $descPlaceholders = implode(',', array_fill(0, count($allAffectedIds), '?'));
            $pdo->prepare("UPDATE dms_nodes SET is_deleted = 0, status = 'active', updated_at = NOW() WHERE id IN ($descPlaceholders) AND is_deleted = 1")->execute($allAffectedIds);

            log_activity('node.batch_restore', 'node', null, [
                'selected_node_ids' => $nodeIds,
                'affected_node_ids' => $allAffectedIds,
                'count'             => count($nodeIds)
            ]);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) restored successfully.'
        ]);
        exit;
    }

    // 2. Permanently Delete Item (Hard delete from database & disk) - Super Admin only
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

            $purgeRes = purge_archived_nodes($pdo, [$nodeId]);
            if (!empty($purgeRes['purged_ids'])) {
                log_activity('node.purge', $nodeData['node_type'] ?? 'document', $nodeId, [
                    'name'            => $nodeData['name'] ?? '',
                    'purged_node_ids' => $purgeRes['purged_ids'],
                    'files_unlinked'  => $purgeRes['files_unlinked']
                ]);
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Item permanently deleted.']);
                exit;
            }
            redirect_to('archives', 'success', 'Item permanently purged from database.');
        }
    }

    // 2B. Batch Permanently Delete Items - Super Admin only
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

        $purgeRes = purge_archived_nodes($pdo, $nodeIds);
        if (!empty($purgeRes['purged_ids'])) {
            log_activity('node.batch_purge', 'node', null, [
                'selected_node_ids' => $nodeIds,
                'purged_node_ids'   => $purgeRes['purged_ids'],
                'files_unlinked'    => $purgeRes['files_unlinked']
            ]);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => count($nodeIds) . ' item(s) permanently deleted.'
        ]);
        exit;
    }
}
