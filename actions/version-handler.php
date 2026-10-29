<?php
// Document Version Actions Endpoint (Upload Revision, Suspend, List)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contributors.php';

require_login();

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$user = current_user();
$userId = (int)($user['id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired).']);
    exit;
}

if (!can_edit_dms($user) && in_array($action, ['upload_revision', 'suspend_document'], true)) {
    $targetDocId = (int)($_REQUEST['doc_id'] ?? 0);
    log_activity('auth.access_denied', 'document', $targetDocId ?: null, [
        'attempted_action' => $action,
        'reason'           => 'Read-only viewer attempted version modification'
    ]);
    echo json_encode(['success' => false, 'message' => 'Access denied: Viewers have read-only permissions and cannot upload or alter document revisions.']);
    exit;
}

// 1. Get version history for a document
if ($action === 'get_versions') {
    $docId = isset($_GET['doc_id']) && is_numeric($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $versions = get_document_versions($docId);
    echo json_encode([
        'success'  => true,
        'versions' => $versions
    ]);
    exit;
}

// 1B. Suspend document for major revision
if ($action === 'suspend_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $pdo = db();
    $pdo->prepare("UPDATE dms_nodes SET status = 'suspend', updated_at = NOW() WHERE id = ? AND status = 'published'")->execute([$docId]);
    $pdo->prepare("UPDATE dms_document_versions SET change_summary = CONCAT(COALESCE(change_summary, ''), ' (SUSPENDED)') WHERE document_id = ? AND is_current = 1 AND change_summary NOT LIKE '%(SUSPENDED)%'")->execute([$docId]);
    log_activity('document.suspend', 'document', $docId, ['reason' => 'Major revision requested']);
    echo json_encode(['success' => true]);
    exit;
}

// 2. Upload a new revision (version bump)
if ($action === 'upload_revision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $docId = isset($_POST['doc_id']) && is_numeric($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
    $changeSummary = trim($_POST['change_summary'] ?? '');

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    if (!is_document_contributor($docId, $userId)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only contributors can upload revisions.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error or no file provided.']);
        exit;
    }

    $stored = store_uploaded_document_file($_FILES['file']);
    if (!($stored['success'] ?? false)) {
        echo json_encode(['success' => false, 'message' => $stored['message'] ?? 'Failed to save uploaded file to storage.']);
        exit;
    }

    $origName     = $stored['file_original_name'];
    $fileSize     = $stored['file_size'];
    $fullDestPath = $stored['full_path'];

    $result = create_document_version($docId, $stored, $changeSummary, $userId);

    if (!($result['success'] ?? false) && file_exists($fullDestPath)) {
        @unlink($fullDestPath);
    }

    if ($result['success'] ?? false) {
        log_activity('version.upload', 'document', $docId, [
            'change_summary'  => $changeSummary,
            'original_name'   => $origName,
            'file_size'       => $fileSize
        ]);
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
