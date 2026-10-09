<?php
// Document Review & Approval Workflow Engine
// Governs status transitions between Active -> Hold -> Published / Active

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/versioning.php';

/**
 * Ensure the review workflow schema exists.
 */
function init_review_schema(): void
{
    $pdo = db();
    $sql = "
    CREATE TABLE IF NOT EXISTS `dms_document_reviews` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `document_id` BIGINT UNSIGNED NOT NULL,
        `version` VARCHAR(20) NOT NULL DEFAULT 'v0.1',
        `requested_by` BIGINT UNSIGNED NOT NULL,
        `reviewer_id` BIGINT UNSIGNED NOT NULL,
        `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        `comments` TEXT NULL,
        `reviewed_at` DATETIME NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_review_doc` (`document_id`, `status`),
        INDEX `idx_review_reviewer` (`reviewer_id`, `status`),
        CONSTRAINT `fk_reviews_doc` FOREIGN KEY (`document_id`) REFERENCES `dms_nodes` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reviews_req_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reviews_rev_user` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
}

/**
 * Retrieve eligible reviewers (Admins, Super Admins, and Engineers).
 */
function get_eligible_reviewers(?int $excludeUserId = null): array
{
    $pdo = db();
    $sql = "
        SELECT id, username, full_name, role, department, avatar 
        FROM users 
        WHERE role IN ('super admin', 'admin', 'engineer')
    ";
    $params = [];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= " AND id != :exclude_id";
        $params['exclude_id'] = $excludeUserId;
    }
    $sql .= " ORDER BY role = 'super admin' DESC, role = 'admin' DESC, full_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve review assignments & verdicts for a specific document.
 */
function get_document_reviews(int $docId, ?string $version = null): array
{
    $pdo = db();
    $sql = "
        SELECT r.*,
               COALESCE(u.full_name, u.username, 'Reviewer') AS reviewer_name,
               u.username AS reviewer_username,
               u.role AS reviewer_role,
               u.department AS reviewer_department,
               u.avatar AS reviewer_avatar,
               COALESCE(req.full_name, req.username, 'Requester') AS requester_name
        FROM dms_document_reviews r
        LEFT JOIN users u ON r.reviewer_id = u.id
        LEFT JOIN users req ON r.requested_by = req.id
        WHERE r.document_id = :doc_id
    ";
    $params = ['doc_id' => $docId];

    if ($version !== null && $version !== '') {
        $sql .= " AND r.version = :version";
        $params['version'] = $version;
    }

    $sql .= " ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve pending review requests assigned to a specific user.
 */
function get_pending_reviews_for_user(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT r.id AS review_id, r.version AS target_version, r.created_at AS review_requested_at,
               r.status AS review_status,
               d.id AS document_id, d.name AS document_name, d.version AS current_version,
               d.file_extension, d.file_size, d.file_original_name, d.status AS document_status,
               p.name AS parent_folder_name,
               COALESCE(req.full_name, req.username, 'Author') AS requested_by_name,
               req.avatar AS requester_avatar
        FROM dms_document_reviews r
        JOIN dms_nodes d ON r.document_id = d.id
        LEFT JOIN dms_nodes p ON d.parent_id = p.id
        LEFT JOIN users req ON r.requested_by = req.id
        WHERE r.reviewer_id = :user_id 
          AND r.status = 'pending'
          AND d.status = 'hold'
          AND d.is_deleted = 0
        ORDER BY r.id DESC
    ");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve review submissions authored by a specific user that are currently in hold.
 */
function get_user_review_submissions(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT d.id AS document_id, d.name AS document_name, d.version AS current_version,
               d.file_extension, d.file_size, d.file_original_name, d.status AS document_status,
               d.updated_at,
               p.name AS parent_folder_name,
               COUNT(r.id) AS total_reviewers,
               SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
               SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
               SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
        FROM dms_nodes d
        LEFT JOIN dms_nodes p ON d.parent_id = p.id
        LEFT JOIN dms_document_reviews r ON d.id = r.document_id AND r.version = d.version
        WHERE d.created_by = :user_id 
          AND d.node_type = 'document'
          AND d.status = 'hold'
          AND d.is_deleted = 0
        GROUP BY d.id
        ORDER BY d.updated_at DESC
    ");
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Submit a document for review (Transitions status: Active -> Hold).
 */
function submit_document_for_review(
    int $docId,
    array $reviewerIds,
    int $userId,
    ?string $targetVersion = null,
    ?string $notes = null
): array {
    $pdo = db();

    // Verify document exists
    $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document' AND is_deleted = 0");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found or inaccessible.'];
    }

    if ($doc['status'] === 'obsolete') {
        return ['success' => false, 'message' => 'Cannot submit an obsolete document for review.'];
    }

    $cleanReviewerIds = array_values(array_unique(array_filter(array_map('intval', $reviewerIds))));
    if (empty($cleanReviewerIds)) {
        return ['success' => false, 'message' => 'Please select at least one reviewer.'];
    }

    $curVersion = $doc['version'] ?: 'v0.1';
    $targetVer = $targetVersion ?: calculate_next_version($curVersion, 'major');

    $pdo->beginTransaction();
    try {
        // 1. Update document status to 'hold'
        $upd = $pdo->prepare("UPDATE dms_nodes SET status = 'hold', updated_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $docId]);

        // 2. Clear any lingering pending reviews for this document version
        $delOld = $pdo->prepare("DELETE FROM dms_document_reviews WHERE document_id = :doc_id AND version = :ver AND status = 'pending'");
        $delOld->execute(['doc_id' => $docId, 'ver' => $targetVer]);

        // 3. Create review assignments
        $ins = $pdo->prepare("
            INSERT INTO dms_document_reviews (document_id, version, requested_by, reviewer_id, status, comments, created_at)
            VALUES (:document_id, :version, :requested_by, :reviewer_id, 'pending', :comments, NOW())
        ");

        foreach ($cleanReviewerIds as $revId) {
            $ins->execute([
                'document_id'  => $docId,
                'version'      => $targetVer,
                'requested_by' => $userId,
                'reviewer_id'  => $revId,
                'comments'     => $notes ? ('Initial submission notes: ' . $notes) : null
            ]);
        }

        $pdo->commit();

        log_activity('document.review_requested', 'document', $docId, [
            'document_name'  => $doc['name'],
            'target_version' => $targetVer,
            'reviewers'      => $cleanReviewerIds,
            'notes'          => $notes
        ], 'success', $userId);

        return [
            'success'        => true,
            'message'        => "Document placed on Hold and submitted to " . count($cleanReviewerIds) . " reviewer(s).",
            'status'         => 'hold',
            'target_version' => $targetVer
        ];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to submit for review: ' . $e->getMessage()];
    }
}

/**
 * Submit reviewer verdict (Approve or Reject/Request Changes).
 */
function submit_review_verdict(
    int $docId,
    int $reviewerId,
    string $verdict,
    ?string $comments = null
): array {
    $pdo = db();

    $verdict = strtolower(trim($verdict));
    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        return ['success' => false, 'message' => 'Invalid review verdict. Must be approved or rejected.'];
    }

    // Verify document
    $docStmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document'");
    $docStmt->execute(['id' => $docId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.'];
    }

    // Check user role
    $userStmt = $pdo->prepare("SELECT role, full_name, username FROM users WHERE id = :id");
    $userStmt->execute(['id' => $reviewerId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userRole = strtolower($user['role'] ?? 'viewer');
    $isAdmin = in_array($userRole, ['super admin', 'admin'], true);

    // Find review record for this reviewer or check if admin
    $revStmt = $pdo->prepare("
        SELECT * FROM dms_document_reviews 
        WHERE document_id = :doc_id AND (reviewer_id = :reviewer_id OR :is_admin = 1)
        ORDER BY (reviewer_id = :reviewer_id2) DESC, id DESC LIMIT 1
    ");
    $revStmt->execute([
        'doc_id'       => $docId,
        'reviewer_id'  => $reviewerId,
        'is_admin'     => $isAdmin ? 1 : 0,
        'reviewer_id2' => $reviewerId
    ]);
    $reviewRecord = $revStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        if ($reviewRecord) {
            $updRev = $pdo->prepare("
                UPDATE dms_document_reviews 
                SET status = :status, comments = :comments, reviewed_at = NOW() 
                WHERE id = :id
            ");
            $updRev->execute([
                'status'   => $verdict,
                'comments' => trim($comments ?? ''),
                'id'       => $reviewRecord['id']
            ]);
            $targetVersion = $reviewRecord['version'];
        } else {
            // Admin direct sign-off if not originally in reviewer list
            $targetVersion = calculate_next_version($doc['version'] ?: 'v0.1', 'major');
            $insRev = $pdo->prepare("
                INSERT INTO dms_document_reviews (document_id, version, requested_by, reviewer_id, status, comments, reviewed_at, created_at)
                VALUES (:doc_id, :ver, :req_by, :rev_id, :status, :comments, NOW(), NOW())
            ");
            $insRev->execute([
                'doc_id'   => $docId,
                'ver'      => $targetVersion,
                'req_by'   => $doc['created_by'] ?: $reviewerId,
                'rev_id'   => $reviewerId,
                'status'   => $verdict,
                'comments' => trim($comments ?? '')
            ]);
        }

        // Action on Verdict
        if ($verdict === 'rejected') {
            // Revert document status back to 'active' (draft) for revision
            $updDoc = $pdo->prepare("UPDATE dms_nodes SET status = 'active', updated_at = NOW() WHERE id = :id");
            $updDoc->execute(['id' => $docId]);

            $pdo->commit();

            log_activity('document.review_rejected', 'document', $docId, [
                'document_name' => $doc['name'],
                'reviewer_id'   => $reviewerId,
                'feedback'      => $comments
            ], 'warning', $reviewerId);

            return [
                'success' => true,
                'verdict' => 'rejected',
                'status'  => 'active',
                'message' => 'Changes requested. Document returned to Active draft status.'
            ];
        } else {
            // Approved verdict: Check if all reviewers have approved
            $checkAll = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved
                FROM dms_document_reviews
                WHERE document_id = :doc_id AND version = :ver
            ");
            $checkAll->execute(['doc_id' => $docId, 'ver' => $targetVersion]);
            $stats = $checkAll->fetch(PDO::FETCH_ASSOC);

            $total = (int)($stats['total'] ?? 0);
            $approved = (int)($stats['approved'] ?? 0);

            // Document is published if all assigned reviewers approved, or if Super Admin approves directly
            $isFullyApproved = ($total > 0 && $approved >= $total) || ($userRole === 'super admin');

            $pdo->commit();

            if ($isFullyApproved) {
                // Execute formal publication
                $publishRes = publish_document($docId, $comments ?: "Approved and published by {$user['full_name']}", $reviewerId);
                
                log_activity('document.published', 'document', $docId, [
                    'document_name'  => $doc['name'],
                    'target_version' => $publishRes['version'] ?? $targetVersion,
                    'approved_by'    => $reviewerId,
                    'sign_off_note'  => $comments
                ], 'success', $reviewerId);

                return [
                    'success'        => true,
                    'verdict'        => 'approved',
                    'status'         => 'published',
                    'is_published'   => true,
                    'version'        => $publishRes['version'] ?? $targetVersion,
                    'message'        => "All approvals complete. Document officially published as " . ($publishRes['version'] ?? $targetVersion)
                ];
            } else {
                log_activity('document.review_approved', 'document', $docId, [
                    'document_name'  => $doc['name'],
                    'approved_by'    => $reviewerId,
                    'pending_count'  => max(0, $total - $approved),
                    'sign_off_note'  => $comments
                ], 'success', $reviewerId);

                return [
                    'success'        => true,
                    'verdict'        => 'approved',
                    'status'         => 'hold',
                    'is_published'   => false,
                    'message'        => "Approval recorded ({$approved}/{$total} approvers completed)."
                ];
            }
        }
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to record verdict: ' . $e->getMessage()];
    }
}
