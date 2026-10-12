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
               COALESCE(u.full_name, u.username, 'System') AS author_name,
               u.avatar AS author_avatar
        FROM dms_document_versions v
        LEFT JOIN users u ON v.created_by = u.id
        WHERE v.document_id = :doc_id
        ORDER BY v.id DESC
    ");
    $stmt->execute(['doc_id' => $docId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create and register a new version revision for a document.
 * Standard uploads always increment the minor version (e.g., v0.1 -> v0.2, v1.0 -> v1.1).
 */
function create_document_version(
    int $docId,
    array $fileData,
    string $bumpType = 'minor',
    ?string $changeSummary = null,
    int $userId = 1
): array {
    $pdo = db();

    // Verify document exists
    $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document'");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.'];
    }

    if ($doc['status'] === 'hold') {
        return [
            'success' => false, 
            'message' => 'Document is currently frozen on Hold for formal review. Retract the review submission or wait for reviewer sign-off before uploading new revisions.'
        ];
    }

    if ($doc['status'] === 'obsolete') {
        return ['success' => false, 'message' => 'Cannot upload revisions to an obsolete document.'];
    }

    // Always increment minor version for regular specimen revisions
    $currentVer = $doc['version'] ?: 'v0.1';
    $nextVer = calculate_next_version($currentVer, 'minor');
    $summary = trim($changeSummary ?? '') ?: ("Revision update to " . $nextVer);

    // If document was published, new revision sets status to active (working draft)
    $newStatus = 'active';

    $pdo->beginTransaction();
    try {
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
    $stmt = $pdo->prepare("SELECT * FROM dms_nodes WHERE id = :id AND node_type = 'document'");
    $stmt->execute(['id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.'];
    }

    $currentVer = $doc['version'] ?: 'v0.1';
    $nextMajorVer = calculate_next_version($currentVer, 'major');
    $summary = trim($publishNotes ?? '') ?: ("Official publication release " . $nextMajorVer);

    $pdo->beginTransaction();
    try {
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

/**
 * Revert a document to an earlier version snapshot.
 */
function revert_document_version(int $docId, int $targetVersionId, int $userId = 1): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT * FROM dms_document_versions 
        WHERE id = :vid AND document_id = :did
    ");
    $stmt->execute(['vid' => $targetVersionId, 'did' => $docId]);
    $targetVer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetVer) {
        return ['success' => false, 'message' => 'Target revision not found.'];
    }

    $pdo->beginTransaction();
    try {
        // Reset all versions current flag
        $updOld = $pdo->prepare("UPDATE dms_document_versions SET is_current = 0 WHERE document_id = :did");
        $updOld->execute(['did' => $docId]);

        // Mark target version as current
        $updTarget = $pdo->prepare("UPDATE dms_document_versions SET is_current = 1 WHERE id = :vid");
        $updTarget->execute(['vid' => $targetVersionId]);

        // Update active document node metadata to match target version
        $updDoc = $pdo->prepare("
            UPDATE dms_nodes 
            SET version = :version,
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
            'version'            => $targetVer['version_number'],
            'file_path'          => $targetVer['file_path'],
            'file_original_name' => $targetVer['file_original_name'],
            'file_size'          => $targetVer['file_size'],
            'file_extension'     => $targetVer['file_extension'],
            'mime_type'          => $targetVer['mime_type'],
            'file_hash'          => $targetVer['file_hash'],
            'id'                 => $docId
        ]);

        $pdo->commit();

        return [
            'success' => true,
            'version' => $targetVer['version_number'],
            'message' => "Document successfully restored to {$targetVer['version_number']}"
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Reversion failed: ' . $e->getMessage()];
    }
}
