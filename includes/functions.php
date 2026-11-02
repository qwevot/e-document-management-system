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
 * Format document display name with its file extension (e.g., "Report.docx") without duplicating extensions.
 */
function format_document_display_name(?string $name, ?string $extension): string
{
    $base = trim($name ?? '');
    $cleanExt = ltrim(strtolower(trim($extension ?? '')), '.');
    if ($cleanExt === '') {
        return $base;
    }
    $suffix = '.' . $cleanExt;
    if ($base !== '' && str_ends_with(strtolower($base), $suffix)) {
        return $base;
    }
    return $base . $suffix;
}

/**
 * Retrieve current logged in user data with real-time database synchronization.
 */
function current_user(): ?array
{
    static $cachedUser = null;
    if ($cachedUser !== null) {
        return $cachedUser;
    }

    $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
    if (!$userId) {
        return null;
    }

    try {
        $st = db()->prepare('SELECT id, username, full_name, role, department, avatar, created_at FROM users WHERE id = ?');
        $st->execute([(int)$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $row['id'] = (int)$row['id'];
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['user'] = $row;
            $cachedUser = $row;
            return $row;
        }

        // User account was deleted from database; terminate session immediately
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return null;
    } catch (Throwable $e) {
        // Fallback to existing session cache if DB connection is unavailable
        return $_SESSION['user'] ?? null;
    }
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
 * Check if user has Administrator authority (Admin or Super Admin).
 */
function is_admin(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!$u) return false;
    $role = strtolower(trim($u['role'] ?? ''));
    return in_array($role, ['super admin', 'admin', 'super_admin', 'superadmin'], true);
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
 * Check if user has modification/mutation rights in the DMS.
 * Viewers are read-only specimens: strictly barred from creating, editing, uploading, or altering nodes.
 */
function can_edit_dms(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!$u) return false;
    $role = strtolower(trim($u['role'] ?? 'viewer'));
    return !in_array($role, ['viewer', 'guest'], true);
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
 * Map document status to its semantic CSS badge class.
 */
function get_status_class(string $status): string
{
    return match ($status) {
        'published' => 'badge-publish',
        'active'    => 'badge-active',
        'hold'      => 'badge-hold',
        'suspend'   => 'badge-suspend',
        'rejected'  => 'badge-rejected',
        'obsolete'  => 'badge-obsolete',
        default     => 'badge-active',
    };
}

/**
 * Validate, hash, and store an uploaded document file in an inert .bin container.
 */
function store_uploaded_document_file(array $file): array
{
    $originalName = $file['name'] ?? '';
    $fileSize     = (int)($file['size'] ?? 0);
    $tmpPath      = $file['tmp_name'] ?? '';

    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['success' => false, 'message' => 'Uploaded temporary file is missing or unreadable.'];
    }

    $fileHash = hash_file('sha256', $tmpPath);
    $fileExt  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($fileExt === '') {
        $fileExt = 'dat';
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = ($finfo ? finfo_file($finfo, $tmpPath) : false) ?: 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }

    // Block dangerous executable or server-side script uploads
    $blockedExtensions = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'ps1', 'vbs', 'msi', 'com', 'scr', 'hta', 'dll'];
    if (in_array($fileExt, $blockedExtensions, true) || in_array($mimeType, ['application/x-dosexec', 'application/x-msdownload', 'text/x-php'], true)) {
        return ['success' => false, 'message' => "Security policy: \".{$fileExt}\" executable or script files are not permitted."];
    }

    // Reconcile extension if a viewable format has a mismatched/spoofed extension
    $mimeToExt = [
        'application/pdf'                                                           => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'image/png'                                                                 => 'png',
        'image/jpeg'                                                                => 'jpg',
        'image/webp'                                                                => 'webp',
        'image/gif'                                                                 => 'gif',
    ];
    if ($fileExt === 'pdf' && $mimeType !== 'application/pdf') {
        if (isset($mimeToExt[$mimeType])) {
            $fileExt = $mimeToExt[$mimeType];
        } else {
            return ['success' => false, 'message' => 'Invalid PDF file: the uploaded file content does not match the PDF format.'];
        }
    } elseif ($fileExt === 'dat' && isset($mimeToExt[$mimeType])) {
        $fileExt = $mimeToExt[$mimeType];
    }

    $yearMonth = date('Y/m');
    $uploadDir = __DIR__ . '/../uploads/documents/' . $yearMonth;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $safeBaseName        = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeStorageName     = bin2hex(random_bytes(16)) . '_' . $safeBaseName . '.bin';
    $destinationPath     = $uploadDir . '/' . $safeStorageName;
    $relativeStoragePath = 'uploads/documents/' . $yearMonth . '/' . $safeStorageName;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        return ['success' => false, 'message' => 'Failed to store file in physical repository.'];
    }

    return [
        'success'            => true,
        'file_path'          => $relativeStoragePath,
        'full_path'          => $destinationPath,
        'file_original_name' => $originalName,
        'file_size'          => $fileSize,
        'file_extension'     => $fileExt,
        'mime_type'          => $mimeType,
        'file_hash'          => $fileHash,
    ];
}

/**
 * Render the shared Explorer / Archives pagination footer bar.
 */
function render_pagination_bar(
    int $page,
    int $perPage,
    int $totalCount,
    int $totalPages,
    string $selectId = 'paginationPerPageSelect',
    string $onPerPageChange = '',
    string $onPageClickFn = ''
): void {
    $startItem = $totalCount > 0 ? ($page - 1) * $perPage + 1 : 0;
    $endItem   = min($totalCount, $page * $perPage);
    $prevPage  = max(1, $page - 1);
    $nextPage  = min($totalPages, $page + 1);
    $window    = 2;
    $startPage = max(1, $page - $window);
    $endPage   = min($totalPages, $page + $window);
    $selectAttr = $onPerPageChange !== '' ? ' onchange="' . e($onPerPageChange) . '"' : '';
    $btnAttr = static function (int $targetPage) use ($onPageClickFn): string {
        $attr = 'data-page="' . $targetPage . '"';
        if ($onPageClickFn !== '') {
            $attr .= ' onclick="' . e($onPageClickFn) . '(' . $targetPage . ')"';
        }
        return $attr;
    };
    ?>
    <div class="explorer-pagination-bar">
        <div class="pagination-info">
            <span>Showing <strong><?php echo $startItem; ?>-<?php echo $endItem; ?></strong> of <strong><?php echo number_format($totalCount); ?></strong> items</span>
        </div>

        <div class="pagination-actions">
            <div class="pagination-per-page">
                <label for="<?php echo e($selectId); ?>" class="pagination-label">Rows per page:</label>
                <select id="<?php echo e($selectId); ?>" class="pagination-select" aria-label="Items per page"<?php echo $selectAttr; ?>>
                    <?php foreach ([10, 25, 50, 100] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pagination-nav-group">
                <button type="button" class="pagination-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" <?php echo $btnAttr($prevPage); ?> title="Previous Page" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                    <?php echo render_icon('arrow-left'); ?>
                </button>

                <div class="pagination-pages">
                    <?php if ($startPage > 1): ?>
                        <button type="button" class="pagination-page-btn" <?php echo $btnAttr(1); ?>>1</button>
                        <?php if ($startPage > 2): ?>
                            <span class="pagination-ellipsis">&hellip;</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <button type="button" class="pagination-page-btn <?php echo $p === $page ? 'is-active' : ''; ?>" <?php echo $btnAttr($p); ?>>
                            <?php echo $p; ?>
                        </button>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="pagination-ellipsis">&hellip;</span>
                        <?php endif; ?>
                        <button type="button" class="pagination-page-btn" <?php echo $btnAttr($totalPages); ?>><?php echo $totalPages; ?></button>
                    <?php endif; ?>
                </div>

                <button type="button" class="pagination-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" <?php echo $btnAttr($nextPage); ?> title="Next Page" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                    <?php echo render_icon('chevron-right'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php
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
function get_descendant_node_ids(PDO $pdo, array $nodeIds, bool $activeOnly = false, bool $includeAncestors = false): array
{
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $nodeIds))));
    if (empty($cleanIds)) {
        return [];
    }

    $inClause = implode(',', $cleanIds);
    $activeFilter = $activeOnly ? 'AND is_deleted = 0' : '';
    $stmt = $pdo->query("
        WITH RECURSIVE node_tree AS (
            SELECT id FROM dms_nodes WHERE id IN ($inClause)
            UNION ALL
            SELECT n.id FROM dms_nodes n
            INNER JOIN node_tree t ON n.parent_id = t.id
            WHERE 1=1 $activeFilter
        )
        SELECT id FROM node_tree
    ");
    $allIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));

    if ($includeAncestors) {
        for ($cur = $cleanIds; !empty($cur); ) {
            $cur = array_values(array_filter(array_map('intval', $pdo->query("SELECT parent_id FROM dms_nodes WHERE id IN (" . implode(',', $cur) . ") AND parent_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)), fn($id) => !in_array($id, $allIds, true)));
            $allIds = array_merge($allIds, $cur);
        }
    }
    return $allIds;
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

        $rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip = filter_var($rawIp, FILTER_VALIDATE_IP) ? $rawIp : '127.0.0.1';
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
              'document.upload', 'version.upload',
              'document.review_requested', 'document.review_approved', 'document.review_rejected', 
              'document.published', 'document.review_cancelled', 'document.access_updated', 'document.suspend'
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
            'version.upload'            => ['Updated', 'badge-active'],
            'document.access_updated'   => ['Shared', 'badge-active'],
            'document.review_requested' => ['Submitted', 'badge-hold'],
            'document.review_rejected'  => ['Rejected', 'badge-rejected'],
            'document.review_approved',
            'document.published'        => ['Accepted', 'badge-publish'],
            'document.review_cancelled' => ['Updated', 'badge-hold'],
            'document.suspend'          => ['Suspended', 'badge-suspend'],
            default                     => ['Updated', 'badge-active']
        };

        $userName = htmlspecialchars($log['user_full_name'] ?? 'System', ENT_QUOTES, 'UTF-8');
        $formattedTime = date('M j, Y &bull; H:i', strtotime($log['created_at']));

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
