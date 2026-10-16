<?php
// Dedicated Secure In-Browser Streaming Endpoint
// Streams the document specimen for inline viewing (PDFs, images, etc.)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
session_write_close();

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

// Support streaming specific revision, or resolve latest active revision
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
    }
} else {
    $cvStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE document_id = :did ORDER BY is_current DESC, id DESC LIMIT 1");
    $cvStmt->execute(['did' => $nodeId]);
    $cvDoc = $cvStmt->fetch(PDO::FETCH_ASSOC);
    if ($cvDoc) {
        $doc['file_path'] = $cvDoc['file_path'];
        $doc['file_original_name'] = $cvDoc['file_original_name'];
        $doc['file_size'] = $cvDoc['file_size'];
        $doc['file_extension'] = $cvDoc['file_extension'];
        $doc['mime_type'] = $cvDoc['mime_type'];
    }
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
$officeExtensions = ['docx', 'doc', 'rtf', 'odt', 'xlsx', 'xls', 'csv', 'pptx', 'ppt', 'txt'];
$imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'];
$isOffice = in_array($ext, $officeExtensions, true);
$previewRequested = (isset($_GET['preview']) && (string)$_GET['preview'] === '1');

// If preview is requested for an office document and LibreOffice is available, stream as converted vector PDF
if (($previewRequested || $isOffice) && $ext !== 'pdf' && !in_array($ext, $imageExtensions, true)) {
    if (is_libreoffice_available()) {
        $pdfPath = convert_document_to_pdf($physicalFilePath);
        if ($pdfPath && file_exists($pdfPath) && is_readable($pdfPath)) {
            $physicalFilePath = $pdfPath;
            $mimeType = 'application/pdf';
            $ext = 'pdf';
        }
    }
}

$mimeType = !empty($mimeType) ? $mimeType : (!empty($doc['mime_type']) ? $doc['mime_type'] : null);

// Determine MIME type with high accuracy for inline streaming
if (!$mimeType || $mimeType === 'application/octet-stream') {
    $mimeType = match ($ext) {
        'pdf'               => 'application/pdf',
        'docx'              => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'               => 'application/msword',
        'xlsx'              => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'               => 'application/vnd.ms-excel',
        'pptx'              => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ppt'               => 'application/vnd.ms-powerpoint',
        'odt'               => 'application/vnd.oasis.opendocument.text',
        'ods'               => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'               => 'application/vnd.oasis.opendocument.presentation',
        'rtf'               => 'application/rtf',
        'png'               => 'image/png',
        'jpg', 'jpeg'       => 'image/jpeg',
        'gif'               => 'image/gif',
        'webp'              => 'image/webp',
        'svg'               => 'image/svg+xml',
        'txt', 'log', 'md'  => 'text/plain; charset=utf-8',
        'html', 'htm'       => 'text/plain; charset=utf-8',
        default             => 'application/octet-stream',
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

$mtime = filemtime($physicalFilePath);
$etag = '"' . md5($physicalFilePath . '_' . $mtime . '_' . $fileSize) . '"';

// Conditional HTTP 304 Validation
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if (($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) || 
    ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
    http_response_code(304);
    exit;
}

// Range request handling (RFC 7233 Byte Serving)
$start = 0;
$end = $fileSize - 1;
$isRangeRequest = false;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\s*(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches)) {
    $rangeStart = $matches[1];
    $rangeEnd   = $matches[2];

    if ($rangeStart === '' && $rangeEnd !== '') {
        // Suffix byte range: e.g. bytes=-500 (last 500 bytes)
        $start = $fileSize - (int)$rangeEnd;
        if ($start < 0) {
            $start = 0;
        }
    } elseif ($rangeStart !== '' && $rangeEnd === '') {
        // Range from start offset to EOF: e.g. bytes=500-
        $start = (int)$rangeStart;
    } elseif ($rangeStart !== '' && $rangeEnd !== '') {
        // Explicit range: e.g. bytes=500-1000
        $start = (int)$rangeStart;
        $end   = (int)$rangeEnd;
    }

    if ($start <= $end && $start < $fileSize) {
        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }
        $isRangeRequest = true;
    } else {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }
}

$contentLength = $end - $start + 1;

if ($isRangeRequest) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
} else {
    http_response_code(200);
}

// Set HTTP inline headers
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . rawurlencode($inlineFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($inlineFilename));
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $contentLength);
header('Accept-Ranges: bytes');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
if ($versionId > 0) {
    header('Cache-Control: private, max-age=86400, must-revalidate');
} else {
    header('Cache-Control: private, no-cache, must-revalidate');
}
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

// Stream physical file slice
$fp = fopen($physicalFilePath, 'rb');
if (!$fp) {
    http_response_code(500);
    die('Unable to open file for streaming.');
}

if ($start > 0) {
    fseek($fp, $start);
}

$bytesRemaining = $contentLength;
$bufferSize = 65536; // 64 KB chunk

while ($bytesRemaining > 0 && !feof($fp) && !connection_aborted()) {
    $readLength = min($bufferSize, $bytesRemaining);
    $data = fread($fp, $readLength);
    if ($data === false) {
        break;
    }
    echo $data;
    flush();
    $bytesRemaining -= strlen($data);
}

fclose($fp);
exit;
