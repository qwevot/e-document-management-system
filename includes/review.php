<?php
// Document Review & Approval Workflow Engine
// Governs status transitions between Active -> Hold -> Published / Active

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/versioning.php';

/**
 * Retrieve eligible reviewers across all roles.
 */
function get_eligible_reviewers(?int $excludeUserId = null): array
{
    $pdo = db();
    $sql = "
        SELECT id, username, full_name, role, department, avatar 
        FROM users 
        WHERE 1=1
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

    $sql .= " ORDER BY r.step_order ASC, r.id ASC";

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
 * Submit a document for review (Transitions status: Active -> Hold).
 * Accepts either a flat array of reviewer IDs or an array of tiered stages:
 * [ ['step_order' => 1, 'reviewer_ids' => [2, 3]], ['step_order' => 2, 'reviewer_ids' => [4]] ]
 */
function submit_document_for_review(
    int $docId,
    array $reviewersOrStages,
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

    // If the document is currently in review, only the submitter or author can modify the workflow
    if ($doc['status'] === 'hold') {
        $stReq = $pdo->prepare("SELECT requested_by FROM dms_document_reviews WHERE document_id = :doc_id LIMIT 1");
        $stReq->execute(['doc_id' => $docId]);
        $requestedBy = (int)$stReq->fetchColumn();

        $isSubmitter = ($userId === $requestedBy || (int)$doc['created_by'] === $userId);
        if (!$isSubmitter) {
            return ['success' => false, 'message' => 'Unauthorized. Reviewers cannot edit or cancel the review submission.'];
        }
    }

    // Normalize input to tiered stages
    $normalizedStages = [];
    if (!empty($reviewersOrStages) && isset($reviewersOrStages[0]) && (is_array($reviewersOrStages[0]) || is_object($reviewersOrStages[0]))) {
        $order = 1;
        foreach ($reviewersOrStages as $st) {
            $stArr = (array)$st;
            $uids = $stArr['reviewerIds'] ?? ($stArr['reviewer_ids'] ?? []);
            $cleanUids = array_values(array_unique(array_filter(array_map('intval', (array)$uids))));
            if (!empty($cleanUids)) {
                $normalizedStages[] = [
                    'step_order'   => $order++,
                    'reviewer_ids' => $cleanUids
                ];
            }
        }
    } else {
        $cleanUids = array_values(array_unique(array_filter(array_map('intval', $reviewersOrStages))));
        if (!empty($cleanUids)) {
            $normalizedStages[] = [
                'step_order'   => 1,
                'reviewer_ids' => $cleanUids
            ];
        }
    }

    if (empty($normalizedStages)) {
        return ['success' => false, 'message' => 'Please select at least one reviewer.'];
    }

    $curVersion = $doc['version'] ?: 'v0.1';
    $targetVer = $targetVersion ?: calculate_next_version($curVersion, 'major');

    $pdo->beginTransaction();
    try {
        // 1. Update document status to 'hold'
        $upd = $pdo->prepare("UPDATE dms_nodes SET status = 'hold', updated_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $docId]);

        // Update current version review_status to 'pending'
        $updVer = $pdo->prepare("UPDATE dms_document_versions SET review_status = 'pending' WHERE document_id = :doc_id AND is_current = 1");
        $updVer->execute(['doc_id' => $docId]);

        // 2. Fetch any already-approved reviews to preserve historical integrity across edits
        $stExisting = $pdo->prepare("SELECT * FROM dms_document_reviews WHERE document_id = :doc_id");
        $stExisting->execute(['doc_id' => $docId]);
        $existingReviews = $stExisting->fetchAll(PDO::FETCH_ASSOC);

        $approvedByStep = [];
        foreach ($existingReviews as $er) {
            if ($er['status'] === 'approved') {
                $approvedByStep[(int)$er['step_order']][(int)$er['reviewer_id']] = $er;
            }
        }

        // 3. Clear non-approved reviews for this document (pending/waiting are replaced by new configuration)
        $delNonApproved = $pdo->prepare("DELETE FROM dms_document_reviews WHERE document_id = :doc_id AND status != 'approved'");
        $delNonApproved->execute(['doc_id' => $docId]);

        // 4. Create review assignments per stage
        $ins = $pdo->prepare("
            INSERT INTO dms_document_reviews (document_id, version, step_order, requested_by, reviewer_id, status, comments, created_at)
            VALUES (:document_id, :version, :step_order, :requested_by, :reviewer_id, :status, :comments, NOW())
        ");

        $activePendingAssigned = false;
        $allCleanUids = [];

        foreach ($normalizedStages as $st) {
            $stepOrder = (int)$st['step_order'];
            $reqUids = $st['reviewer_ids'];

            $existingApprovedForStep = $approvedByStep[$stepOrder] ?? [];
            $alreadyApprovedUids = array_keys($existingApprovedForStep);
            $isStepAlreadyComplete = !empty($reqUids) && empty(array_diff($reqUids, $alreadyApprovedUids));

            if (!$isStepAlreadyComplete && !$activePendingAssigned) {
                $stepStatus = 'pending';
                $activePendingAssigned = true;
            } elseif ($isStepAlreadyComplete) {
                $stepStatus = 'approved';
            } else {
                $stepStatus = 'waiting';
            }

            foreach ($reqUids as $revId) {
                // If reviewer already has an approved record for this step, keep it intact
                if (isset($existingApprovedForStep[$revId])) {
                    $allCleanUids[] = $revId;
                    continue;
                }

                $ins->execute([
                    'document_id'  => $docId,
                    'version'      => $targetVer,
                    'step_order'   => $stepOrder,
                    'requested_by' => $userId,
                    'reviewer_id'  => $revId,
                    'status'       => $stepStatus,
                    'comments'     => $notes ? ('Initial submission notes: ' . $notes) : null
                ]);
                $allCleanUids[] = $revId;
            }
        }

        $pdo->commit();

        log_activity('document.review_requested', 'document', $docId, [
            'document_name'  => $doc['name'],
            'target_version' => $targetVer,
            'stages_count'   => count($normalizedStages),
            'reviewers'      => $allCleanUids,
            'notes'          => $notes
        ], $userId);

        $stageCount = count($normalizedStages);
        $stageMsg = $stageCount > 1 
            ? "Document placed on Hold with a {$stageCount}-stage review pipeline." 
            : "Document placed on Hold and submitted to " . count($allCleanUids) . " reviewer(s).";

        return [
            'success'        => true,
            'message'        => $stageMsg,
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

    if ($doc['status'] !== 'hold') {
        return ['success' => false, 'message' => 'Document is not currently awaiting review.'];
    }

    // Check user
    $userStmt = $pdo->prepare("SELECT role, full_name, username FROM users WHERE id = :id");
    $userStmt->execute(['id' => $reviewerId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Find review record specifically assigned to this reviewer in the active pending stage
    $revStmt = $pdo->prepare("
        SELECT * FROM dms_document_reviews 
        WHERE document_id = :doc_id AND reviewer_id = :reviewer_id AND status = 'pending'
        LIMIT 1
    ");
    $revStmt->execute([
        'doc_id'      => $docId,
        'reviewer_id' => $reviewerId
    ]);
    $reviewRecord = $revStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reviewRecord) {
        return ['success' => false, 'message' => 'Unauthorized. You are not assigned to review this document at the current stage.'];
    }

    $currentStep = (int)($reviewRecord['step_order'] ?? 1);
    $targetVersion = $reviewRecord['version'];

    $pdo->beginTransaction();
    try {
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

        // Action on Verdict
        if ($verdict === 'rejected') {
            // Set document status to 'rejected'
            $updDoc = $pdo->prepare("UPDATE dms_nodes SET status = 'rejected', updated_at = NOW() WHERE id = :id");
            $updDoc->execute(['id' => $docId]);

            // Mark current version as rejected
            $updVer = $pdo->prepare("UPDATE dms_document_versions SET review_status = 'rejected' WHERE document_id = :doc_id AND is_current = 1");
            $updVer->execute(['doc_id' => $docId]);

            $pdo->commit();

            log_activity('document.review_rejected', 'document', $docId, [
                'document_name' => $doc['name'],
                'reviewer_id'   => $reviewerId,
                'stage'         => $currentStep,
                'feedback'      => $comments
            ], $reviewerId);

            return [
                'success' => true,
                'verdict' => 'rejected',
                'status'  => 'rejected',
                'message' => "Changes requested at Stage {$currentStep}. Document marked as Rejected."
            ];
        } else {
            // Approved verdict: Check if all reviewers in the current stage have approved
            $checkStage = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved
                FROM dms_document_reviews
                WHERE document_id = :doc_id AND version = :ver AND step_order = :step
            ");
            $checkStage->execute(['doc_id' => $docId, 'ver' => $targetVersion, 'step' => $currentStep]);
            $stageStats = $checkStage->fetch(PDO::FETCH_ASSOC);
            $stageTotal = (int)($stageStats['total'] ?? 0);
            $stageApproved = (int)($stageStats['approved'] ?? 0);

            $isStageComplete = ($stageTotal > 0 && $stageApproved >= $stageTotal);

            // If current stage is complete and there is a subsequent waiting stage, promote it
            if ($isStageComplete) {
                $nextStepStmt = $pdo->prepare("
                    SELECT MIN(step_order) FROM dms_document_reviews 
                    WHERE document_id = :doc_id AND version = :ver AND step_order > :step AND status = 'waiting'
                ");
                $nextStepStmt->execute(['doc_id' => $docId, 'ver' => $targetVersion, 'step' => $currentStep]);
                $nextStep = (int)$nextStepStmt->fetchColumn();

                if ($nextStep > 0) {
                    $actStmt = $pdo->prepare("
                        UPDATE dms_document_reviews 
                        SET status = 'pending' 
                        WHERE document_id = :doc_id AND version = :ver AND step_order = :next_step AND status = 'waiting'
                    ");
                    $actStmt->execute(['doc_id' => $docId, 'ver' => $targetVersion, 'next_step' => $nextStep]);

                    $pdo->commit();

                    log_activity('document.review_stage_promoted', 'document', $docId, [
                        'document_name'   => $doc['name'],
                        'completed_stage' => $currentStep,
                        'next_stage'      => $nextStep,
                        'approved_by'     => $reviewerId
                    ], $reviewerId);

                    return [
                        'success'      => true,
                        'verdict'      => 'approved',
                        'status'       => 'hold',
                        'is_published' => false,
                        'message'      => "Stage {$currentStep} completed. Stage {$nextStep} is now open for review."
                    ];
                }
            }

            // Check if ALL assigned reviewers across all stages have approved
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

            // Document is published ONLY if all assigned reviewers in all stages approved
            $isFullyApproved = ($total > 0 && $approved >= $total);

            $pdo->commit();

            if ($isFullyApproved) {
                // Execute formal publication
                $publishRes = publish_document($docId, $comments ?: "Approved and published by {$user['full_name']}", $reviewerId);
                
                log_activity('document.published', 'document', $docId, [
                    'document_name'  => $doc['name'],
                    'target_version' => $publishRes['version'] ?? $targetVersion,
                    'approved_by'    => $reviewerId,
                    'sign_off_note'  => $comments
                ], $reviewerId);

                return [
                    'success'      => true,
                    'verdict'      => 'approved',
                    'status'       => 'published',
                    'is_published' => true,
                    'version'      => $publishRes['version'] ?? $targetVersion,
                    'message'      => "All review stages completed successfully. Document published as {$publishRes['version']}."
                ];
            }

            return [
                'success'      => true,
                'verdict'      => 'approved',
                'status'       => 'hold',
                'is_published' => false,
                'message'      => "Stage {$currentStep} sign-off recorded. Awaiting remaining reviewers."
            ];
        }
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to record verdict: ' . $e->getMessage()];
    }
}

/**
 * Cancel/revert an ongoing review submission (Transitions status: Hold -> Active).
 * Can be invoked strictly by the user who submitted the review request (or document author).
 */
function cancel_document_review(int $docId, int $userId): array
{
    $pdo = db();

    $docStmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document'");
    $docStmt->execute(['id' => $docId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.'];
    }

    if ($doc['status'] !== 'hold') {
        return ['success' => false, 'message' => 'Document is not currently on Hold for review.'];
    }

    // Identify the user who submitted the review request
    $reqStmt = $pdo->prepare("SELECT requested_by FROM dms_document_reviews WHERE document_id = :doc_id ORDER BY id DESC LIMIT 1");
    $reqStmt->execute(['doc_id' => $docId]);
    $reviewRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
    $requestedBy = $reviewRow ? (int)$reviewRow['requested_by'] : (int)$doc['created_by'];

    $isSubmitter = ($userId === $requestedBy || (int)$doc['created_by'] === $userId);
    if (!$isSubmitter) {
        return ['success' => false, 'message' => 'Only the user who submitted the review request can cancel/retract it.'];
    }

    $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id");
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        // 1. Delete all review cycle records for this document
        $del = $pdo->prepare("DELETE FROM dms_document_reviews WHERE document_id = :doc_id");
        $del->execute(['doc_id' => $docId]);

        // 2. Set document status back to 'active' and reset version status to 'draft'
        $upd = $pdo->prepare("UPDATE dms_nodes SET status = 'active', updated_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $docId]);

        $updVer = $pdo->prepare("UPDATE dms_document_versions SET review_status = 'draft' WHERE document_id = :doc_id AND is_current = 1 AND review_status = 'pending'");
        $updVer->execute(['doc_id' => $docId]);

        $pdo->commit();

        log_activity('document.review_cancelled', 'document', $docId, [
            'document_name' => $doc['name'],
            'cancelled_by'  => $userId,
            'user_name'     => $user['full_name'] ?? 'User'
        ], $userId);

        return [
            'success' => true,
            'status'  => 'active',
            'message' => 'Review submission retracted. Document status returned to Active.'
        ];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to cancel review: ' . $e->getMessage()];
    }
}
