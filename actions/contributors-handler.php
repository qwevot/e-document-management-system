<?php
// Document Access & Contributor Management Handler

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contributors.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = current_user();
if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired).']);
    exit;
}

$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Get Access & Contributor Data
if ($action === 'get_access_data') {
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.status, d.access_mode, d.created_by,
               u.id AS owner_user_id,
               COALESCE(u.full_name, u.username, 'Guest') AS owner_name,
               COALESCE(u.username, 'guest') AS owner_username, u.avatar AS owner_avatar
        FROM dms_nodes d
        LEFT JOIN users u ON d.created_by = u.id
        WHERE d.id = :id AND d.node_type = 'document' AND d.is_deleted = 0
    ");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }

    $contributors = get_document_contributors($docId);
    
    // Fetch all active users (to allow inviting as contributor, excluding owner)
    $usersStmt = $pdo->prepare("
        SELECT id, username, full_name, role, department, avatar 
        FROM users 
        WHERE id != :owner_id 
        ORDER BY full_name ASC
    ");
    $usersStmt->execute(['owner_id' => (int)$doc['created_by']]);
    $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    $contributorUserIds = array_map(fn($c) => (int)$c['user_id'], $contributors);

    echo json_encode([
        'success' => true,
        'doc_id' => $docId,
        'doc_name' => $doc['name'],
        'access_mode' => $doc['access_mode'] ?: 'restricted',
        'owner' => [
            'id' => (int)$doc['created_by'],
            'name' => $doc['owner_name'],
            'username' => $doc['owner_username'],
            'avatar' => $doc['owner_avatar']
        ],
        'contributors' => $contributors,
        'contributor_ids' => $contributorUserIds,
        'eligible_users' => $allUsers
    ]);
    exit;
}

// 2. Save Access Mode & Contributor Memberships
if ($action === 'save_access_data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    $accessMode = isset($data['access_mode']) ? trim((string)$data['access_mode']) : 'restricted';
    $contributorIds = isset($data['contributor_ids']) && is_array($data['contributor_ids']) ? $data['contributor_ids'] : [];

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $res = save_document_access($docId, $accessMode, $contributorIds, $userId);
    echo json_encode($res);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
