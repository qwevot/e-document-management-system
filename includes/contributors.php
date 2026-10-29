<?php
// Document Contributor & Access Control Engine
// Governs Public/Restricted access modes and Contributor memberships

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Retrieve all explicit contributors for a document.
 */
function get_document_contributors(int $docId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT c.id AS contributor_entry_id, c.created_at AS added_at,
            u.id AS user_id, u.username, u.full_name, u.role, u.department, u.avatar
        FROM dms_document_contributors c
        JOIN users u ON c.user_id = u.id
        WHERE c.document_id = :doc_id
        ORDER BY u.full_name ASC
    ");
    $stmt->execute(['doc_id' => $docId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if a user has Contributor (edit) rights on a document.
 */
function is_document_contributor(int $docId, int $userId): bool
{
    $pdo = db();

    // 1. Fetch document meta
    $stmt = $pdo->prepare("SELECT id, created_by, status, access_mode FROM dms_nodes WHERE id = :id AND node_type = 'document' AND is_deleted = 0");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return false;
    }

    // 2. Viewers are strictly read-only and cannot have contributor (edit) rights
    $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $uStmt->execute(['id' => $userId]);
    $uRole = strtolower(trim($uStmt->fetchColumn() ?: 'viewer'));
    if ($uRole === 'viewer' || $uRole === 'guest') {
        return false;
    }

    // 3. Owner always has contributor rights
    if ((int)$doc['created_by'] === $userId) {
        return true;
    }

    // 4. Super Admins & Admins always have contributor rights
    if (in_array($uRole, ['super admin', 'admin'], true)) {
        return true;
    }

    // 5. Public access mode grants non-viewer authenticated users contributor rights
    if (($doc['access_mode'] ?? 'restricted') === 'public') {
        return true;
    }

    // 5. Check explicit invitation in dms_document_contributors
    $cStmt = $pdo->prepare("SELECT 1 FROM dms_document_contributors WHERE document_id = :doc_id AND user_id = :user_id");
    $cStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
    return (bool)$cStmt->fetchColumn();
}

/**
 * Check whether a specific user can annotate on the current document specimen.
 */
function can_user_annotate_document(array $doc, int $userId): bool
{
    $status = $doc['status'] ?? 'active';

    // Published, obsolete, and rejected documents are globally locked from annotation
    if ($status === 'published' || $status === 'obsolete' || $status === 'rejected') {
        return false;
    }

    $docId = (int)$doc['id'];

    // While on Hold (Review), strictly only assigned reviewers can annotate
    if ($status === 'hold') {
        $pdo = db();
        $rStmt = $pdo->prepare("SELECT 1 FROM dms_document_reviews WHERE document_id = :doc_id AND reviewer_id = :user_id");
        $rStmt->execute(['doc_id' => $docId, 'user_id' => $userId]);
        return (bool)$rStmt->fetchColumn();
    }

    // When active or suspend, contributors can annotate
    return is_document_contributor($docId, $userId);
}

/**
 * Check whether a specific user can upload a new revision.
 */
function can_user_upload_revision(array $doc, int $userId): bool
{
    // Revisions can only be uploaded in Active, Rejected, or Suspend status (Hold freezes revisions)
    if (!in_array($doc['status'] ?? '', ['active', 'rejected', 'suspend'], true)) {
        return false;
    }

    return is_document_contributor((int)$doc['id'], $userId);
}

/**
 * Save access mode and synchronize contributor memberships.
 * Only the document owner or an Administrator can manage access.
 */
function save_document_access(int $docId, string $accessMode, array $contributorIds, int $operatorUserId): array
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document' AND is_deleted = 0");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.'];
    }

    $uStmt = $pdo->prepare("SELECT role, full_name FROM users WHERE id = :id");
    $uStmt->execute(['id' => $operatorUserId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    $isAdmin = in_array(strtolower($user['role'] ?? ''), ['super admin', 'admin'], true);
    $isOwner = ((int)$doc['created_by'] === $operatorUserId);

    if (!$isOwner && !$isAdmin) {
        return ['success' => false, 'message' => 'Only the document owner or an Administrator can modify access permissions.'];
    }

    $cleanMode = in_array($accessMode, ['restricted', 'public'], true) ? $accessMode : 'restricted';
    $cleanUserIds = array_values(array_unique(array_filter(array_map('intval', $contributorIds), fn($id) => $id > 0 && $id !== (int)$doc['created_by'])));

    $pdo->beginTransaction();
    try {
        // 1. Update access_mode
        $upd = $pdo->prepare("UPDATE dms_nodes SET access_mode = :mode, updated_at = NOW() WHERE id = :id");
        $upd->execute(['mode' => $cleanMode, 'id' => $docId]);

        // 2. Clear old contributors
        $del = $pdo->prepare("DELETE FROM dms_document_contributors WHERE document_id = :doc_id");
        $del->execute(['doc_id' => $docId]);

        // 3. Insert new contributors
        if (!empty($cleanUserIds)) {
            $ins = $pdo->prepare("INSERT INTO dms_document_contributors (document_id, user_id) VALUES (:doc_id, :user_id)");
            foreach ($cleanUserIds as $uid) {
                $ins->execute(['doc_id' => $docId, 'user_id' => $uid]);
            }
        }

        $pdo->commit();

        log_activity('document.access_updated', 'document', $docId, [
            'document_name' => $doc['name'],
            'access_mode'   => $cleanMode,
            'contributors_count' => count($cleanUserIds),
            'updated_by'    => $operatorUserId
        ], $operatorUserId);

        return [
            'success' => true,
            'access_mode' => $cleanMode,
            'contributors_count' => count($cleanUserIds),
            'message' => 'Document access settings updated successfully.'
        ];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to save access settings: ' . $e->getMessage()];
    }
}
