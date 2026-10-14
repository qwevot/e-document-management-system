<?php
$pageTitle = 'System Logs';
$activeNav  = 'system-logs';

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role('super admin', 'admin');

$pdo = db();
$currentRole = current_role();
$isSuperAdmin = ($currentRole === 'super admin');

// Fetch distinct usernames for filter
$allUsers = [];
try {
    $allUsers = $pdo->query("SELECT DISTINCT username FROM system_logs WHERE username IS NOT NULL AND username <> '' ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Filtering parameters
$filterCat    = trim($_GET['cat'] ?? '');
$filterUser   = trim($_GET['user'] ?? '');
$search       = trim($_GET['q'] ?? '');

// Pagination parameters
$page    = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], [10, 25, 50, 100], true) ? (int)$_GET['per_page'] : 25;

$whereClauses = ['1=1'];
$params = [];

if ($filterCat !== '') {
    $whereClauses[] = "action LIKE ?";
    $params[] = $filterCat . '.%';
}
if ($filterUser !== '') {
    $whereClauses[] = "username = ?";
    $params[] = $filterUser;
}
if ($search !== '') {
    $whereClauses[] = "(action LIKE ? OR username LIKE ? OR ip_address LIKE ? OR details LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = implode(' AND ', $whereClauses);

// Count total matching logs
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Fetch current page logs
$sql = "SELECT * FROM system_logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/../includes/flash.php'; ?>

<div class="users-section">
    <!-- Header Bar: Title, Search, Filters, and Actions -->
    <div class="users-header-card" style="flex-wrap: wrap; gap: 12px;">
        <div class="users-header-title">
            <?php echo render_icon('system-logs'); ?>
            <span>System Logs</span>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <a href="<?php echo BASE_URL; ?>/actions/export-logs.php?<?php echo http_build_query($_GET); ?>" class="btn" style="background: var(--surface); border: 1px solid var(--border); color: var(--ink); display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; font-size: 12.5px;" title="Export filtered logs to CSV">
                <?php echo render_icon('download'); ?>
                <span>Export CSV</span>
            </a>

            <?php if ($isSuperAdmin): ?>
            <button type="button" class="btn primary" style="background: var(--red); border-color: var(--red); display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; font-size: 12.5px;" onclick="openPurgeModal()">
                <?php echo render_icon('trash'); ?>
                <span>Purge Logs</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <form method="GET" action="system-logs.php" class="explorer-toolbar-card" style="margin-bottom: 0; padding: 10px 14px; gap: 10px; flex-wrap: wrap;">
        <div class="explorer-search-box" style="flex: 1; min-width: 220px;">
            <span class="search-icon"><?php echo render_icon('search'); ?></span>
            <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search action, user, IP, or payload..." class="search-input" autocomplete="off">
            <?php if ($search !== ''): ?>
                <a href="system-logs.php<?php echo $filterCat || $filterUser ? '?' . http_build_query(['cat' => $filterCat, 'user' => $filterUser]) : ''; ?>" class="search-clear" title="Clear search">&times;</a>
            <?php endif; ?>
        </div>

        <div class="explorer-filters-right" style="gap: 8px;">
            <div class="select-wrapper">
                <select name="cat" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <option value="auth" <?php echo $filterCat === 'auth' ? 'selected' : ''; ?>>Auth (auth.*)</option>
                    <option value="document" <?php echo $filterCat === 'document' ? 'selected' : ''; ?>>Documents (document.*)</option>
                    <option value="folder" <?php echo $filterCat === 'folder' ? 'selected' : ''; ?>>Folders (folder.*)</option>
                    <option value="node" <?php echo $filterCat === 'node' ? 'selected' : ''; ?>>Nodes (node.*)</option>
                    <option value="version" <?php echo $filterCat === 'version' ? 'selected' : ''; ?>>Versions (version.*)</option>
                    <option value="user" <?php echo $filterCat === 'user' ? 'selected' : ''; ?>>Users (user.*)</option>
                    <option value="system" <?php echo $filterCat === 'system' ? 'selected' : ''; ?>>System (system.*)</option>
                </select>
            </div>

            <?php if (!empty($allUsers)): ?>
            <div class="select-wrapper">
                <select name="user" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Users</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo e($u); ?>" <?php echo $filterUser === $u ? 'selected' : ''; ?>><?php echo e($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($search !== '' || $filterCat !== '' || $filterUser !== ''): ?>
                <a href="system-logs.php" class="btn-reset-filters" title="Reset all filters">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Logs Data Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="no-col">No.</th>
                    <th style="width: 140px;">Timestamp</th>
                    <th style="width: 160px;">Event Action</th>
                    <th style="width: 160px;">Actor / User</th>
                    <th style="width: 130px;">Entity Target</th>
                    <th style="width: 120px;">IP Address</th>
                    <th>Payload / Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; color:var(--muted); padding:48px 24px;">
                            No system activity logs found matching the selected criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $lg): ?>
                        <?php
                            $rowNum = $offset + $i + 1;
                            $actionPrefix = explode('.', $lg['action'])[0] ?? 'system';
                            $detailsArr = json_decode($lg['details'] ?? '{}', true);
                            $hasDetails = !empty($detailsArr);
                            $detailJson = $hasDetails ? json_encode($detailsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                            $detailSnippet = $hasDetails ? json_encode($detailsArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                            $roleSlug = strtolower(str_replace(' ', '-', (string)($lg['role'] ?? 'viewer')));
                        ?>
                        <tr>
                            <td class="no-col"><?php echo $rowNum; ?></td>
                            <td style="font-size: 12px; color: var(--muted); white-space: nowrap;">
                                <?php echo date('M d, Y H:i:s', strtotime($lg['created_at'])); ?>
                            </td>
                            <td>
                                <code style="font-size: 12px; font-weight: 600; color: var(--green-dark); background: var(--green-soft); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--green-line); white-space: nowrap;">
                                    <?php echo e($lg['action']); ?>
                                </code>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--ink); font-size: 12.5px;">
                                    <?php echo e($lg['username'] ?? 'guest'); ?>
                                </div>
                                <div style="margin-top: 2px;">
                                    <span class="role-badge <?php echo e($roleSlug); ?>" style="font-size: 10px; padding: 1px 5px;">
                                        <?php echo e(ucfirst($lg['role'] ?? 'guest')); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($lg['entity_type'])): ?>
                                    <span style="font-weight: 500; color: var(--ink); font-size: 12px;">
                                        <?php echo e(ucfirst($lg['entity_type'])); ?> <?php echo $lg['entity_id'] ? '#' . (int)$lg['entity_id'] : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--muted);">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size: 11.5px; color: var(--muted); background: var(--surface-light); padding: 2px 5px; border-radius: 4px; border: 1px solid var(--border);">
                                    <?php echo e($lg['ip_address'] ?? 'N/A'); ?>
                                </code>
                            </td>
                            <td style="max-width: 320px;">
                                <?php if ($hasDetails): ?>
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <button type="button" class="btn" style="padding: 2px 7px; font-size: 11px; background: var(--surface-light); border: 1px solid var(--border); color: var(--text); border-radius: 4px; cursor: pointer;" onclick="togglePayload(<?php echo (int)$lg['id']; ?>)">
                                                <span id="payload-btn-icon-<?php echo (int)$lg['id']; ?>">▶</span> View JSON
                                            </button>
                                            <span style="font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;">
                                                <?php echo e($detailSnippet); ?>
                                            </span>
                                        </div>
                                        <div id="payload-box-<?php echo (int)$lg['id']; ?>" style="display: none; margin-top: 6px; background: var(--surface-light); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--muted);">Payload</span>
                                                <button type="button" style="background: none; border: none; font-size: 11px; color: var(--green); cursor: pointer; font-weight: 600;" onclick="copyPayload(<?php echo (int)$lg['id']; ?>, this)">Copy</button>
                                            </div>
                                            <pre id="payload-code-<?php echo (int)$lg['id']; ?>" style="margin: 0; font-family: monospace; font-size: 11.5px; color: var(--ink); white-space: pre-wrap; word-break: break-all; max-height: 180px; overflow-y: auto;"><?php echo e($detailJson); ?></pre>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--muted);">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <?php
    $startItem = $totalCount > 0 ? ($page - 1) * $perPage + 1 : 0;
    $endItem = min($totalCount, $page * $perPage);
    $urlParams = $_GET;
    ?>
    <div class="explorer-pagination-bar">
        <div class="pagination-info">
            <span>Showing <strong><?php echo $startItem; ?>–<?php echo $endItem; ?></strong> of <strong><?php echo number_format($totalCount); ?></strong> activity records</span>
        </div>

        <div class="pagination-actions">
            <!-- Rows per page selector -->
            <div class="pagination-per-page">
                <label for="logsPerPageSelect" class="pagination-label">Rows per page:</label>
                <select id="logsPerPageSelect" class="pagination-select" aria-label="Logs per page" onchange="navigateLogsParam('per_page', this.value)">
                    <?php foreach ([10, 25, 50, 100] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Navigation buttons -->
            <div class="pagination-nav-group">
                <button type="button" class="pagination-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" onclick="navigateLogsPage(<?php echo max(1, $page - 1); ?>)" title="Previous Page" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                    <?php echo render_icon('arrow-left'); ?>
                </button>

                <div class="pagination-pages">
                    <?php
                    $window = 2;
                    $startPage = max(1, $page - $window);
                    $endPage = min($totalPages, $page + $window);

                    if ($startPage > 1): ?>
                        <button type="button" class="pagination-page-btn" onclick="navigateLogsPage(1)">1</button>
                        <?php if ($startPage > 2): ?>
                            <span class="pagination-ellipsis">&hellip;</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <button type="button" class="pagination-page-btn <?php echo $p === $page ? 'is-active' : ''; ?>" onclick="navigateLogsPage(<?php echo $p; ?>)">
                            <?php echo $p; ?>
                        </button>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="pagination-ellipsis">&hellip;</span>
                        <?php endif; ?>
                        <button type="button" class="pagination-page-btn" onclick="navigateLogsPage(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></button>
                    <?php endif; ?>
                </div>

                <button type="button" class="pagination-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" onclick="navigateLogsPage(<?php echo min($totalPages, $page + 1); ?>)" title="Next Page" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                    <?php echo render_icon('chevron-right'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Super Admin Purge Confirmation Modal -->
<?php if ($isSuperAdmin): ?>
<div class="edms-modal-backdrop" id="modalPurgeLogs">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('trash'); ?>
                <span>Purge System Logs</span>
            </div>
            <button type="button" class="btn-modal-close" onclick="closePurgeModal()">&times;</button>
        </div>
        <form method="POST" action="<?php echo BASE_URL; ?>/actions/purge-logs.php">
            <?php echo csrf_field('system-logs'); ?>
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 12px;">
                    Select the retention threshold for log purging. This action permanently deletes audit entries and cannot be undone.
                </p>
                <div class="form-group">
                    <label class="form-label" for="purgeRetentionSelect">Retention Policy</label>
                    <select id="purgeRetentionSelect" name="range" class="form-input">
                        <option value="30">Delete logs older than 30 days</option>
                        <option value="90">Delete logs older than 90 days</option>
                        <option value="all">Delete ALL logs (Complete Purge)</option>
                    </select>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePurgeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: var(--red); border-color: var(--red);" onclick="return confirm('Are you certain you want to execute this log purge?')">
                    <?php echo render_icon('trash'); ?>
                    <span>Purge Selected</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function togglePayload(id) {
    const box = document.getElementById('payload-box-' + id);
    const icon = document.getElementById('payload-btn-icon-' + id);
    if (!box) return;
    if (box.style.display === 'none' || box.style.display === '') {
        box.style.display = 'block';
        if (icon) icon.textContent = '▼';
    } else {
        box.style.display = 'none';
        if (icon) icon.textContent = '▶';
    }
}

function copyPayload(id, btn) {
    const codeEl = document.getElementById('payload-code-' + id);
    if (!codeEl) return;
    navigator.clipboard.writeText(codeEl.textContent).then(() => {
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    }).catch(() => {
        btn.textContent = 'Failed';
        setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
    });
}

function openPurgeModal() {
    const m = document.getElementById('modalPurgeLogs');
    if (m) m.classList.add('is-open');
}

function closePurgeModal() {
    const m = document.getElementById('modalPurgeLogs');
    if (m) m.classList.remove('is-open');
}

function navigateLogsPage(newPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', newPage);
    window.location.href = url.toString();
}

function navigateLogsParam(param, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(param, value);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// Close modal on Escape or backdrop click
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePurgeModal();
    }
});

const purgeModalEl = document.getElementById('modalPurgeLogs');
if (purgeModalEl) {
    purgeModalEl.addEventListener('click', function(e) {
        if (e.target === this) {
            closePurgeModal();
        }
    });
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>