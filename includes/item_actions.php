<?php
/**
 * EDMS Shared Item Actions Popover & Modals
 * Unified component for item operations: Open Workspace, Download, Copy Link, Audit History, Rename, Move, Archive
 */
?>
<!-- Floating Action Popover Menu (3-Dots Context Menu & Right Click Menu) -->
<div class="edms-action-popover" id="edmsActionPopover" style="display: none;">
    <div class="popover-header-label" id="popoverHeaderLabel" style="display: none;">1 item selected</div>
    <a href="#" class="popover-item" id="popoverActionView" style="text-decoration: none;">
        <?php echo render_icon('view'); ?>
        <span>Open Workspace</span>
    </a>
    <a href="#" class="popover-item" id="popoverActionDownload" style="text-decoration: none;">
        <?php echo render_icon('download'); ?>
        <span>Download</span>
    </a>
    <button type="button" class="popover-item" id="popoverActionCopyLink">
        <?php echo render_icon('link'); ?>
        <span>Copy Link</span>
    </button>
    <button type="button" class="popover-item" id="popoverActionAuditHistory">
        <?php echo render_icon('history'); ?>
        <span>Audit History</span>
    </button>
    <?php if (can_edit_dms()): ?>
    <button type="button" class="popover-item" id="popoverActionRename">
        <?php echo render_icon('edit'); ?>
        <span>Rename</span>
    </button>
    <button type="button" class="popover-item" id="popoverActionMove">
        <?php echo render_icon('move'); ?>
        <span id="popoverActionMoveLabel">Move to...</span>
    </button>
    <div class="popover-divider"></div>
    <button type="button" class="popover-item is-danger" id="popoverActionDelete">
        <?php echo render_icon('archive'); ?>
        <span id="popoverActionDeleteLabel">Trash</span>
    </button>
    <?php endif; ?>
</div>

<!-- Modal: Rename Item -->
<div class="edms-modal-backdrop" id="modalRenameNode">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('edit'); ?>
                <span>Rename Item</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseRenameModal" title="Close modal">&times;</button>
        </div>
        <form id="formRenameNode" method="POST" action="explorer.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="rename_node">
            <input type="hidden" name="id" id="renameNodeId" value="">
            <div class="edms-modal-body">
                <div class="form-group">
                    <label for="renameNodeName" class="form-label">Item Name</label>
                    <input type="text" id="renameNodeName" name="name" class="form-input" required autocomplete="off">
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelRename">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRename">
                    <span>Save Name</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Move Item (Google Drive Style Navigator) -->
<div class="edms-modal-backdrop" id="modalMoveNode">
    <div class="edms-modal-dialog modal-dialog-drive">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <span>Moving: <strong id="moveModalTitleName">Item</strong></span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseMoveModal" title="Close modal">&times;</button>
        </div>
        <form id="formMoveNode" method="POST" action="explorer.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="moveNodeAction" value="move_node">
            <input type="hidden" name="id" id="moveNodeId" value="">
            <input type="hidden" name="ids" id="moveNodeIds" value="">
            <input type="hidden" name="target_folder_id" id="moveTargetFolderId" value="">
            
            <div class="edms-modal-body" style="padding-bottom: 8px;">
                <div class="drive-move-header">
                    <span class="drive-move-label">Current Location:</span>
                    <strong id="moveItemLocationDisplay" class="drive-move-target-name">Projects</strong>
                </div>

                <!-- Interactive Breadcrumb Directory for Destination -->
                <div class="drive-move-path-bar">
                    <div class="drive-move-breadcrumbs" id="driveMoveBreadcrumbs">
                        <!-- Populated by JS: e.g. Root > Folder A > Subfolder B -->
                    </div>
                </div>

                <!-- Folder Navigation Container -->
                <div class="drive-move-explorer">
                    <div class="drive-move-list" id="driveMoveFolderList">
                        <!-- Populated dynamically with folders -->
                    </div>
                </div>
                
                <div class="drive-move-current-target">
                    <span>Destination: </span>
                    <strong id="driveMoveSelectedName">Projects</strong>
                </div>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelMove">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitMove">
                    <span>Move Here</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Move to Archives / Obsolete Confirmation -->
<div class="edms-modal-backdrop" id="modalDeleteNode">
    <div class="edms-modal-dialog">
        <div class="edms-modal-header">
            <div class="edms-modal-title" style="color: var(--amber-dark, var(--ink));">
                <span>Trash</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseDeleteModal" title="Close modal">&times;</button>
        </div>
        <form id="formDeleteNode" method="POST" action="explorer.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="deleteNodeAction" value="delete_node">
            <input type="hidden" name="id" id="deleteNodeId" value="">
            <input type="hidden" name="ids" id="deleteNodeIds" value="">
            <div class="edms-modal-body">
                <p style="font-size: 13.5px; color: var(--ink); margin-bottom: 8px;">
                    Move <strong id="deleteItemNameDisplay"></strong> to Archives?
                </p>
                <p class="form-help" style="color: var(--muted); line-height: 1.5;">
                    This document will be marked as <strong>OBSOLETE</strong> and archived away from the active workspace. It can be viewed in the Archives repository.
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelDelete">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitDelete">
                    <?php echo render_icon('archive'); ?>
                    <span>Archive Item</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Document Audit History -->
<div class="edms-modal-backdrop" id="modalAuditHistory">
    <div class="edms-modal-dialog" style="max-width: 580px; width: 90%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('history'); ?>
                <span>Audit History: <strong id="auditModalDocName" style="font-weight: 600;">Document</strong></span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseAuditModal" title="Close modal">&times;</button>
        </div>
        <div class="edms-modal-body" style="max-height: 480px; overflow-y: auto; padding: 16px;">
            <div id="auditModalFeed" class="annotation-history-feed" style="max-height: none; overflow: visible;">
                <div class="history-empty-state">
                    <span>Loading audit records...</span>
                </div>
            </div>
        </div>
        <div class="edms-modal-footer">
            <button type="button" class="btn btn-secondary" id="btnCancelAuditModal">Close</button>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/explorer.js" defer></script>
