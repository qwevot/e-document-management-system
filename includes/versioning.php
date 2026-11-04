<?php
// Document Versioning & Revision Lineage Module

require_once __DIR__ . '/db.php';

/**
 * Calculate next semantic version number.
 * @param string $current e.g. "v1.0", "1.2", "v0.1"
 * @param string $type "minor" or "major"
 */
function calculate_next_version(string $current, string $type = 'minor'): string
{
    $clean = ltrim(trim($current), 'vV');
    $parts = explode('.', $clean);
    $major = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 1;
    $minor = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;

    if ($type === 'major') {
        $major++;
        $minor = 0;
    } else {
        $minor++;
    }

    return "v{$major}.{$minor}";
}

/**
 * Retrieve full version history of a document.
 */
function get_document_versions(int $docId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT v.*, 
               n.status AS doc_status,
               COALESCE(u.full_name, u.username, 'System') AS author_name,
               u.avatar AS author_avatar
        FROM dms_document_versions v
        LEFT JOIN dms_nodes n ON v.document_id = n.id
        LEFT JOIN users u ON v.created_by = u.id
        WHERE v.document_id = :doc_id
        ORDER BY v.id DESC
    ");
    $stmt->execute(['doc_id' => $docId]);
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($versions as &$v) {
        if ((!empty($v['is_current']) && ($v['doc_status'] ?? '') === 'suspend') || str_contains($v['change_summary'] ?? '', '(SUSPENDED)')) {
            $v['review_status'] = 'suspended';
        } elseif ((!empty($v['is_current']) && ($v['doc_status'] ?? '') === 'published') || (($v['review_status'] ?? '') === 'approved' && preg_match('/\.0$/', $v['version_number'] ?? ''))) {
            $v['review_status'] = 'published';
        }
    }
    unset($v);

    return $versions;
}

/**
 * Create and register a new version revision for a document.
 * Uploading revisions strictly increments the minor version (e.g., v0.1 -> v0.2, v1.0 -> v1.1).
 * Major version bumps are reserved strictly for official publication.
 */
function create_document_version(
    int $docId,
    array $fileData,
    ?string $changeSummary = null,
    int $userId = 1
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Verify and lock document row to serialize concurrent revision uploads
        $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document' FOR UPDATE");
        $stmt->execute(['id' => $docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc || in_array($doc['status'], ['obsolete', 'hold'], true)) {
            $pdo->rollBack();
            $msg = !$doc ? 'Document not found.' : ($doc['status'] === 'obsolete' ? 'Cannot upload revisions to an obsolete document.' : 'Document is frozen on hold for review. Revisions cannot be uploaded at this stage.');
            return ['success' => false, 'message' => $msg];
        }

        // Always increment minor version for regular specimen revisions
        $currentVer = $doc['version'] ?: 'v0.1';
        $nextVer = calculate_next_version($currentVer, 'minor');
        $summary = trim($changeSummary ?? '') ?: ("Revision update to " . $nextVer);
        $newStatus = 'active';
        // Set existing versions to is_current = 0
        $updOld = $pdo->prepare("UPDATE dms_document_versions SET is_current = 0 WHERE document_id = :doc_id");
        $updOld->execute(['doc_id' => $docId]);

        // Insert new version record
        $ins = $pdo->prepare("
            INSERT INTO dms_document_versions 
            (document_id, version_number, file_path, file_original_name, file_size, file_extension, mime_type, file_hash, change_summary, is_current, created_by, created_at)
            VALUES 
            (:document_id, :version_number, :file_path, :file_original_name, :file_size, :file_extension, :mime_type, :file_hash, :change_summary, 1, :created_by, NOW())
        ");
        $ins->execute([
            'document_id'        => $docId,
            'version_number'     => $nextVer,
            'file_path'          => $fileData['file_path'],
            'file_original_name' => $fileData['file_original_name'],
            'file_size'          => $fileData['file_size'],
            'file_extension'     => $fileData['file_extension'],
            'mime_type'          => $fileData['mime_type'],
            'file_hash'          => $fileData['file_hash'],
            'change_summary'     => $summary,
            'created_by'         => $userId
        ]);
        $newVersionId = (int)$pdo->lastInsertId();

        // Update active document node metadata
        $updDoc = $pdo->prepare("
            UPDATE dms_nodes 
            SET version = :version,
                status = :status,
                file_path = :file_path,
                file_original_name = :file_original_name,
                file_size = :file_size,
                file_extension = :file_extension,
                mime_type = :mime_type,
                file_hash = :file_hash,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updDoc->execute([
            'version'            => $nextVer,
            'status'             => $newStatus,
            'file_path'          => $fileData['file_path'],
            'file_original_name' => $fileData['file_original_name'],
            'file_size'          => $fileData['file_size'],
            'file_extension'     => $fileData['file_extension'],
            'mime_type'          => $fileData['mime_type'],
            'file_hash'          => $fileData['file_hash'],
            'id'                 => $docId
        ]);

        $pdo->commit();

        return [
            'success'    => true,
            'version_id' => $newVersionId,
            'version'    => $nextVer,
            'status'     => $newStatus,
            'message'    => "Successfully updated to {$nextVer}"
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Failed to save revision: ' . $e->getMessage()];
    }
}

/**
 * Publish a document: transitions status to 'published' and bumps major version (e.g. v0.3 -> v1.0, v1.2 -> v2.0).
 */
function publish_document(int $docId, ?string $publishNotes = null, int $userId = 1): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document' FOR UPDATE");
        $stmt->execute(['id' => $docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc || $doc['status'] === 'published') {
            $pdo->rollBack();
            return $doc
                ? ['success' => true, 'version' => $doc['version'], 'status' => 'published', 'message' => "Document officially published as {$doc['version']}"]
                : ['success' => false, 'message' => 'Document not found.'];
        }

        $currentVer = $doc['version'] ?: 'v0.1';
        $nextMajorVer = calculate_next_version($currentVer, 'major');
        $summary = trim($publishNotes ?? '') ?: ("Official publication release " . $nextMajorVer);
        // Set existing versions is_current = 0
        $updOld = $pdo->prepare("UPDATE dms_document_versions SET is_current = 0 WHERE document_id = :doc_id");
        $updOld->execute(['doc_id' => $docId]);

        // Insert new major version record
        $ins = $pdo->prepare("
            INSERT INTO dms_document_versions 
            (document_id, version_number, file_path, file_original_name, file_size, file_extension, mime_type, file_hash, change_summary, is_current, review_status, created_by, created_at)
            VALUES 
            (:document_id, :version_number, :file_path, :file_original_name, :file_size, :file_extension, :mime_type, :file_hash, :change_summary, 1, 'approved', :created_by, NOW())
        ");
        $ins->execute([
            'document_id'        => $docId,
            'version_number'     => $nextMajorVer,
            'file_path'          => $doc['file_path'],
            'file_original_name' => $doc['file_original_name'],
            'file_size'          => $doc['file_size'],
            'file_extension'     => $doc['file_extension'],
            'mime_type'          => $doc['mime_type'],
            'file_hash'          => $doc['file_hash'],
            'change_summary'     => $summary,
            'created_by'         => $userId
        ]);
        $newVersionId = (int)$pdo->lastInsertId();

        // Update active document node status to published and version to major
        $updDoc = $pdo->prepare("
            UPDATE dms_nodes 
            SET status = 'published',
                version = :version,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updDoc->execute([
            'version' => $nextMajorVer,
            'id'      => $docId
        ]);

        $pdo->commit();

        return [
            'success'    => true,
            'version_id' => $newVersionId,
            'version'    => $nextMajorVer,
            'status'     => 'published',
            'message'    => "Document officially published as {$nextMajorVer}"
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Publishing failed: ' . $e->getMessage()];
    }
}
