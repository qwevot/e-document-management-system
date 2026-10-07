<?php
// Core Helper Functions

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/db.php';

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


