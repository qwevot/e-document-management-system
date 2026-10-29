<?php
/**
 * Shared Upload Document Modal Specimen
 * Reused across Explorer and My Workspace; controlled by assets/js/explorer.js.
 */
$uploadModalParentId = (isset($parentId) && $parentId !== null && ($viewMode ?? '') === 'project')
    ? (string)$parentId
    : '';
$uploadModalTargetHtml = (!empty($currentNode) && ($viewMode ?? '') === 'project')
    ? ('Inside <strong>' . e($currentNode['name']) . '</strong>')
    : '<strong>Projects</strong>';

$modalCurrentUid = (int)(current_user()['id'] ?? 0);
$modalEligibleReviewers = function_exists('get_eligible_reviewers') ? get_eligible_reviewers($modalCurrentUid) : [];
?>
<!-- Modal: Upload Document Specimen -->
<div class="edms-modal-backdrop" id="modalUploadDoc">
    <div class="edms-modal-dialog" style="max-width: 520px; width: 95%;">
        <div class="edms-modal-header">
            <div class="edms-modal-title">
                <?php echo render_icon('cloud-upload'); ?>
                <span>Upload Document</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseUploadModal" title="Close modal">&times;</button>
        </div>
        <form id="formUploadDoc" method="POST" action="explorer.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="parent_id" id="uploadParentId" value="<?php echo e($uploadModalParentId); ?>">

            <div class="edms-modal-body">
                <!-- Dropzone Area -->
                <div class="upload-dropzone" id="uploadDropzone">
                    <input type="file" name="file" id="uploadFileInput" class="upload-file-input" required>
                    <div class="dropzone-content">
                        <div class="dropzone-icon">
                            <?php echo render_icon('cloud-upload'); ?>
                        </div>
                        <p class="dropzone-text">
                            <strong>Click to browse</strong> or drag &amp; drop your file here
                        </p>
                        <span class="dropzone-hint">PDF, DOCX, XLSX, DWG, PNG, ZIP, etc.</span>
                    </div>
                </div>

                <!-- Selected File Pill (Shown when file is selected) -->
                <div class="selected-file-preview" id="selectedFilePreview" style="display: none;">
                    <div class="preview-file-icon" id="previewFileIcon">
                        <?php echo render_icon('files'); ?>
                    </div>
                    <div class="preview-file-details">
                        <span class="preview-file-name" id="previewFileName">filename.ext</span>
                        <span class="preview-file-size" id="previewFileSize">0 KB</span>
                    </div>
                    <button type="button" class="btn-clear-selected-file" id="btnClearSelectedFile" title="Remove file">&times;</button>
                </div>

                <!-- Document Form Inputs -->
                <div class="form-group" style="margin-top: 14px;">
                    <label for="uploadDocName" class="form-label">Document Title</label>
                    <input type="text" id="uploadDocName" name="name" class="form-input" placeholder="e.g. Standard Operating Procedure" required autocomplete="off">
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label for="uploadDocStatus" class="form-label">Initial Status</label>
                    <div class="select-wrapper">
                        <select id="uploadDocStatus" name="status" class="form-input">
                            <option value="active" selected>Active (Draft)</option>
                            <option value="published">Published</option>
                            <option value="hold">On Hold</option>
                        </select>
                    </div>
                </div>

                <!-- Reviewer Selector (Revealed when Initial Status is On Hold) -->
                <div id="uploadReviewerSection" style="display: none; margin-top: 14px;">
                    <label class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span>Assign Reviewer(s) <span style="color: var(--red); font-weight: bold;">*</span></span>
                        <span style="font-size: 11px; color: var(--muted); font-weight: normal;">Select at least one approver</span>
                    </label>

                    <div style="border: 1px solid var(--border); border-radius: 8px; padding: 6px; background: var(--surface); max-height: 160px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px;">
                        <?php if (empty($modalEligibleReviewers)): ?>
                            <div style="font-size: 12px; color: var(--muted); padding: 10px; text-align: center;">
                                No eligible reviewers found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($modalEligibleReviewers as $r):
                                $rName = !empty($r['full_name']) ? $r['full_name'] : $r['username'];
                                $rInitials = get_initials($rName);
                                $rAvatar = !empty($r['avatar']) && is_file(__DIR__ . '/../' . $r['avatar']) ? BASE_URL . '/' . e($r['avatar']) : null;
                            ?>
                                <label class="reviewer-item-row" style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; cursor: pointer; transition: background 0.12s ease;" onmouseover="this.style.background='var(--surface-light)'" onmouseout="this.style.background='transparent'">
                                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                        <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; overflow: hidden;">
                                            <?php if ($rAvatar): ?>
                                                <img src="<?php echo $rAvatar; ?>" alt="<?php echo e($rName); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <span><?php echo e($rInitials); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <div style="font-size: 12.5px; font-weight: 600; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($rName); ?></div>
                                            <div style="font-size: 10.5px; color: var(--muted);">@<?php echo e($r['username']); ?> &bull; <?php echo ucfirst(e($r['role'])); ?></div>
                                        </div>
                                    </div>
                                    <input type="checkbox" name="reviewer_ids[]" value="<?php echo (int)$r['id']; ?>" class="upload-reviewer-checkbox" style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--green);">
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="form-help" id="uploadLocationHelp">
                    Target location: <?php echo $uploadModalTargetHtml; ?>
                </p>
            </div>
            <div class="edms-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelUpload">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitUpload">
                    <?php echo render_icon('cloud-upload'); ?>
                    <span>Upload Document</span>
                </button>
            </div>
        </form>
    </div>
</div>
