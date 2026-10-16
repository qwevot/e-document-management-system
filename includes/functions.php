<?php
// Core Helper Functions

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/converter.php';
require_once __DIR__ . '/versioning.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/contributors.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Escape HTML output safely.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Retrieve current logged in user data.
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id']) && empty($_SESSION['user'])) {
        return null;
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }

    if (!empty($_SESSION['user_id'])) {
        try {
            $st = db()->prepare('SELECT id, username, full_name, role, avatar FROM users WHERE id = ?');
            $st->execute([$_SESSION['user_id']]);
            $row = $st->fetch();
            if ($row !== false) {
                $_SESSION['user'] = $row;
                return $row;
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

/**
 * Ensure user is authenticated, otherwise redirect to login page.
 */
function require_login(): void
{
    if (current_user() === null) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Retrieve or generate CSRF token.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render hidden CSRF form input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Validate CSRF token from POST or headers.
 */
function csrf_valid(): bool
{
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';

    if ($token === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string)$k, 'X-CSRF-Token') === 0) {
                    $token = $v;
                    break;
                }
            }
        }
    }

    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Retrieve current user role.
 */
function current_role(): string
{
    $user = current_user();
    return strtolower($user['role'] ?? 'viewer');
}

/**
 * Check if user has Super Admin authority.
 */
function is_super_admin(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!$u) return false;
    $role = strtolower(trim($u['role'] ?? ''));
    return in_array($role, ['super admin', 'super_admin', 'superadmin'], true);
}

/**
 * Ensure valid CSRF token on POST, or redirect back with error.
 */
function require_csrf(string $fallbackPage = 'profile'): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
        redirect_to($fallbackPage, 'error', 'Security validation failed (CSRF token invalid or expired). Please try again.');
    }
}

/**
 * Redirect to a page with a flash parameter.
 */
function redirect_to(string $page, string $key, string $message): void
{
    header('Location: ' . BASE_URL . '/pages/' . $page . '.php?' . $key . '=' . urlencode($message));
    exit;
}

/**
 * Safe database connection wrapper with page redirect on failure.
 */
function html_db(string $page): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        redirect_to($page, 'error', 'Database connection failed.');
    }
}

/**
 * Save an uploaded avatar image with square crop and resize.
 */
function save_avatar_upload(array $file, int $userId): ?string
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $avatarDir = UPLOAD_DIR . 'avatars/';
    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0755, true)) {
        return null;
    }

    $tmpPath = $file['tmp_name'];
    $imgInfo = @getimagesize($tmpPath);
    if (!$imgInfo) {
        return null;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($imgInfo['mime'], $allowedMimes, true)) {
        return null;
    }

    $data = @file_get_contents($tmpPath);
    if ($data === false) {
        return null;
    }

    $srcImg = @imagecreatefromstring($data);
    if (!$srcImg) {
        return null;
    }

    // Square crop / resize avatar to max 512x512
    $origW = imagesx($srcImg);
    $origH = imagesy($srcImg);
    $minDim = min($origW, $origH);
    $srcX = (int)(($origW - $minDim) / 2);
    $srcY = (int)(($origH - $minDim) / 2);

    $targetDim = min(512, $minDim);
    $dstImg = imagecreatetruecolor($targetDim, $targetDim);
    imagecopyresampled($dstImg, $srcImg, 0, 0, $srcX, $srcY, $targetDim, $targetDim, $minDim, $minDim);

    $filename = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $destPath = $avatarDir . $filename;

    imagejpeg($dstImg, $destPath, 85);
    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return UPLOAD_URL . 'avatars/' . $filename;
}

/**
 * Delete avatar file from disk.
 */
function delete_avatar(?string $avatar): void
{
    if ($avatar) {
        $base = basename($avatar);
        $path = UPLOAD_DIR . 'avatars/' . $base;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Generate 1-2 character initials for user avatars.
 */
function get_initials(?string $name): string
{
    $name = trim($name ?? '');
    if ($name === '') {
        return 'U';
    }
    $parts = preg_split('/\s+/', $name);
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
    }
    return $initials !== '' ? $initials : 'U';
}

/**
 * Render small 22px avatar element with image fallback to initials.
 */
function render_user_avatar_sm(?string $name, ?string $avatar): string
{
    $initials = e(get_initials($name));
    if (!empty($avatar)) {
        $src = e(BASE_URL . '/' . ltrim($avatar, '/\\'));
        return '<span class="user-avatar-sm"><img src="' . $src . '" alt="" loading="lazy" onerror="this.remove()">' . $initials . '</span>';
    }
    return '<span class="user-avatar-sm">' . $initials . '</span>';
}

/**
 * High-performance recursive descendant node ID collector using CTE
 * Collects all nested descendant IDs of given root IDs in a single query.
 */
function get_descendant_node_ids(PDO $pdo, array $nodeIds, bool $activeOnly = false): array
{
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $nodeIds))));
    if (empty($cleanIds)) {
        return [];
    }

    $inClause = implode(',', $cleanIds);
    $activeFilter = $activeOnly ? 'AND is_deleted = 0' : '';

    try {
        $sql = "
            WITH RECURSIVE node_tree AS (
                SELECT id FROM dms_nodes WHERE id IN ($inClause)
                UNION ALL
                SELECT n.id FROM dms_nodes n
                INNER JOIN node_tree t ON n.parent_id = t.id
                WHERE 1=1 $activeFilter
            )
            SELECT id FROM node_tree
        ";
        $stmt = $pdo->query($sql);
        $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('intval', $allIds)));
    } catch (\Throwable $e) {
        // Graceful fallback for non-CTE or legacy setups
        $all = $cleanIds;
        $queue = $cleanIds;
        while (!empty($queue)) {
            $currId = array_shift($queue);
            $q = "SELECT id FROM dms_nodes WHERE parent_id = " . (int)$currId . " $activeFilter";
            $cIds = $pdo->query($q)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cIds as $cid) {
                $cInt = (int)$cid;
                if (!in_array($cInt, $all, true)) {
                    $all[] = $cInt;
                    $queue[] = $cInt;
                }
            }
        }
        return $all;
    }
}

/**
 * Checks whether an active item with the same name exists under the given parent folder.
 */
function is_node_duplicate(PDO $pdo, ?int $parentId, string $name, ?int $excludeId = null): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    if ($parentId !== null) {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM dms_nodes WHERE parent_id = :pid AND LOWER(name) = LOWER(:name) AND id != :eid AND is_deleted = 0 LIMIT 1");
            $stmt->execute(['pid' => $parentId, 'name' => $name, 'eid' => $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM dms_nodes WHERE parent_id = :pid AND LOWER(name) = LOWER(:name) AND is_deleted = 0 LIMIT 1");
            $stmt->execute(['pid' => $parentId, 'name' => $name]);
        }
    } else {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM dms_nodes WHERE parent_id IS NULL AND LOWER(name) = LOWER(:name) AND id != :eid AND is_deleted = 0 LIMIT 1");
            $stmt->execute(['name' => $name, 'eid' => $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM dms_nodes WHERE parent_id IS NULL AND LOWER(name) = LOWER(:name) AND is_deleted = 0 LIMIT 1");
            $stmt->execute(['name' => $name]);
        }
    }

    return (bool)$stmt->fetchColumn();
}

/**
 * Audit log helper: Records system actions into system_logs.
 */
function log_activity(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = [],
    string $status = 'success',
    ?int $userId = null,
    ?string $username = null,
    ?string $role = null
): bool {
    try {
        $pdo = db();
        if ($userId === null) {
            $user = current_user();
            if ($user) {
                $userId = (int)($user['id'] ?? 0) ?: null;
                $username = $username ?? ($user['username'] ?? null);
                $role = $role ?? ($user['role'] ?? null);
            }
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ip = substr($ip, 0, 45);

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $detailsJson = !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, username, role, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
            VALUES (:user_id, :username, :role, :action, :entity_type, :entity_id, :details, :ip_address, :user_agent, NOW())
        ");

        return $stmt->execute([
            'user_id'     => $userId,
            'username'    => $username ? substr($username, 0, 50) : null,
            'role'        => $role ? substr($role, 0, 50) : null,
            'action'      => substr($action, 0, 100),
            'entity_type' => $entityType ? substr($entityType, 0, 50) : null,
            'entity_id'   => $entityId,
            'details'     => $detailsJson,
            'ip_address'  => $ip,
            'user_agent'  => $ua
        ]);
    } catch (\Throwable $e) {
        error_log("Failed to write system log: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieve consolidated audit trail events & vertical timeline HTML for a document.
 */
function get_document_audit_trail(int $docId): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT l.id, l.role, l.action, l.created_at,
               COALESCE(u.full_name, l.username, 'System') AS user_full_name
        FROM system_logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE l.entity_type = 'document' AND l.entity_id = :did
          AND l.action IN (
              'document.upload', 'version.upload', 'version.revert',
              'document.review_requested', 'document.review_approved', 'document.review_rejected', 
              'document.published', 'document.review_cancelled', 'document.access_updated'
          )
        ORDER BY l.id DESC
    ");
    $stmt->execute(['did' => $docId]);
    $rawLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    $htmlItems = '';

    foreach ($rawLogs as $log) {
        $action = $log['action'];

        [$actionWord, $dotClass] = match ($action) {
            'document.upload'           => ['Uploaded', 'badge-publish'],
            'version.upload',
            'version.revert'            => ['Updated', 'badge-active'],
            'document.access_updated'   => ['Shared', 'badge-active'],
            'document.review_requested' => ['Submitted', 'badge-hold'],
            'document.review_rejected'  => ['Rejected', 'badge-rejected'],
            'document.review_approved',
            'document.published'        => ['Accepted', 'badge-publish'],
            'document.review_cancelled' => ['Updated', 'badge-hold'],
            default                     => ['Updated', 'badge-active']
        };

        $userName = htmlspecialchars($log['user_full_name'] ?? 'System', ENT_QUOTES, 'UTF-8');
        $formattedTime = date('M j, Y • H:i', strtotime($log['created_at']));

        $htmlItems .= "
        <div class=\"audit-timeline-item\">
            <span class=\"audit-timeline-dot {$dotClass}\"></span>
            <div class=\"audit-timeline-content\">
                <div style=\"font-size: 12.5px; color: var(--ink); line-height: 1.4;\">
                    <strong>{$actionWord}</strong> by <strong>{$userName}</strong>
                </div>
                <div class=\"history-time\" style=\"font-size: 10.5px; color: var(--muted); margin-top: 2px;\">{$formattedTime}</div>
            </div>
        </div>";

        $events[] = [
            'id'             => (int)$log['id'],
            'action'         => $actionWord,
            'user_name'      => $userName,
            'formatted_time' => $formattedTime
        ];
    }

    $finalHtml = $htmlItems !== '' 
        ? "<div class=\"audit-timeline\">{$htmlItems}</div>" 
        : '<div class="history-empty-state"><span>No audit events recorded yet.</span></div>';

    return [
        'count'  => count($events),
        'events' => $events,
        'html'   => $finalHtml
    ];
}
