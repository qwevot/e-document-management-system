<?php
// Document Review & Approval Actions Endpoint

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contributors.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Please log in to perform review actions.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired).']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int)($user['id'] ?? 1);

if (!can_edit_dms($user) && in_array($action, ['submit_review', 'cancel_review'], true)) {
    $targetDocId = (int)($_REQUEST['doc_id'] ?? 0);
    log_activity('auth.access_denied', 'document', $targetDocId ?: null, [
        'attempted_action' => $action,
        'reason'           => 'Read-only viewer attempted review workflow modification'
    ]);
    echo json_encode(['success' => false, 'message' => 'Access denied: Viewers have read-only permissions and cannot initiate or cancel review workflows.']);
    exit;
}

// 1. Get eligible reviewers
if ($action === 'get_reviewers') {
    $reviewers = get_eligible_reviewers($userId);
    echo json_encode([
        'success'   => true,
        'reviewers' => $reviewers
    ]);
    exit;
}

// 2. Get reviews for a document
if ($action === 'get_reviews') {
    $docId = isset($_GET['doc_id']) && is_numeric($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $reviews = get_document_reviews($docId);
    echo json_encode([
        'success' => true,
        'reviews' => $reviews
    ]);
    exit;
}

// 3. Submit document for review (Active -> Hold)
if ($action === 'submit_review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    $reviewerIds = $data['reviewer_ids'] ?? [];
    $targetVersion = trim($data['target_version'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    if (!is_document_contributor($docId, $userId)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only contributors can submit documents for review.']);
        exit;
    }

    if (!is_array($reviewerIds) || empty($reviewerIds)) {
        echo json_encode(['success' => false, 'message' => 'Please select at least one reviewer.']);
        exit;
    }

    $result = submit_document_for_review($docId, $reviewerIds, $userId, $targetVersion !== '' ? $targetVersion : null, $notes ?: null);
    echo json_encode($result);
    exit;
}

// 4. Submit review verdict (Approve / Reject)
if ($action === 'submit_verdict' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    $verdict = trim($data['verdict'] ?? '');
    $comments = trim($data['comments'] ?? '');

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid verdict. Must be approved or rejected.']);
        exit;
    }

    $result = submit_review_verdict($docId, $userId, $verdict, $comments ?: null);
    echo json_encode($result);
    exit;
}

// 5. Cancel review submission (Hold -> Active)
if ($action === 'cancel_review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    if (!is_document_contributor($docId, $userId)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only contributors can cancel a review.']);
        exit;
    }

    $result = cancel_document_review($docId, $userId);
    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown review action.']);
exit;
