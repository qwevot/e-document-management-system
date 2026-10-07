<?php
// Dedicated Secure In-Browser Streaming Endpoint
// Streams the document specimen for inline viewing (PDFs, images, etc.)

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

// Check physical file existence
$physicalFilePath = __DIR__ . '/../' . ltrim($doc['file_path'], '/\\');

if (!file_exists($physicalFilePath) || !is_readable($physicalFilePath)) {
    http_response_code(404);
    die('Physical file does not exist on disk.');
}

// Clean output buffers to prevent corruption in binary stream
if (ob_get_level()) {
    ob_end_clean();
}

$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$mimeType = !empty($doc['mime_type']) ? $doc['mime_type'] : null;

// Determine MIME type with high accuracy for inline streaming
if (!$mimeType || $mimeType === 'application/octet-stream') {
    $mimeType = match ($ext) {
        'pdf'               => 'application/pdf',
        'png'               => 'image/png',
        'jpg', 'jpeg'       => 'image/jpeg',
        'gif'               => 'image/gif',
        'webp'              => 'image/webp',
        'svg'               => 'image/svg+xml',
        'txt', 'log', 'md'  => 'text/plain; charset=utf-8',
        'html', 'htm'       => 'text/plain; charset=utf-8', // Plain text for security
        default             => 'application/pdf',
    };
}

$fileSize = filesize($physicalFilePath);

// Construct clean inline filename
$baseName = trim($doc['name'] ?? '');
if ($baseName === '') {
    $baseName = $doc['file_original_name'] ?: 'document';
}
$sanitizedBaseName = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $baseName);
if ($ext !== '' && !str_ends_with(strtolower($sanitizedBaseName), '.' . $ext)) {
    $inlineFilename = $sanitizedBaseName . '.' . $ext;
} else {
    $inlineFilename = $sanitizedBaseName;
}

// Set HTTP inline headers
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . rawurlencode($inlineFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($inlineFilename));
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

// Stream physical file
readfile($physicalFilePath);
exit;
