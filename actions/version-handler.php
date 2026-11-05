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
    if ($docId <= 0 || !is_document_contributor($docId, $userId)) {
        log_activity('auth.access_denied', 'document', $docId ?: null, ['attempted_action' => 'suspend_document']);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only contributors can suspend this document.']);
        exit;
    }
    $pdo = db();
    $pdo->prepare("UPDATE dms_nodes SET status = 'suspend', updated_at = NOW() WHERE id = ? AND status = 'published'")->execute([$docId]);
    $pdo->prepare("UPDATE dms_document_versions SET review_status = 'suspended' WHERE document_id = ? AND is_current = 1")->execute([$docId]);
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
        log_activity('auth.access_denied', 'document', $docId, ['attempted_action' => 'upload_revision']);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only contributors can upload revisions.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        log_activity('version.upload_failed', 'document', $docId, [
            'error_code' => $_FILES['file']['error'] ?? 'missing',
            'reason'     => 'File upload error or no file provided'
        ]);
        echo json_encode(['success' => false, 'message' => 'File upload error or no file provided.']);
        exit;
    }

    $stored = store_uploaded_document_file($_FILES['file']);
    if (!($stored['success'] ?? false)) {
        log_activity('version.upload_failed', 'document', $docId, [
            'filename' => $_FILES['file']['name'] ?? '',
            'reason'   => $stored['message'] ?? 'Storage failure'
        ]);
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
            'version_number'  => $result['version_number'] ?? null,
            'change_summary'  => $changeSummary,
            'original_name'   => $origName,
            'file_size'       => $fileSize
        ]);
    } else {
        log_activity('version.upload_failed', 'document', $docId, [
            'original_name' => $origName,
            'reason'        => $result['message'] ?? 'Version creation failed'
        ]);
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
