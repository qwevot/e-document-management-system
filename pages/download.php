<?php
// Dedicated Secure File Download Endpoint
// Streams the physical file to the client with clean original filename headers

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = db();

$nodeId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($nodeId <= 0) {
    http_response_code(400);
    die('Invalid document identifier.');
}

// Fetch document record
$stmt = $pdo->prepare("
    SELECT id, name, node_type, file_path, file_original_name, file_size, mime_type, file_extension, is_deleted
    FROM dms_nodes
    WHERE id = :id
");
$stmt->execute(['id' => $nodeId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc || $doc['node_type'] !== 'document') {
    http_response_code(404);
    die('Document not found.');
}

// Support downloading specific historical revision
$versionId = isset($_GET['version_id']) && is_numeric($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
if ($versionId > 0) {
    $vStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE id = :vid AND document_id = :did");
    $vStmt->execute(['vid' => $versionId, 'did' => $nodeId]);
    $vDoc = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($vDoc) {
        $doc['file_path'] = $vDoc['file_path'];
        $doc['file_original_name'] = $vDoc['file_original_name'];
        $doc['file_size'] = $vDoc['file_size'];
        $doc['file_extension'] = $vDoc['file_extension'];
        $doc['mime_type'] = $vDoc['mime_type'];
        $doc['version'] = $vDoc['version_number'];
    }
}

// Check physical file existence
$physicalFilePath = __DIR__ . '/../' . ltrim($doc['file_path'], '/\\');

if (!file_exists($physicalFilePath) || !is_readable($physicalFilePath)) {
    http_response_code(404);
    die('Physical file does not exist on disk.');
}

$asPdf = (isset($_GET['as_pdf']) && (string)$_GET['as_pdf'] === '1') ||
         (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'pdf');

$streamFilePath = $physicalFilePath;
$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$downloadExt = $ext;
$mimeType = !empty($doc['mime_type']) ? $doc['mime_type'] : 'application/octet-stream';

if ($asPdf && $ext !== 'pdf') {
    $convertedPath = convert_document_to_pdf($physicalFilePath);
    if ($convertedPath && file_exists($convertedPath) && is_readable($convertedPath)) {
        $streamFilePath = $convertedPath;
        $downloadExt = 'pdf';
        $mimeType = 'application/pdf';
    } else {
        http_response_code(503);
        die('PDF conversion is currently unavailable. Please place LibreOffice Portable into the "bin/LibreOfficePortable" folder or install LibreOffice on the server.');
    }
}

// Determine clean download filename based on the current active title in the system
$baseName = trim($doc['name'] ?? '');
if ($baseName === '') {
    $baseName = $doc['file_original_name'] ?: 'document';
}

// Strip invalid filename characters for client OS filesystem safety
$sanitizedBaseName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $baseName);
$sanitizedBaseName = preg_replace('/\.(docx?|pdf|rtf|odt|xlsx?|pptx?)$/i', '', $sanitizedBaseName);

// Ensure proper extension is appended
$downloadName = $sanitizedBaseName . ($downloadExt !== '' ? ('.' . $downloadExt) : '');

// Clean output buffers to prevent corruption in binary stream
if (ob_get_level()) {
    ob_end_clean();
}

$fileSize = filesize($streamFilePath);

// HTTP Streaming Headers
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');

// Stream file
readfile($streamFilePath);
exit;
