<?php
/**
 * Document Marginalia Comments Handler
 * Manages page-level margin comments, threaded replies, and resolutions.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contributors.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_valid()) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed (CSRF invalid or expired).']);
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = db();

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

$action = $_REQUEST['action'] ?? $jsonInput['action'] ?? '';
$docId  = (int)($_REQUEST['doc_id'] ?? $jsonInput['doc_id'] ?? 0);

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
    exit;
}

// Verify document exists and is not deleted
$docStmt = $pdo->prepare("SELECT id, name, status, access_mode, created_by FROM dms_nodes WHERE id = :id AND node_type = 'document' AND is_deleted = 0");
$docStmt->execute(['id' => $docId]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found or inaccessible.']);
    exit;
}

// -------------------------------------------------------------
// 1. Fetch Comments Grouped by Page
// -------------------------------------------------------------
if ($action === 'get_comments') {
    $verId = isset($_GET['version_id']) && is_numeric($_GET['version_id']) && (int)$_GET['version_id'] > 0 ? (int)$_GET['version_id'] : null;

    $sql = "
        SELECT c.*, 
               COALESCE(u.full_name, u.username, 'Collaborator') AS author_name,
               u.username, u.role, u.avatar,
               ru.full_name AS resolver_name
        FROM dms_document_comments c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN users ru ON c.resolved_by = ru.id
        WHERE c.document_id = :doc_id
    ";
    $params = ['doc_id' => $docId];

    if ($verId !== null) {
        $sql .= " AND (c.version_id = :ver_id OR c.version_id IS NULL)";
        $params['ver_id'] = $verId;
    }
    $sql .= " ORDER BY c.page_number ASC, c.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $commentsByPage = [];
    $parentsMap = [];

    foreach ($rows as $row) {
        $cid = (int)$row['id'];
        $pageNum = (int)$row['page_number'];
        $pid = !empty($row['parent_id']) ? (int)$row['parent_id'] : 0;

        $item = [
            'id'          => $cid,
            'page_number' => $pageNum,
            'parent_id'   => $pid > 0 ? $pid : null,
            'user_id'     => (int)$row['user_id'],
            'author_name' => $row['author_name'],
            'username'    => $row['username'],
            'role'        => $row['role'],
            'avatar'      => $row['avatar'],
            'initials'    => get_initials($row['author_name']),
            'content'     => $row['content'],
            'is_resolved' => (bool)$row['is_resolved'],
            'resolver'    => $row['resolver_name'],
            'created_at'  => date('M j, H:i', strtotime($row['created_at'])),
            'is_self'     => ((int)$row['user_id'] === $userId),
            'replies'     => []
        ];

        if ($pid === 0) {
            $parentsMap[$cid] = $item;
            $commentsByPage[$pageNum] = $commentsByPage[$pageNum] ?? [];
            $commentsByPage[$pageNum][] = &$parentsMap[$cid];
        } else {
            if (isset($parentsMap[$pid])) {
                $parentsMap[$pid]['replies'][] = $item;
            }
        }
    }

    echo json_encode(['success' => true, 'doc_id' => $docId, 'comments_by_page' => $commentsByPage]);
    exit;
}

// -------------------------------------------------------------
// 2. Add Comment or Reply
// -------------------------------------------------------------
if ($action === 'add_comment' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!can_user_annotate_document($doc, $userId)) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. You cannot annotate this document.']);
        exit;
    }

    $data = !empty($jsonInput) ? $jsonInput : $_POST;
    $content = trim($data['content'] ?? '');
    $pageNum = max(1, (int)($data['page_number'] ?? 1));
    $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
    $verId = !empty($data['version_id']) ? (int)$data['version_id'] : null;

    if ($content === '') {
        echo json_encode(['success' => false, 'message' => 'Comment cannot be empty.']);
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO dms_document_comments (document_id, version_id, page_number, parent_id, user_id, content, created_at, updated_at)
        VALUES (:doc_id, :ver_id, :page, :parent_id, :user_id, :content, NOW(), NOW())
    ");
    $ins->execute([
        'doc_id'    => $docId,
        'ver_id'    => $verId,
        'page'      => $pageNum,
        'parent_id' => $parentId,
        'user_id'   => $userId,
        'content'   => $content
    ]);
    $newId = (int)$pdo->lastInsertId();

    $authorName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'User';

    // 1. Log in dms_annotation_history
    $histStmt = $pdo->prepare("
        INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details, created_at)
        VALUES (:doc_id, :ver_id, :user_id, 'create', 'comment', :page, :details, NOW())
    ");
    $histStmt->execute([
        'doc_id'  => $docId,
        'ver_id'  => $verId,
        'user_id' => $userId,
        'page'    => $pageNum,
        'details' => ($parentId ? 'Replied to note: ' : 'Added page note: ') . '"' . mb_strimwidth($content, 0, 45, '...') . '"'
    ]);

    // 2. Log in system_logs
    log_activity('document.comment_add', 'document', $docId, [
        'comment_id'  => $newId,
        'page_number' => $pageNum,
        'is_reply'    => ($parentId !== null)
    ], 'success');

    echo json_encode([
        'success' => true,
        'comment' => [
            'id'          => $newId,
            'page_number' => $pageNum,
            'parent_id'   => $parentId,
            'user_id'     => $userId,
            'author_name' => $authorName,
            'username'    => $currentUser['username'] ?? '',
            'role'        => $currentUser['role'] ?? 'viewer',
            'avatar'      => $currentUser['avatar'] ?? null,
            'initials'    => get_initials($authorName),
            'content'     => $content,
            'is_resolved' => false,
            'created_at'  => date('M j, H:i'),
            'is_self'     => true,
            'replies'     => []
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. Resolve / Unresolve Comment
// -------------------------------------------------------------
if ($action === 'resolve_comment' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = !empty($jsonInput) ? $jsonInput : $_POST;
    $commentId = (int)($data['comment_id'] ?? 0);

    $find = $pdo->prepare("SELECT id, user_id, is_resolved FROM dms_document_comments WHERE id = :id AND document_id = :did");
    $find->execute(['id' => $commentId, 'did' => $docId]);
    $comment = $find->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        echo json_encode(['success' => false, 'message' => 'Comment not found.']);
        exit;
    }

    $userRole = strtolower(trim($currentUser['role'] ?? 'viewer'));
    $isAdmin = in_array($userRole, ['super admin', 'admin'], true);
    $isOwner = ((int)$doc['created_by'] === $userId);
    $isAuthor = ((int)$comment['user_id'] === $userId);

    if (!$isAdmin && !$isOwner && !$isAuthor && !is_document_contributor($docId, $userId)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to resolve this comment.']);
        exit;
    }

    $newResolved = ((int)$comment['is_resolved'] === 1) ? 0 : 1;
    $upd = $pdo->prepare("
        UPDATE dms_document_comments 
        SET is_resolved = :resolved, resolved_by = :res_by, resolved_at = :res_at, updated_at = NOW() 
        WHERE id = :id
    ");
    $upd->execute([
        'resolved' => $newResolved,
        'res_by'   => $newResolved ? $userId : null,
        'res_at'   => $newResolved ? date('Y-m-d H:i:s') : null,
        'id'       => $commentId
    ]);

    log_activity('document.comment_resolve', 'document', $docId, [
        'comment_id'  => $commentId,
        'is_resolved' => (bool)$newResolved
    ], 'success');

    echo json_encode(['success' => true, 'comment_id' => $commentId, 'is_resolved' => (bool)$newResolved]);
    exit;
}

// -------------------------------------------------------------
// 4. Delete Comment (Strictly Author Only)
// -------------------------------------------------------------
if ($action === 'delete_comment' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = !empty($jsonInput) ? $jsonInput : $_POST;
    $commentId = (int)($data['comment_id'] ?? 0);

    $find = $pdo->prepare("SELECT id, user_id, content, page_number, version_id FROM dms_document_comments WHERE id = :id AND document_id = :did");
    $find->execute(['id' => $commentId, 'did' => $docId]);
    $comment = $find->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        echo json_encode(['success' => false, 'message' => 'Comment not found.']);
        exit;
    }

    // Strictly only the commenter who wrote the note can delete it
    if ((int)$comment['user_id'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only the original commenter can delete this comment.']);
        exit;
    }

    // Cascade delete comment and any nested replies
    $del = $pdo->prepare("DELETE FROM dms_document_comments WHERE id = :id OR parent_id = :id");
    $del->execute(['id' => $commentId]);

    // 1. Log in dms_annotation_history
    $histStmt = $pdo->prepare("
        INSERT INTO dms_annotation_history (document_id, version_id, user_id, action_type, tool_type, page_number, details, created_at)
        VALUES (:doc_id, :ver_id, :user_id, 'delete', 'comment', :page, :details, NOW())
    ");
    $histStmt->execute([
        'doc_id'  => $docId,
        'ver_id'  => $comment['version_id'],
        'user_id' => $userId,
        'page'    => $comment['page_number'],
        'details' => 'Deleted note: "' . mb_strimwidth($comment['content'], 0, 45, '...') . '"'
    ]);

    // 2. Log in system_logs
    log_activity('document.comment_delete', 'document', $docId, [
        'comment_id'  => $commentId,
        'page_number' => $comment['page_number']
    ], 'success');

    echo json_encode(['success' => true, 'comment_id' => $commentId]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
