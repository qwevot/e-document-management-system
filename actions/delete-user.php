<?php
// DELETE: Removes a user account. Super admin only; cannot delete yourself or last super admin.
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role('super admin');
require_csrf('users');

$pdo = db();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_to('users', 'error', 'Invalid user.');
}

$currentUserId = (int)(current_user()['id'] ?? 0);
if ($id === $currentUserId) {
    log_activity('user.delete_failed', 'user', $id, ['reason' => 'Attempted self-deletion']);
    redirect_to('users', 'error', 'You cannot delete your own account.');
}

$st = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
$st->execute([$id]);
$targetUser = $st->fetch(PDO::FETCH_ASSOC);
if (!$targetUser) {
    redirect_to('users', 'error', 'User not found.');
}
$username = $targetUser['username'];

if ($targetUser['role'] === 'super admin') {
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super admin'")->fetchColumn();
    if ($adminCount <= 1) {
        log_activity('user.delete_failed', 'user', $id, ['username' => $username, 'reason' => 'Cannot delete sole Super Administrator']);
        redirect_to('users', 'error', 'Cannot delete the sole Super Administrator account.');
    }
}

$activeRevStmt = $pdo->prepare("
    SELECT COUNT(*) FROM dms_document_reviews r
    JOIN dms_nodes d ON r.document_id = d.id
    WHERE r.reviewer_id = ? AND r.status IN ('pending', 'waiting') AND d.status = 'hold' AND d.is_deleted = 0
");
$activeRevStmt->execute([$id]);
if ((int)$activeRevStmt->fetchColumn() > 0) {
    log_activity('user.delete_failed', 'user', $id, ['username' => $username, 'reason' => 'User has active pending document reviews']);
    redirect_to('users', 'error', 'Cannot delete user while they have active pending document reviews.');
}

$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

log_activity('user.delete', 'user', $id, [
    'username' => $username,
    'role'     => $targetUser['role'] ?? 'viewer'
]);

redirect_to('users', 'success', 'User "' . $username . '" removed successfully.');
