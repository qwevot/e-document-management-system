<?php
// DELETE: Removes a user account. Super admin only; cannot delete yourself or last super admin.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/review.php';
require_login();
require_role('super admin');
require_csrf('users');

$pdo = db();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_to('users', 'error', 'Invalid user.');
}

$currentUserId = (int)(current_user()['id'] ?? 0);
if ($id === $currentUserId) {
    log_activity('user.delete_failed', 'user', $id, ['reason' => 'Attempted self-deletion']);
    redirect_to('users', 'error', 'You cannot delete your own account.');
}

$st = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
$st->execute([$id]);
$targetUser = $st->fetch(PDO::FETCH_ASSOC);
if (!$targetUser) {
    redirect_to('users', 'error', 'User not found.');
}
$username = $targetUser['username'];

if ($targetUser['role'] === 'super admin') {
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super admin'")->fetchColumn();
    if ($adminCount <= 1) {
        log_activity('user.delete_failed', 'user', $id, ['username' => $username, 'reason' => 'Cannot delete sole Super Administrator']);
        redirect_to('users', 'error', 'Cannot delete the sole Super Administrator account.');
    }
}

$pdo->beginTransaction();
try {
    // 1. Find any active 'hold' documents where this user has pending/waiting review rows
    $activeRevDocsStmt = $pdo->prepare("
        SELECT DISTINCT r.document_id, r.version
        FROM dms_document_reviews r
        JOIN dms_nodes d ON r.document_id = d.id
        WHERE r.reviewer_id = ? AND r.status IN ('pending', 'waiting') AND d.status = 'hold' AND d.is_deleted = 0
    ");
    $activeRevDocsStmt->execute([$id]);
    $affectedHoldDocs = $activeRevDocsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Remove uncompleted review assignments for the deleted user
    $pdo->prepare("DELETE FROM dms_document_reviews WHERE reviewer_id = ? AND status IN ('pending', 'waiting')")->execute([$id]);

    // Reconcile any Hold documents affected by removing this reviewer
    $docsToPublish = [];
    foreach ($affectedHoldDocs as $ah) {
        $docId = (int)$ah['document_id'];
        $ver   = $ah['version'];

        $remStmt = $pdo->prepare("SELECT status, step_order FROM dms_document_reviews WHERE document_id = ? AND version = ?");
        $remStmt->execute([$docId, $ver]);
        $remRows = $remStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($remRows)) {
            // Sole reviewer was deleted: return document from Hold back to Active
            $pdo->prepare("UPDATE dms_nodes SET status = 'active', updated_at = NOW() WHERE id = ? AND status = 'hold'")->execute([$docId]);
            $pdo->prepare("UPDATE dms_document_versions SET review_status = 'draft' WHERE document_id = ? AND is_current = 1 AND review_status = 'pending'")->execute([$docId]);
        } else {
            $hasPending  = false;
            $hasWaiting  = false;
            $allApproved = true;
            $minWaitingStep = null;

            foreach ($remRows as $rr) {
                if ($rr['status'] === 'pending') {
                    $hasPending = true;
                    $allApproved = false;
                } elseif ($rr['status'] === 'waiting') {
                    $hasWaiting = true;
                    $allApproved = false;
                    $step = (int)$rr['step_order'];
                    if ($minWaitingStep === null || $step < $minWaitingStep) {
                        $minWaitingStep = $step;
                    }
                } elseif ($rr['status'] !== 'approved') {
                    $allApproved = false;
                }
            }

            if (!$hasPending && $hasWaiting && $minWaitingStep !== null) {
                $pdo->prepare("UPDATE dms_document_reviews SET status = 'pending' WHERE document_id = ? AND version = ? AND step_order = ? AND status = 'waiting'")
                    ->execute([$docId, $ver, $minWaitingStep]);
            } elseif ($allApproved) {
                $docsToPublish[] = $docId;
            }
        }
    }

    // 2. Convert all documents owned by the deleted user to Public (owner becomes Guest)
    $ownStmt = $pdo->prepare("UPDATE dms_nodes SET access_mode = 'public' WHERE created_by = ?");
    $ownStmt->execute([$id]);
    $reassignedCount = $ownStmt->rowCount();

    // 3. Clean up contributor & star pivot rows for the deleted user
    $pdo->prepare("DELETE FROM dms_document_contributors WHERE user_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM dms_starred_nodes WHERE user_id = ?")->execute([$id]);

    // 4. Delete user account
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

    $pdo->commit();

    // If any Hold document became 100% approved after removing the deleted user's pending slot, publish it
    foreach ($docsToPublish as $pubDocId) {
        publish_document($pubDocId, "Published automatically after remaining reviewers approved (deleted reviewer slot removed).", $currentUserId);
    }

    log_activity('user.delete', 'user', $id, [
        'username'               => $username,
        'role'                   => $targetUser['role'] ?? 'viewer',
        'guest_public_docs_count'=> $reassignedCount
    ]);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity('user.delete_failed', 'user', $id, ['username' => $username, 'error' => $e->getMessage()]);
    redirect_to('users', 'error', 'Failed to delete user: ' . $e->getMessage());
}

redirect_to('users', 'success', 'User "' . $username . '" removed. Owned documents are now under Guest (Public).');
