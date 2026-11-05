<?php
/**
 * EDMS Document Viewer Modals
 * Extracted from pages/view.php for modular readability:
 * - Upload Document Revision (#modalUploadRevision)
 * - Multi-Stage Review Pipeline (includes/workflow-modal.php)
 * - Finish Review & Submit Verdict (#modalReviewVerdict)
 * - Document Access & Contributors (#modalDocumentAccess)
 * - Suspend Document Confirmation (#modalSuspendDoc)
 */
?>
<!-- 2. Modal: Upload Document Revision (Matches explorer.php style) -->
<div class="edms-modal-backdrop" id="modalUploadRevision">
    <div class="edms-modal-dialog" style="max-width: 520px; width: 95%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <span>Upload Document Revision</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseUploadRevisionModal" title="Close modal">&times;</button>
        </div>
        <form id="formUploadRevision" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_revision">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body">
                <!-- Dropzone Area -->
                <div class="upload-dropzone" id="uploadDropzoneRevision">
                    <input type="file" name="file" id="uploadFileInputRevision" class="upload-file-input" required>
                    <div class="dropzone-content">
                        <div class="dropzone-icon">
                            <?php echo render_icon('cloud-upload'); ?>
                        </div>
                        <p class="dropzone-text">
                            <strong>Click to browse</strong> or drag &amp; drop your file here
                        </p>
                        <span class="dropzone-hint">Will be saved as revision <strong><?php echo e($nextMinorVer); ?></strong></span>
                    </div>
                </div>

                <!-- Selected File Pill (Shown when file is selected) -->
                <div class="selected-file-preview" id="selectedFilePreviewRevision" style="display: none;">
                    <div class="preview-file-icon">
                        <?php echo render_icon('files'); ?>
                    </div>
                    <div class="preview-file-details">
                        <span class="preview-file-name" id="previewFileNameRevision">filename.ext</span>
                        <span class="preview-file-size" id="previewFileSizeRevision">0 KB</span>
                    </div>
                    <button type="button" class="btn-clear-selected-file" id="btnClearSelectedFileRevision" title="Remove file">&times;</button>
                </div>

                <!-- Revision Notes -->
                <div class="form-group" style="margin-top: 14px;">
                    <label for="revisionNotes" class="form-label">Revision Summary (Optional)</label>
                    <textarea id="revisionNotes" name="change_summary" rows="2" class="form-input" placeholder="What changed in this revision?"></textarea>
                </div>
            </div>

            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelRevisionUpload">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitRevision">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Revision (<?php echo e($nextMinorVer); ?>)</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal: Multi-Stage Review Pipeline -->
<?php include __DIR__ . '/workflow-modal.php'; ?>

<!-- 4. Modal: Finish Review & Submit Verdict -->
<div class="edms-modal-backdrop" id="modalReviewVerdict">
    <div class="edms-modal-dialog" style="max-width: 480px;">
        <div class="edms-modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>Finish Document Review</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseVerdictModal" title="Close modal">&times;</button>
        </div>
        <form id="formReviewVerdict">
            <input type="hidden" name="action" value="submit_verdict">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">
            <input type="hidden" name="verdict" id="verdictTypeValue" value="approved">

            <div class="edms-modal-body" style="padding: 18px 20px;">
                <!-- Specimen Info Strip -->
                <div style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--ink);"><?php echo e($doc['name']); ?></div>
                        <div style="font-size: 11px; color: var(--muted);">Version <?php echo e($doc['version'] ?? 'v1.0'); ?> &bull; Sign-Offs: <?php echo $approvedCount; ?>/<?php echo max(1, $allReviewersCount); ?> Approved</div>
                    </div>
                    <span class="workspace-badge" style="background: var(--amber-soft); color: var(--amber-ink); border: 1px solid var(--amber-line);">
                        Hold
                    </span>
                </div>

                <!-- Feedback Comments -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="verdictComments" class="form-label" style="font-size: 12px; font-weight: 600;">Remarks / Notes (Optional)</label>
                    <textarea id="verdictComments" name="comments" rows="3" class="form-input" placeholder="Add optional remarks or notes..." style="font-size: 12.5px; resize: vertical; border-radius: 6px;"></textarea>
                    <span class="form-help" style="font-size: 11px; color: var(--muted); margin-top: 4px; display: block;">Visual canvas annotations made during review remain attached to this version.</span>
                </div>
            </div>

            <div class="edms-modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="btnCancelVerdict" style="padding: 6px 14px; font-size: 12.5px;">Cancel</button>
                <button type="button" class="btn btn-secondary" id="btnRejectVerdict" style="padding: 6px 14px; font-size: 12.5px; color: var(--red); border-color: var(--red-line); background: var(--surface); font-weight: 600;">Reject</button>
                <button type="button" class="btn btn-primary" id="btnApproveVerdict" style="padding: 6px 16px; font-size: 12.5px; background: var(--green); border-color: var(--green); font-weight: 600;">Approve</button>
            </div>
        </form>
    </div>
</div>

<!-- 5. Modal: Document Access & Contributors (Google Drive Share Style) -->
<div class="edms-modal-backdrop" id="modalDocumentAccess">
    <div class="edms-modal-dialog" style="max-width: 520px;">
        <div class="edms-modal-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                <span>Document Access &amp; Contributors</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseAccessModal" title="Close modal">&times;</button>
        </div>
        <form id="formDocumentAccess">
            <input type="hidden" name="action" value="save_access_data">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">

            <div class="edms-modal-body" style="padding: 18px 20px;">
                <!-- Access Mode Segmented Switch -->
                <div style="margin-bottom: 18px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px;">
                        General Access
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface);">
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="radio" name="access_mode" value="restricted" id="modeRestricted" <?php echo $accessMode === 'restricted' ? 'checked' : ''; ?> style="margin-top: 3px; accent-color: var(--green);">
                            <div>
                                <div style="font-size: 13px; font-weight: 600; color: var(--ink);">Restricted</div>
                                <div style="font-size: 11.5px; color: var(--muted); line-height: 1.3;">Only the Document Owner and invited Contributors can annotate, update versions, and submit reviews.</div>
                            </div>
                        </label>
                        <div style="height: 1px; background: var(--border);"></div>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="radio" name="access_mode" value="public" id="modePublic" <?php echo $accessMode === 'public' ? 'checked' : ''; ?> style="margin-top: 3px; accent-color: var(--green);">
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 13px; font-weight: 600; color: var(--ink);">Public (Anyone can Edit)</span>
                                </div>
                                <div style="font-size: 11.5px; color: var(--muted); line-height: 1.3;">All authenticated users can annotate, upload revisions, and submit for review.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Add Contributor Input -->
                <div id="contributorsManagementSection" style="<?php echo $accessMode === 'public' ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px;">
                        Manage Specific Contributors
                    </div>
                    
                    <div style="position: relative; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; background: var(--surface);">
                            <span style="color: var(--muted); display: flex; align-items: center;"><?php echo render_icon('search'); ?></span>
                            <input type="text" id="searchContributorInput" placeholder="Search people to add as contributors..." style="border: none; background: transparent; outline: none; width: 100%; font-size: 13px; color: var(--ink);" autocomplete="off">
                        </div>
                    </div>

                    <!-- Contributors List Container -->
                    <div id="contributorsListContainer" style="max-height: 170px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; border: 1px solid var(--border); border-radius: 8px; padding: 6px; background: var(--surface);">
                        <div style="text-align: center; padding: 12px; color: var(--muted); font-size: 12px;">Loading contributors...</div>
                    </div>
                </div>
            </div>

            <div class="edms-modal-footer" style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="btnCancelAccessModal" style="padding: 6px 14px; font-size: 12.5px;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveAccessSettings" style="padding: 6px 18px; font-size: 12.5px; background: var(--green); border-color: var(--green);">
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($isDocPublished && $isContributor): ?>
<!-- Modal: Suspend Document Confirmation -->
<div class="edms-modal-backdrop" id="modalSuspendDoc" onclick="if(event.target===this)this.classList.remove('is-open')">
    <div class="edms-modal-dialog" style="max-width: 440px;">
        <div class="edms-modal-header">
            <div class="edms-modal-title" style="color: var(--red-ink)"><?php echo render_icon('close'); ?><span>Suspend Document</span></div>
            <button type="button" class="btn-modal-close" onclick="document.getElementById('modalSuspendDoc').classList.remove('is-open')">&times;</button>
        </div>
        <div class="edms-modal-body">
            <p style="font-size: 13.5px; margin: 0 0 6px;">Are you sure you want to suspend <strong><?php echo e($doc['name']); ?></strong>?</p>
            <p style="font-size: 12px; color: var(--muted); margin: 0;">This will freeze the document for a major revision cycle.</p>
        </div>
        <div class="edms-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalSuspendDoc').classList.remove('is-open')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="this.disabled=true;this.textContent='Suspending...';fetch('../actions/version-handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=suspend_document&doc_id=<?php echo (int)$docId; ?>&csrf_token=<?php echo urlencode(csrf_token()); ?>'}).then(()=>location.reload())">Suspend</button>
        </div>
    </div>
</div>
<?php endif; ?>
