<?php
// Dedicated Secure File Download Endpoint
// Streams the physical specimen to the client with clean original filename headers

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

$nodeId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($nodeId <= 0) {
    http_response_code(400);
    die('Invalid specimen identifier.');
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

// Check physical file existence
$physicalFilePath = __DIR__ . '/../' . ltrim($doc['file_path'], '/\\');

if (!file_exists($physicalFilePath) || !is_readable($physicalFilePath)) {
    http_response_code(404);
    die('Physical file does not exist on disk.');
}

// Determine clean download filename for user
$downloadName = $doc['file_original_name'] ?: ($doc['name'] . '.' . ($doc['file_extension'] ?: 'dat'));

// Clean output buffers to prevent corruption in binary stream
if (ob_get_level()) {
    ob_end_clean();
}

$mimeType = !empty($doc['mime_type']) ? $doc['mime_type'] : 'application/octet-stream';
$fileSize = filesize($physicalFilePath);

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
readfile($physicalFilePath);
exit;
