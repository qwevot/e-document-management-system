<?php
// Dedicated Fullscreen Document Viewer & Collaborative Annotation Workspace
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/icons.php';

require_login();

$pdo = db();

$docId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId <= 0) {
    header('Location: explorer.php');
    exit;
}

// Fetch document metadata
$stmt = $pdo->prepare("
    SELECT n.*, COALESCE(u.full_name, u.username, 'System') AS creator_name, p.name AS parent_name
    FROM dms_nodes n
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN dms_nodes p ON n.parent_id = p.id
    WHERE n.id = :id AND n.node_type = 'document'
");
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die('Document data not found.');
}

if (!empty($doc['is_deleted']) && !is_admin() && (int)($doc['created_by'] ?? 0) !== (int)(current_user()['id'] ?? 0)) {
    log_activity('auth.access_denied', 'document', $docId, ['reason' => 'Attempted to view archived document without permission']);
    redirect_to('explorer', 'error', 'Document is archived or inaccessible.');
}

$isArchived = ($doc['status'] === 'obsolete' || (int)$doc['is_deleted'] === 1);
$defaultBackUrl = $isArchived ? 'archives.php' : ('explorer.php?mode=project' . (!empty($doc['parent_id']) ? '&folder=' . (int)$doc['parent_id'] : ''));

$isAjaxRequest = !empty($_REQUEST['action']) 
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

// Determine smart back URL: prioritize genuine origin page (workspace, archives, search, etc.)
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$sessionKey = 'doc_origin_url_' . $docId;

if (!$isAjaxRequest && !empty($referer)) {
    $refParts = parse_url($referer);
    $currHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $refHost = $refParts['host'] ?? $currHost;
    $refPath = $refParts['path'] ?? '';

    // If referrer is internal to our application and not view.php itself, record it as origin
    if ($refHost === $currHost && !str_ends_with($refPath, 'view.php')) {
        $_SESSION[$sessionKey] = $referer;
    }
}

$backUrl = $_SESSION[$sessionKey] ?? $defaultBackUrl;

$currentVersion = $doc['version'] ?? 'v1.0';
$nextMinorVer = calculate_next_version($currentVersion, 'minor');

// Check if viewing a historical version snapshot
$reqVersionId = isset($_GET['version_id']) && is_numeric($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$isHistoricalSnapshot = false;
$activeVersionName = $currentVersion;
$activeVersionId = 0;

if ($reqVersionId > 0) {
    $vStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE id = :vid AND document_id = :did");
    $vStmt->execute(['vid' => $reqVersionId, 'did' => $docId]);
    $vDoc = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($vDoc) {
        $activeVersionId = (int)$vDoc['id'];
        $isHistoricalSnapshot = ((int)$vDoc['is_current'] !== 1);
        $activeVersionName = $vDoc['version_number'];
        $activeReviewStatus = $vDoc['review_status'] ?? 'draft';
        $doc['file_path'] = $vDoc['file_path'];
        $doc['file_original_name'] = $vDoc['file_original_name'];
        $doc['file_size'] = $vDoc['file_size'];
        $doc['file_extension'] = $vDoc['file_extension'];
        $doc['mime_type'] = $vDoc['mime_type'];
    }
} else {
    $verStmt = $pdo->prepare("SELECT * FROM dms_document_versions WHERE document_id = :did ORDER BY is_current DESC, id DESC LIMIT 1");
    $verStmt->execute(['did' => $docId]);
    $verRow = $verStmt->fetch(PDO::FETCH_ASSOC);
    if ($verRow) {
        $activeVersionId = (int)$verRow['id'];
        $activeVersionName = $verRow['version_number'];
        $activeReviewStatus = $verRow['review_status'] ?? ($doc['status'] === 'published' ? 'approved' : ($doc['status'] === 'hold' ? 'pending' : 'draft'));
        $doc['file_path'] = $verRow['file_path'];
        $doc['file_original_name'] = $verRow['file_original_name'];
        $doc['file_size'] = $verRow['file_size'];
        $doc['file_extension'] = $verRow['file_extension'];
        $doc['mime_type'] = $verRow['mime_type'];
    }
}

$effectiveVersionId = $reqVersionId > 0 ? $reqVersionId : ($activeVersionId > 0 ? $activeVersionId : null);
$versionQueryParam = ($effectiveVersionId ? '&version_id=' . $effectiveVersionId : '') 
    . ($activeVersionName !== '' ? '&v=' . urlencode($activeVersionName) : '');

$ext = strtolower(trim($doc['file_extension'] ?? '', '.'));
$streamUrl = 'stream.php?id=' . $doc['id'] . $versionQueryParam;
$downloadUrl = 'download.php?id=' . $doc['id'] . ($effectiveVersionId ? '&version_id=' . $effectiveVersionId : '');
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'Collaborator');
$canAnnotate = !$isHistoricalSnapshot && can_user_annotate_document($doc, $userId);

// --- AJAX Annotation Persistence API (short-circuits before full-page queries & LibreOffice detection) ---
require_once __DIR__ . '/../includes/viewer_actions.php';

// Record document access telemetry (only on genuine full-page loads, never on background AJAX calls)
if (!$isAjaxRequest) {
    $viewThrottleKey = 'last_doc_view_' . $docId . '_' . $userId;
    if (!isset($_SESSION[$viewThrottleKey]) || (time() - (int)$_SESSION[$viewThrottleKey]) > 30) {
        $_SESSION[$viewThrottleKey] = time();
        log_activity('document.view', 'document', $docId, [
            'name'    => $doc['name'],
            'version' => $activeVersionName
        ]);
    }
}

// Review and workflow query (scoped to the active/target major version cycle)
$reviewTargetVersion = ($doc['status'] === 'published')
    ? ($doc['version'] ?: 'v1.0')
    : calculate_next_version($doc['version'] ?: 'v0.1', 'major');
$reviews = get_document_reviews($docId, $reviewTargetVersion);
$isDocHold = ($doc['status'] === 'hold');
$isDocActive = ($doc['status'] === 'active');
$isDocPublished = ($doc['status'] === 'published');

$userPendingReview = null;
$approvedCount = 0;
$rejectedCount = 0;
$pendingCount = 0;

$submittedByUserId = 0;
foreach ($reviews as $rev) {
    if ($rev['status'] === 'approved') $approvedCount++;
    elseif ($rev['status'] === 'rejected') $rejectedCount++;
    else $pendingCount++;

    if ((int)$rev['reviewer_id'] === $userId && $rev['status'] === 'pending') {
        $userPendingReview = $rev;
    }
    if ($submittedByUserId === 0 && !empty($rev['requested_by'])) {
        $submittedByUserId = (int)$rev['requested_by'];
    }
}
$allReviewersCount = count($reviews);
$reviewerUserIds = array_values(array_unique(array_map('intval', array_column($reviews, 'reviewer_id'))));
if ($submittedByUserId === 0) {
    $submittedByUserId = (int)$doc['created_by'];
}

$isAdmin = in_array(strtolower($currentUser['role'] ?? ''), ['super admin', 'admin'], true);
$isViewer = !can_edit_dms($currentUser);
$isOwner = (!$isViewer && ((int)$doc['created_by'] === $userId || $isAdmin));
$accessMode = $doc['access_mode'] ?? 'restricted';
$isContributor = is_document_contributor($docId, $userId);
$isAssignedReviewer = in_array($userId, $reviewerUserIds, true);
$canAnnotate = !$isHistoricalSnapshot && can_user_annotate_document($doc, $userId);
$canUploadRevision = !$isHistoricalSnapshot && can_user_upload_revision($doc, $userId);
$canSubmitReview = (!$isHistoricalSnapshot && in_array($doc['status'] ?? '', ['active', 'rejected', 'suspend'], true) && $isContributor);
$canManageAccess = $isOwner;

$isSubmitter = ($userId === $submittedByUserId || (int)$doc['created_by'] === $userId);

// Reviewers can act on the review only if they have an active pending review in the current stage
$canActOnReview = !$isHistoricalSnapshot && $isDocHold && ($userPendingReview !== null);

// Only the submitter who is not currently an assigned reviewer can manage, edit, or retract the review workflow
$canManageWorkflow = !$isHistoricalSnapshot && $isDocHold && $isSubmitter && !$isAssignedReviewer;
$canRetractReview = $canManageWorkflow;
$eligibleReviewers = get_eligible_reviewers($userId);

// Check if file type can be rendered in the canvas/preview engine
$imageExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp', 'ico'];
$officeExtensions = ['docx', 'doc', 'rtf', 'odt', 'xlsx', 'xls', 'csv', 'pptx', 'ppt', 'txt'];
$hasLibreOffice = is_libreoffice_available();

$isImage = in_array($ext, $imageExtensions, true);
$isPdf = ($ext === 'pdf');
$isOfficeDoc = in_array($ext, $officeExtensions, true);

if ($isPdf || ($isOfficeDoc && $hasLibreOffice)) {
    $renderMode = 'pdf';
} elseif ($isImage) {
    $renderMode = 'image';
} elseif ($isOfficeDoc && ($ext === 'docx' || $ext === 'doc')) {
    $renderMode = 'docx_fallback';
} else {
    $renderMode = 'unsupported';
}

$isSupportedViewer = ($renderMode !== 'unsupported');

// Structure existing multi-stage review pipeline for modal inspection
$existingReviewStages = [];
if (!empty($reviews)) {
    $tempStages = [];
    foreach ($reviews as $rev) {
        $step = (int)($rev['step_order'] ?? 1);
        if (!isset($tempStages[$step])) {
            $tempStages[$step] = [
                'id'          => $step,
                'reviewerIds' => [],
                'status'      => 'waiting',
                'reviewers'   => []
            ];
        }
        $tempStages[$step]['reviewerIds'][] = (int)$rev['reviewer_id'];
        $tempStages[$step]['reviewers'][] = [
            'id'       => (int)$rev['reviewer_id'],
            'name'     => !empty($rev['reviewer_name']) ? $rev['reviewer_name'] : $rev['reviewer_username'],
            'username' => $rev['reviewer_username'] ?? '',
            'avatar'   => $rev['reviewer_avatar'] ?? null,
            'status'   => $rev['status'] ?? 'waiting'
        ];
    }
    foreach ($tempStages as $sNum => &$sData) {
        $stList = array_column($sData['reviewers'], 'status');
        if (in_array('rejected', $stList, true)) {
            $sData['status'] = 'rejected';
        } elseif (!empty($stList) && count(array_filter($stList, fn($s) => $s === 'approved')) === count($stList)) {
            $sData['status'] = 'approved';
        } elseif (in_array('pending', $stList, true)) {
            $sData['status'] = 'pending';
        } else {
            $sData['status'] = 'waiting';
        }
    }
    unset($sData);
    ksort($tempStages);
    $existingReviewStages = array_values($tempStages);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($doc['name']); ?> - Workspace Viewer</title>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <link rel="stylesheet" href="../assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/styles.css'); ?>">
    <link rel="stylesheet" href="../assets/css/viewer.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/viewer.css'); ?>">
    <link rel="stylesheet" href="../assets/css/explorer.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/explorer.css'); ?>">
    <link rel="stylesheet" href="../assets/css/responsive.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>">
    <script>
    function handleWorkspaceBack(e, fallbackUrl) {
        if (window.history.length > 1 && document.referrer && !document.referrer.includes('view.php')) {
            if (e) e.preventDefault();
            window.history.back();
            return;
        }
        if (fallbackUrl && e && e.defaultPrevented) {
            window.location.href = fallbackUrl;
        }
    }
    </script>
</head>
<body class="edms-viewer-body">

<div class="workspace-viewport-app" id="workspaceViewerApp">

    <!-- 1. Top Global Navigation Header -->
    <header class="workspace-top-bar">
        <div class="workspace-nav-left">
            <a href="<?php echo e($backUrl); ?>" class="btn-workspace-back" title="Back" onclick="handleWorkspaceBack(event, '<?php echo e($backUrl); ?>')">
                <?php echo render_icon('arrow-left'); ?>
                <span>Back</span>
            </a>
            
            <div class="workspace-doc-meta">
                <span class="workspace-file-icon icon-<?php echo e($ext ?: 'pdf'); ?>">
                    <?php echo render_icon($ext ?: 'pdf'); ?>
                </span>
                <span class="workspace-doc-title" title="<?php echo e($doc['name']); ?>">
                    <?php echo e($doc['name']); ?>
                </span>

                <?php if ($accessMode === 'public'): ?>
                    <span class="workspace-badge" style="background:var(--info-blue-bg); color:var(--info-blue); border:1px solid var(--info-blue-line);" title="Public Document (Anyone can contribute)">Public</span>
                <?php endif; ?>

                <?php if ($isHistoricalSnapshot): ?>
                    <span class="workspace-badge" style="background:var(--amber-soft); color:var(--amber-ink); border:1px solid var(--amber-line);" title="Viewing historical version snapshot">Snapshot: <?php echo e($activeVersionName); ?></span>
                    <a href="view.php?id=<?php echo $docId; ?>" class="btn-workspace-action" style="padding: 2px 8px; font-size: 11px; height: 24px;" title="Switch to latest active revision">Latest (<?php echo e($currentVersion); ?>)</a>
                <?php else: ?>
                    <button type="button" class="workspace-badge badge-version" id="btnBadgeVersion" title="View Revision Lineage" style="cursor:pointer; background: var(--green-tint-surface); border: 1px dashed var(--green-tint-border); display: inline-flex; align-items: center; gap: 4px;">
                        <span><?php echo e($doc['version'] ?? 'v1.0'); ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Center Controls: Zoom & Page Info (Only for supported viewable formats) -->
        <div class="workspace-nav-center">
            <?php if ($isSupportedViewer): ?>
                <div class="workspace-control-pill">
                    <button type="button" class="btn-ctrl" id="btnPrevPage" title="Previous Page">
                        <?php echo render_icon('arrow-left'); ?>
                    </button>
                    <span class="page-indicator-text" id="pageIndicatorDisplay">Page 1 / 1</span>
                    <button type="button" class="btn-ctrl" id="btnNextPage" title="Next Page">
                        <?php echo render_icon('chevron-right'); ?>
                    </button>
                    <div class="ctrl-sep"></div>
                    <button type="button" class="btn-ctrl" id="btnZoomOut" title="Zoom Out">
                        <?php echo render_icon('zoom-out'); ?>
                    </button>
                    <span class="zoom-level-text" id="zoomLevelDisplay">100%</span>
                    <button type="button" class="btn-ctrl" id="btnZoomIn" title="Zoom In">
                        <?php echo render_icon('zoom-in'); ?>
                    </button>
                    <div class="ctrl-sep"></div>
                    <button type="button" class="btn-ctrl" id="btnZoomFit" title="Fit to Width">
                        <span>Fit</span>
                    </button>
                    <button type="button" class="btn-ctrl" id="btnZoomReset" title="Reset Zoom (100%)">
                        <span>100%</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Controls: Active Collaborators, Submit Review, Version History, Layer Drawer Toggle, Download -->
        <div class="workspace-nav-right">

            <?php if ($isSupportedViewer): ?>
                <!-- Active Collaborator Presence Indicator -->
                <div class="collaborator-presence-strip" title="Active Collaborators">
                    <div class="presence-avatar is-self" title="<?php echo e($currentUser['full_name']); ?> (You)" style="overflow: hidden; padding: 0;">
                        <?php if (!empty($currentUser['avatar']) && is_file(__DIR__ . '/../' . $currentUser['avatar'])): ?>
                            <img src="<?php echo BASE_URL . '/' . e($currentUser['avatar']); ?>" alt="<?php echo e($currentUser['full_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?php echo e(get_initials($currentUser['full_name'])); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Download Dropdown Menu for All Supported Formats -->
                <div class="workspace-download-dropdown" id="workspaceDownloadDropdown">
                    <button type="button" class="btn-workspace-action" id="btnDownloadDropdownToggle" title="Download Options">
                        <?php echo render_icon('download'); ?>
                        <span>Download</span>
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px; transition: transform 0.2s;" id="downloadDropdownArrow">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="workspace-download-menu" id="workspaceDownloadMenu">
                        <a href="<?php echo e($downloadUrl); ?>" class="download-menu-item" download>
                            <?php echo render_icon('files'); ?>
                            <div class="menu-item-text">
                                <span class="menu-item-title"><?php 
                                    if ($isOfficeDoc) echo 'Original (.' . e($ext) . ')';
                                    elseif ($isImage) echo 'Original Image (.' . e($ext) . ')';
                                    else echo 'Original Document'; 
                                ?></span>
                                <span class="menu-item-sub">Download source file</span>
                            </div>
                        </a>
                        <?php if ($isOfficeDoc): ?>
                            <a href="download.php?id=<?php echo e((string)$doc['id']); ?>&as_pdf=1" class="download-menu-item" download>
                                <?php echo render_icon('pdf'); ?>
                                <div class="menu-item-text">
                                    <span class="menu-item-title">Download As PDF</span>
                                    <span class="menu-item-sub">Convert to clean vector PDF</span>
                                </div>
                            </a>
                        <?php endif; ?>
                        <?php if ($renderMode === 'pdf' || $renderMode === 'image'): ?>
                            <button type="button" class="download-menu-item" id="btnDownloadWithAnnotations">
                                <?php echo render_icon('edit'); ?>
                                <div class="menu-item-text">
                                    <span class="menu-item-title"><?php echo $isImage ? ('With Annotations (.' . e($ext) . ')') : 'With Annotations (PDF)'; ?></span>
                                    <span class="menu-item-sub">Flatten visible drawings &amp; notes</span>
                                </div>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Clean direct download button when viewer is not applicable -->
                <a href="<?php echo e($downloadUrl); ?>" class="btn-workspace-action is-active" download title="Download File">
                    <?php echo render_icon('download'); ?>
                    <span>Download</span>
                </a>
            <?php endif; ?>

            <?php if ($canManageAccess): ?>
                <!-- Document Contributor Access & Sharing -->
                <button type="button" class="btn-workspace-action" id="btnOpenDocAccessModal" title="Manage Document Contributors &amp; Access Mode" style="color: var(--ink);">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                    <span>Access</span>
                </button>
            <?php endif; ?>

            <?php if (!$isHistoricalSnapshot && $isDocPublished && $isContributor): ?>
                <button type="button" class="btn-workspace-action" title="Suspend for Revision" style="color: var(--red);" onclick="document.getElementById('modalSuspendDoc').classList.add('is-open')">
                    <?php echo render_icon('close'); ?>
                    <span>Suspend</span>
                </button>
            <?php endif; ?>

            <?php if ($canSubmitReview): ?>
                <!-- Submit for Review Trigger -->
                <button type="button" class="btn-workspace-action" id="btnOpenSubmitReviewModal" title="Submit document for review &amp; formal approval" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.4); color: var(--ink);">
                    <?php echo render_icon('files'); ?>
                    <span>Approval</span>
                </button>
            <?php elseif (!$isHistoricalSnapshot && $isDocHold): ?>
                <!-- Review Progress / Pipeline Trigger -->
                <button type="button" class="btn-workspace-action" id="btnOpenSubmitReviewModal" title="View Review Approval Pipeline &amp; Progress" style="background: var(--amber-soft); border-color: var(--amber-line); color: var(--amber-ink);">
                    <?php echo render_icon('files'); ?>
                    <span>Approval</span>
                </button>
                <?php if ($canActOnReview): ?>
                    <!-- Finish Review Trigger (Replaces Submit Review for Approvers) -->
                    <button type="button" class="btn-workspace-action is-active" id="btnOpenFinishReviewModal" title="Finish Review &amp; Record Verdict" style="background: var(--green); border-color: var(--green); color: #fff; font-weight: 600;">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>Finish Review</span>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($isSupportedViewer): ?>
        <!-- 2. Main Workspace Layout Area (Supported Viewer) -->
        <div class="workspace-stage-wrapper">
            
            <!-- Floating Left Tool Palette -->
            <aside class="floating-tool-dock" id="floatingToolDock">
                <div class="tool-dock-section">
                    <button type="button" class="dock-btn" data-tool="pan" id="toolPan" title="Pan / Navigate (Hand)">
                        <?php echo render_icon('hand'); ?>
                    </button>
                    <button type="button" class="dock-btn" data-tool="select" id="toolSelect" title="Select / Transform (Cursor)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M3 3l7 18 2.5-7.5L20 11 3 3z"/></svg>
                    </button>
                    <button type="button" class="dock-btn is-active" data-tool="pen" id="toolPen" title="Freehand Brush">
                        <?php echo render_icon('pen'); ?>
                    </button>
                    <button type="button" class="dock-btn" data-tool="highlighter" id="toolHighlighter" title="Highlight">
                        <?php echo render_icon('highlighter'); ?>
                    </button>
                    <div id="dockShapesGroup" style="position: relative;">
                        <button type="button" class="dock-btn" data-tool="rect" id="toolShapes" title="Shapes (Rectangle, Circle, Arrow, Line)">
                            <span id="toolShapesIcon" style="display: flex; align-items: center; justify-content: center; pointer-events: none;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="3" y="3" width="10" height="10" rx="1.5"/><circle cx="15" cy="15" r="5"/></svg>
                            </span>
                        </button>
                        <div id="dockShapesMenu" style="display: none; position: absolute; left: calc(100% + 8px); top: 0; background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-lg); border-radius: var(--radius-lg); padding: 4px; z-index: 50; flex-direction: column; gap: 4px;">
                            <button type="button" class="dock-btn shape-opt" data-shape="rect" title="Rectangle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="3" width="18" height="18" rx="2"/></svg></button>
                            <button type="button" class="dock-btn shape-opt" data-shape="circle" title="Circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="9"/></svg></button>
                            <button type="button" class="dock-btn shape-opt" data-shape="arrow" title="Arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></button>
                            <button type="button" class="dock-btn shape-opt" data-shape="line" title="Line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="16" height="16"><line x1="5" y1="19" x2="19" y2="5"/></svg></button>
                        </div>
                    </div>
                    <button type="button" class="dock-btn" data-tool="eraser" id="toolEraser" title="Stroke Eraser">
                        <?php echo render_icon('eraser'); ?>
                    </button>
                    <button type="button" class="dock-btn" data-tool="text" id="toolText" title="Text Note / Callout">
                        <?php echo render_icon('type'); ?>
                    </button>
                    <button type="button" class="dock-btn" data-tool="stamp" id="toolStamp" title="Insert Image / Signature">
                        <?php echo render_icon('image'); ?>
                    </button>
                    <input type="file" id="stampFileInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display: none;">
                </div>

                <div class="tool-dock-divider"></div>

                <!-- Color Palette Swatches -->
                <div class="tool-color-palette" id="toolColorPalette">
                    <button type="button" class="color-swatch is-selected" data-color="#16a34a" style="--swatch-color: var(--green);" title="Emerald Green"></button>
                    <button type="button" class="color-swatch" data-color="#f59e0b" style="--swatch-color: var(--amber);" title="Amber Warning"></button>
                    <button type="button" class="color-swatch" data-color="#dc2626" style="--swatch-color: var(--red);" title="Crimson Red"></button>
                    <button type="button" class="color-swatch" data-color="#2563eb" style="--swatch-color: var(--info-blue);" title="Sky Blue"></button>
                    <button type="button" class="color-swatch" data-color="#7c3aed" style="--swatch-color: var(--cad-purple);" title="Purple"></button>
                    <button type="button" class="color-swatch" data-color="#0f172a" style="--swatch-color: var(--ink);" title="Ink Dark"></button>
                </div>

                <!-- Stroke Width Options -->
                <div class="tool-dock-divider"></div>
                <div class="tool-stroke-group">
                    <button type="button" class="stroke-btn is-active" data-stroke="2" title="Fine Stroke (2px)">
                        <span style="height: 2px; width: 14px; background: currentColor; border-radius: 1px;"></span>
                    </button>
                    <button type="button" class="stroke-btn" data-stroke="4" title="Medium Stroke (4px)">
                        <span style="height: 4px; width: 14px; background: currentColor; border-radius: 2px;"></span>
                    </button>
                    <button type="button" class="stroke-btn" data-stroke="8" title="Thick Stroke (8px)">
                        <span style="height: 8px; width: 14px; background: currentColor; border-radius: 3px;"></span>
                    </button>
                </div>

                <div class="tool-dock-divider"></div>

                <!-- History Actions: Undo / Redo / Clear Layer -->
                <div class="tool-dock-section">
                    <button type="button" class="dock-btn" id="btnUndoStroke" title="Undo Last Stroke">
                        <?php echo render_icon('undo'); ?>
                    </button>
                    <button type="button" class="dock-btn" id="btnRedoStroke" title="Redo Stroke" disabled style="opacity: 0.35; cursor: default;">
                        <?php echo render_icon('redo'); ?>
                    </button>
                    <button type="button" class="dock-btn" id="btnClearMyLayer" title="Delete Current Group">
                        <?php echo render_icon('trash'); ?>
                    </button>
                </div>
            </aside>

            <!-- Center Stage: Scrollable Document & Multi-Layer Canvas Stage -->
            <main class="workspace-center-stage" id="workspaceCenterStage">
                
                <div class="workspace-document-container" id="workspaceDocContainer">
                    
                    <!-- Loading State Overlay -->
                    <div class="workspace-doc-loading" id="workspaceDocLoading">
                        <div class="viewer-spinner"></div>
                        <span id="loadingStatusText">Rendering pages...</span>
                    </div>

                    <!-- Continuous Multi-Page PDF Container -->
                    <div class="pdf-pages-feed" id="pdfPagesFeed">
                        <!-- Dynamic page wrappers injected by PDF.js engine -->
                    </div>

                </div>

            </main>

            <!-- Right Side Drawer: Annotation Layers & Version Lineage Tabs -->
            <aside class="workspace-right-drawer is-open" id="workspaceRightDrawer">
                <!-- Poppable Arrow Toggle Button on Drawer Left Edge -->
                <button type="button" class="drawer-edge-toggle" id="btnToggleRightDrawerEdge" title="Toggle Side Panel">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>

                <div class="drawer-header" style="padding: 10px 14px; gap: 8px;">
                    <div class="pill-tabs" style="display: flex; flex: 1; justify-content: stretch;">
                        <button type="button" class="pill-tab active" id="tabBtnAnnotations" style="flex: 1; justify-content: center; border: none; cursor: pointer;">
                            <?php echo render_icon('layers'); ?>
                            <span>Annotations</span>
                        </button>
                        <button type="button" class="pill-tab" id="tabBtnVersions" style="flex: 1; justify-content: center; border: none; cursor: pointer;">
                            <?php echo render_icon('history'); ?>
                            <span>Versions</span>
                        </button>
                    </div>
                </div>

                <div class="drawer-body">
                    
                    <!-- 1. Annotations Tab Content -->
                    <div id="drawerTabAnnotations" class="drawer-tab-pane">
                        <div class="layer-section-header-row">
                            <div class="layer-section-title" style="margin-bottom: 0;">Contributors</div>
                            <?php if ($canAnnotate && !$isHistoricalSnapshot && !$isArchived): ?>
                            <button type="button" class="btn-add-layer" id="btnAddLayerGroup" title="Add New Annotation Group">
                                <?php echo render_icon('plus'); ?>
                                <span>Add Layer</span>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="layers-list" id="layersList">
                            <!-- Dynamic Layer Items rendered by JS -->
                        </div>

                        <div class="layer-section-title" style="margin-top: 24px;">Layer Transparency</div>
                        <div class="layer-opacity-box">
                            <div class="opacity-label-row">
                                <span>Overlay Opacity</span>
                                <span id="opacityDisplayVal">100%</span>
                            </div>
                            <input type="range" id="layerOpacitySlider" min="0" max="100" value="100" class="layer-opacity-slider">
                        </div>

                        <div class="history-accordion-header" id="btnToggleHistoryAccordion" title="Toggle Annotation History">
                            <div class="history-accordion-title">
                                <span class="history-accordion-arrow" id="historyAccordionArrow">
                                    <?php echo render_icon('chevron-right'); ?>
                                </span>
                                <span>Annotation History</span>
                            </div>
                        </div>
                        <div class="annotation-history-feed is-collapsed" id="annotationHistoryFeed">
                            <div class="history-empty-state" id="historyEmptyState">
                                <span>No annotation events recorded yet.</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Version History Tab Content -->
                    <div id="drawerTabVersions" class="drawer-tab-pane" style="display: none;">
                        <div class="history-accordion-header is-open" id="btnToggleVersionHistoryDropdown" title="Toggle Version History" style="margin-top: 0; margin-bottom: 10px; cursor: pointer;">
                            <div class="history-accordion-title">
                                <span class="history-accordion-arrow" id="versionHistoryAccordionArrow">
                                    <?php echo render_icon('chevron-right'); ?>
                                </span>
                                <span>Version History</span>
                            </div>
                            <?php if ($canUploadRevision && !$isHistoricalSnapshot && !$isArchived): ?>
                                <button type="button" class="btn btn-primary" id="btnOpenUploadRevisionModal" style="padding: 4px 10px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                    <?php echo render_icon('plus'); ?>
                                    <span>Upload Revision</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Version Timeline Feed -->
                        <div id="versionTimelineContainer" class="annotation-history-feed" style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="text-align: center; padding: 20px; color: var(--slate-muted); font-size: 13px;">
                                Loading version history...
                            </div>
                        </div>

                        <!-- Audit History Dropdown Accordion -->
                        <div class="history-accordion-header" id="btnToggleAuditHistoryDropdown" title="Toggle Audit History" style="margin-top: 20px; cursor: pointer;">
                            <div class="history-accordion-title">
                                <span class="history-accordion-arrow" id="auditHistoryAccordionArrow">
                                    <?php echo render_icon('chevron-right'); ?>
                                </span>
                                <span>Audit History</span>
                            </div>
                        </div>
                        <div class="annotation-history-feed is-collapsed" id="auditHistoryFeed">
                            <div class="history-empty-state" id="auditHistoryEmptyState">
                                <span>No audit events recorded yet.</span>
                            </div>
                        </div>
                    </div>

                </div>
            </aside>

        </div>
    <?php else: ?>
        <!-- Simple Unsupported Filetype Screen -->
        <main class="workspace-center-stage workspace-unsupported-stage">
            <div class="workspace-unsupported-card">
                <div class="unsupported-icon-circle">
                    <?php echo render_icon(!empty($ext) ? $ext : 'files'); ?>
                </div>
                <h2 class="unsupported-title">No preview available</h2>
                <p class="unsupported-desc">
                    The viewer does not support <strong><?php echo !empty($ext) ? '.' . e($ext) : 'this'; ?></strong> files. You can download the file to view it on your device.
                </p>
                <div class="unsupported-actions">
                    <a href="<?php echo e($downloadUrl); ?>" class="btn-unsupported-download" download>
                        <?php echo render_icon('download'); ?>
                        <span>Download <?php echo !empty($ext) ? '.' . strtoupper(e($ext)) : 'File'; ?></span>
                    </a>
                    <a href="<?php echo e($backUrl); ?>" class="btn-unsupported-back" onclick="handleWorkspaceBack(event, '<?php echo e($backUrl); ?>')">
                        <span>Back</span>
                    </a>
                </div>
            </div>
        </main>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/viewer_modals.php'; ?>

<!-- Pass Initial Document Configuration to JS -->
<script>

window.EDMS_WORKSPACE_CONFIG = {
    baseUrl: <?php echo json_encode(BASE_URL); ?>,
    docId: <?php echo (int)$doc['id']; ?>,
    docName: <?php echo json_encode($doc['name']); ?>,
    fileExt: <?php echo json_encode($ext); ?>,
    renderMode: <?php echo json_encode($renderMode); ?>,
    docStatus: <?php echo json_encode($doc['status']); ?>,
    accessMode: <?php echo json_encode($accessMode); ?>,
    isDocHold: <?php echo $isDocHold ? 'true' : 'false'; ?>,
    isDocPublished: <?php echo $isDocPublished ? 'true' : 'false'; ?>,
    isOwner: <?php echo $isOwner ? 'true' : 'false'; ?>,
    isContributor: <?php echo $isContributor ? 'true' : 'false'; ?>,
    canAnnotate: <?php echo $canAnnotate ? 'true' : 'false'; ?>,
    canActOnReview: <?php echo $canActOnReview ? 'true' : 'false'; ?>,
    canUploadRevision: <?php echo $canUploadRevision ? 'true' : 'false'; ?>,
    canSubmitReview: <?php echo $canSubmitReview ? 'true' : 'false'; ?>,
    canManageAccess: <?php echo $canManageAccess ? 'true' : 'false'; ?>,
    hasLibreOffice: <?php echo $hasLibreOffice ? 'true' : 'false'; ?>,
    isSupportedViewer: <?php echo $isSupportedViewer ? 'true' : 'false'; ?>,
    streamUrl: <?php echo json_encode($streamUrl); ?>,
    downloadUrl: <?php echo json_encode($downloadUrl); ?>,
    reviewerUserIds: <?php echo json_encode($reviewerUserIds); ?>,
    currentUserId: <?php echo (int)$currentUser['id']; ?>,
    currentUserName: <?php echo json_encode($currentUser['full_name']); ?>,
    currentUserAvatar: <?php echo json_encode($currentUser['avatar'] ?? null); ?>,
    activeVersionId: <?php echo (int)$activeVersionId; ?>,
    activeVersionName: <?php echo json_encode($activeVersionName); ?>,
    isHistoricalSnapshot: <?php echo $isHistoricalSnapshot ? 'true' : 'false'; ?>,
    canRetractReview: <?php echo $canRetractReview ? 'true' : 'false'; ?>,
    canManageWorkflow: <?php echo $canManageWorkflow ? 'true' : 'false'; ?>,
    existingReviewStages: <?php echo json_encode($existingReviewStages); ?>,
    icons: {
        comment: <?php echo json_encode(render_icon('comment-add')); ?>,
        plus: <?php echo json_encode(render_icon('plus')); ?>
    }
};
</script>
<?php if ($isSupportedViewer): ?>
<?php if ($renderMode === 'pdf'): ?>
<script src="../assets/vendor/pdfjs/pdf.min.js"></script>
<?php elseif ($renderMode === 'docx_fallback'): ?>
<script src="../assets/vendor/jszip.min.js"></script>
<script src="../assets/vendor/docx-preview.min.js"></script>
<?php endif; ?>
<script src="../assets/js/comments.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/comments.js'); ?>"></script>
<?php endif; ?>
<script src="../assets/js/core.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/core.js'); ?>"></script>
<script src="../assets/js/viewer-workflow.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/viewer-workflow.js'); ?>" defer></script>
<script src="../assets/js/viewer.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/viewer.js'); ?>" defer></script>
</body>
</html>
