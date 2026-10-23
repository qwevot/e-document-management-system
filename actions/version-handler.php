<?php
// Document Version Actions Endpoint (Upload Revision, Revert, List)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$user = current_user();
$userId = (int)($user['id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF token invalid or expired).']);
    exit;
}

if (!can_edit_dms($user) && in_array($action, ['upload_revision', 'revert_version', 'publish_document', 'suspend_document'], true)) {
    $targetDocId = (int)($_REQUEST['doc_id'] ?? 0);
    log_activity('auth.access_denied', 'document', $targetDocId ?: null, [
        'attempted_action' => $action,
        'reason'           => 'Read-only viewer attempted version modification'
    ], 'failure');
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

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error or no file provided.']);
        exit;
    }

    $file = $_FILES['file'];
    $origName = $file['name'];
    $fileSize = (int)$file['size'];
    $tmpPath = $file['tmp_name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Determine storage path
    $yearMonth = date('Y/m');
    $destFolder = __DIR__ . '/../uploads/documents/' . $yearMonth;
    if (!is_dir($destFolder)) {
        @mkdir($destFolder, 0777, true);
    }

    $fileHash = hash_file('sha256', $tmpPath);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $storedFileName = md5($origName . microtime(true)) . '_' . $safeName . '.' . $ext;
    $relPath = 'uploads/documents/' . $yearMonth . '/' . $storedFileName;
    $fullDestPath = __DIR__ . '/../' . $relPath;

    if (!move_uploaded_file($tmpPath, $fullDestPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file to storage.']);
        exit;
    }

    $mimeType = mime_content_type($fullDestPath) ?: 'application/octet-stream';

    $fileData = [
        'file_path'          => $relPath,
        'file_original_name' => $origName,
        'file_size'          => $fileSize,
        'file_extension'     => $ext,
        'mime_type'          => $mimeType,
        'file_hash'          => $fileHash,
    ];

    $result = create_document_version($docId, $fileData, $changeSummary, $userId);

    if ($result['success'] ?? false) {
        log_activity('version.upload', 'document', $docId, [
            'change_summary'  => $changeSummary,
            'original_name'   => $origName,
            'file_size'       => $fileSize
        ], 'success');
    }

    echo json_encode($result);
    exit;
}

// 3. Revert to a specific revision
if ($action === 'revert_version' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    $versionId = isset($data['version_id']) && is_numeric($data['version_id']) ? (int)$data['version_id'] : 0;

    if ($docId <= 0 || $versionId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }

    $result = revert_document_version($docId, $versionId, $userId);

    if ($result['success'] ?? false) {
        log_activity('version.revert', 'document', $docId, [
            'version_id' => $versionId
        ], 'success');
    }

    echo json_encode($result);
    exit;
}

// 4. Publish document (Transition to PUBLISHED & bump major version)
if ($action === 'publish_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $docId = isset($data['doc_id']) && is_numeric($data['doc_id']) ? (int)$data['doc_id'] : 0;
    $notes = trim($data['notes'] ?? '');

    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $result = publish_document($docId, $notes, $userId);

    if ($result['success'] ?? false) {
        log_activity('version.publish', 'document', $docId, [
            'notes' => $notes
        ], 'success');
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
