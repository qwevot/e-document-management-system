<?php
// Document Review & Approval Actions Endpoint

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$user = current_user();
$userId = (int)($user['id'] ?? 1);

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

    if (!is_array($reviewerIds) || empty($reviewerIds)) {
        echo json_encode(['success' => false, 'message' => 'Please select at least one reviewer.']);
        exit;
    }

    $result = submit_document_for_review($docId, $reviewerIds, $userId, $targetVersion ?: null, $notes ?: null);
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

echo json_encode(['success' => false, 'message' => 'Unknown review action.']);
exit;
