<?php
$pageTitle = 'Users & Roles';
$activeNav  = 'users';

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role('super admin');

$users = [];
$dbError = '';

try {
    $pdo = db();
    $users = $pdo->query('
        SELECT id, username, full_name, role, department, avatar, created_at 
        FROM users 
        ORDER BY id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dbError = 'Could not load users from the database.';
}

$roles = available_roles();
$currentUserId = (int)(current_user()['id'] ?? 0);

include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/../includes/flash.php'; ?>

<div class="users-section">
    <!-- Header Bar: Title, Search, and Add User Button -->
    <div class="users-header-card">
        <div class="users-header-title">
            <?php echo render_icon('users'); ?>
            <span>User Management</span>
            <span style="font-size:12px; font-weight:600; color:var(--muted); background:var(--surface); border:1px solid var(--border); padding:2px 8px; border-radius:12px;">
                <?php echo count($users); ?> Accounts
            </span>
        </div>

        <div class="users-header-actions">
            <input type="text" id="userSearchInput" class="users-search-input" placeholder="Search user or department..." oninput="filterUsersTable(this.value)">
            <button type="button" class="btn primary" onclick="openAddUserModal()">
                <?php echo render_icon('plus'); ?>
                <span>Add User</span>
            </button>
        </div>
    </div>

    <!-- User Data Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="no-col">No.</th>
                    <th class="action-col">Action</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:var(--muted); padding:40px;">
                            No user data recorded. Click "Add User" above to create an account.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $idx => $u): ?>
                        <?php
                        $roleSlug = strtolower(str_replace(' ', '-', (string)$u['role']));
                        $dispName = !empty($u['full_name']) ? $u['full_name'] : $u['username'];
                        $avatarUrl = !empty($u['avatar']) && is_file(__DIR__ . '/../' . $u['avatar']) ? BASE_URL . '/' . e($u['avatar']) : null;
                        $initials = get_initials($dispName);
                        ?>
                        <tr class="user-data-row" 
                            data-username="<?php echo e(mb_strtolower((string)$u['username'])); ?>"
                            data-fullname="<?php echo e(mb_strtolower((string)($u['full_name'] ?? ''))); ?>"
                            data-dept="<?php echo e(mb_strtolower((string)($u['department'] ?? ''))); ?>"
                            data-role="<?php echo e(mb_strtolower((string)$u['role'])); ?>">
                            <td class="no-col"><?php echo $idx + 1; ?></td>
                            <td class="action-col">
                                <div class="row-actions">
                                    <button type="button" class="btn icon-btn edit" title="Edit User" 
                                            onclick="openEditUserModal(<?php echo (int)$u['id']; ?>, <?php echo e(json_encode($u['username'])); ?>, <?php echo e(json_encode($u['full_name'] ?? '')); ?>, <?php echo e(json_encode($u['role'])); ?>, <?php echo e(json_encode($u['department'] ?? '')); ?>)">
                                        <?php echo render_icon('edit'); ?>
                                    </button>
                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <button type="button" class="btn icon-btn delete" title="Delete User"
                                                onclick="confirmDeleteUser(<?php echo (int)$u['id']; ?>, <?php echo e(json_encode($u['username'])); ?>)">
                                            <?php echo render_icon('trash'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="user-cell-wrap">
                                    <div class="user-cell-avatar">
                                        <?php if ($avatarUrl): ?>
                                            <img src="<?php echo $avatarUrl; ?>" alt="<?php echo e($dispName); ?>">
                                        <?php else: ?>
                                            <span><?php echo e($initials); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="user-cell-name"><?php echo e($dispName); ?></div>
                                        <div class="user-cell-username">@<?php echo e($u['username']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge <?php echo e($roleSlug); ?>">
                                    <?php echo e(ucfirst($u['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 500; color: var(--text);">
                                    <?php echo e($u['department'] ?: 'Engineering'); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 12px; color: var(--muted);">
                                    <?php echo date('M d, Y H:i', strtotime($u['created_at'])); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add User -->
<div class="edms-modal-backdrop" id="addUserModal">
    <div class="edms-modal-dialog">
        <form method="POST" action="<?php echo BASE_URL; ?>/actions/save-user.php">
            <?php echo csrf_field(); ?>
            <div class="edms-modal-header">
                <div class="edms-modal-title">
                    <?php echo render_icon('plus'); ?>
                    <span>Create User Specimen</span>
                </div>
                <button type="button" class="btn-modal-close" onclick="closeAddUserModal()">&times;</button>
            </div>
            <div class="edms-modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="addUsername">Username *</label>
                        <input type="text" id="addUsername" name="username" required maxlength="50" autocomplete="off" placeholder="e.g. jdoe">
                    </div>
                    <div class="field">
                        <label for="addFullName">Full Name</label>
                        <input type="text" id="addFullName" name="full_name" maxlength="100" placeholder="e.g. John Doe">
                    </div>
                    <div class="field">
                        <label for="addRole">Role</label>
                        <select id="addRole" name="role" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?php echo e($r); ?>" <?php echo $r === 'viewer' ? 'selected' : ''; ?>>
                                    <?php echo e(ucwords($r)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="addDepartment">Department</label>
                        <input type="text" id="addDepartment" name="department" maxlength="50" value="Engineering" placeholder="e.g. Engineering, Quality, Operations">
                    </div>
                    <div class="field full">
                        <label for="addPassword">Temporary Password *</label>
                        <input type="password" id="addPassword" name="password" required minlength="6" autocomplete="new-password" placeholder="Min. 6 characters">
                    </div>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn cancel" onclick="closeAddUserModal()">Cancel</button>
                <button type="submit" class="btn primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="edms-modal-backdrop" id="editUserModal">
    <div class="edms-modal-dialog">
        <form method="POST" action="<?php echo BASE_URL; ?>/actions/save-user.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="editUserId">
            <div class="edms-modal-header">
                <div class="edms-modal-title">
                    <?php echo render_icon('edit'); ?>
                    <span>Modify User Credentials</span>
                </div>
                <button type="button" class="btn-modal-close" onclick="closeEditUserModal()">&times;</button>
            </div>
            <div class="edms-modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="editUsername">Username *</label>
                        <input type="text" id="editUsername" name="username" required maxlength="50" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="editFullName">Full Name</label>
                        <input type="text" id="editFullName" name="full_name" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="editRole">Role</label>
                        <select id="editRole" name="role" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?php echo e($r); ?>"><?php echo e(ucwords($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="editDepartment">Department</label>
                        <input type="text" id="editDepartment" name="department" maxlength="50">
                    </div>
                    <div class="field full">
                        <label for="editPassword">Reset Password (leave blank to retain current)</label>
                        <input type="password" id="editPassword" name="password" minlength="6" autocomplete="new-password" placeholder="Enter new password if changing">
                    </div>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn cancel" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="btn primary">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Delete Confirmation -->
<div class="edms-modal-backdrop" id="deleteUserModal">
    <div class="edms-modal-dialog" style="max-width: 420px;">
        <form method="POST" action="<?php echo BASE_URL; ?>/actions/delete-user.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="deleteUserId">
            <div class="edms-modal-header">
                <div class="edms-modal-title" style="color:var(--red);">
                    <?php echo render_icon('trash'); ?>
                    <span>Remove User Account</span>
                </div>
                <button type="button" class="btn-modal-close" onclick="closeDeleteUserModal()">&times;</button>
            </div>
            <div class="edms-modal-body">
                <p style="font-size:13.5px; color:var(--text); line-height:1.5; margin:0;">
                    Are you certain you wish to remove account <strong id="deleteUserDisplay" style="color:var(--ink);"></strong>? 
                    This operation is irreversible.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn cancel" onclick="closeDeleteUserModal()">Cancel</button>
                <button type="submit" class="btn danger">Delete Account</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddUserModal() {
    document.getElementById('addUserModal').classList.add('is-open');
    setTimeout(() => document.getElementById('addUsername')?.focus(), 50);
}

function closeAddUserModal() {
    document.getElementById('addUserModal').classList.remove('is-open');
}

function openEditUserModal(id, username, fullName, role, department) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUsername').value = username;
    document.getElementById('editFullName').value = fullName || '';
    document.getElementById('editRole').value = role;
    document.getElementById('editDepartment').value = department || 'Engineering';
    document.getElementById('editPassword').value = '';
    document.getElementById('editUserModal').classList.add('is-open');
}

function closeEditUserModal() {
    document.getElementById('editUserModal').classList.remove('is-open');
}

function confirmDeleteUser(id, username) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserDisplay').textContent = '@' + username;
    document.getElementById('deleteUserModal').classList.add('is-open');
}

function closeDeleteUserModal() {
    document.getElementById('deleteUserModal').classList.remove('is-open');
}

// Client-side quick filter
function filterUsersTable(query) {
    const term = (query || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.user-data-row');
    rows.forEach(row => {
        const u = row.getAttribute('data-username') || '';
        const f = row.getAttribute('data-fullname') || '';
        const d = row.getAttribute('data-dept') || '';
        const r = row.getAttribute('data-role') || '';
        const match = u.includes(term) || f.includes(term) || d.includes(term) || r.includes(term);
        row.style.display = match ? '' : 'none';
    });
}

// Close modals on Escape key or backdrop click
document.querySelectorAll('.edms-modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('is-open');
        }
    });
});
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.edms-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
