/**
 * EDMS Document Viewer — Lifecycle, Version History, Review Verdict & Access Controller
 * Decoupled from viewer.js so contributors working on workflows, modals, or version panels
 * can work independently of the PDF/Canvas annotation rendering engine.
 */

document.addEventListener("DOMContentLoaded", function () {
    const config = window.EDMS_WORKSPACE_CONFIG || {};
    const docId = config.docId;

    const btnToggleVersionHistoryDropdown = document.getElementById("btnToggleVersionHistoryDropdown");
    const btnToggleAuditHistoryDropdown = document.getElementById("btnToggleAuditHistoryDropdown");
    const auditHistoryFeed = document.getElementById("auditHistoryFeed");
    const btnBadgeVersion = document.getElementById("btnBadgeVersion");
    const versionTimelineContainer = document.getElementById("versionTimelineContainer");

    // Upload Revision Modal Elements
    const modalUploadRevision = document.getElementById("modalUploadRevision");
    const btnOpenUploadRevisionModal = document.getElementById("btnOpenUploadRevisionModal");
    const btnCloseUploadRevisionModal = document.getElementById("btnCloseUploadRevisionModal");
    const btnCancelRevisionUpload = document.getElementById("btnCancelRevisionUpload");
    const formUploadRevision = document.getElementById("formUploadRevision");
    const uploadDropzoneRevision = document.getElementById("uploadDropzoneRevision");
    const uploadFileInputRevision = document.getElementById("uploadFileInputRevision");
    const selectedFilePreviewRevision = document.getElementById("selectedFilePreviewRevision");
    const previewFileNameRevision = document.getElementById("previewFileNameRevision");
    const previewFileSizeRevision = document.getElementById("previewFileSizeRevision");
    const btnClearSelectedFileRevision = document.getElementById("btnClearSelectedFileRevision");
    const btnSubmitRevision = document.getElementById("btnSubmitRevision");

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }

    if (btnBadgeVersion) {
        btnBadgeVersion.addEventListener("click", function () {
            if (typeof window.switchViewerSidePanelTab === "function") {
                window.switchViewerSidePanelTab("versions");
            }
        });
    }

    // Reusable Modal Lifecycle Manager
    function bindModal(modal, openTriggers = [], closeTriggers = [], onOpen = null, onClose = null) {
        if (!modal) return { open: () => {}, close: () => {} };
        const open = () => {
            if (onOpen) onOpen();
            modal.classList.add("is-open");
        };
        const close = () => {
            modal.classList.remove("is-open");
            if (onClose) onClose();
        };
        openTriggers.forEach(btn => btn && btn.addEventListener("click", open));
        closeTriggers.forEach(btn => btn && btn.addEventListener("click", close));
        modal.addEventListener("click", e => { if (e.target === modal) close(); });
        return { open, close };
    }

    bindModal(
        modalUploadRevision,
        [btnOpenUploadRevisionModal],
        [btnCloseUploadRevisionModal, btnCancelRevisionUpload],
        null,
        () => { if (formUploadRevision) formUploadRevision.reset(); clearRevisionFileSelection(); }
    );

    // Dropzone File Handling
    if (uploadFileInputRevision) {
        uploadFileInputRevision.addEventListener("change", function () {
            if (this.files && this.files[0]) {
                displayRevisionFileSelection(this.files[0]);
            }
        });
    }

    if (uploadDropzoneRevision) {
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadDropzoneRevision.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadDropzoneRevision.classList.add("is-dragover");
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadDropzoneRevision.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadDropzoneRevision.classList.remove("is-dragover");
            });
        });

        uploadDropzoneRevision.addEventListener("drop", (e) => {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                if (uploadFileInputRevision) uploadFileInputRevision.files = e.dataTransfer.files;
                displayRevisionFileSelection(e.dataTransfer.files[0]);
            }
        });
    }

    function displayRevisionFileSelection(file) {
        if (!selectedFilePreviewRevision) return;
        if (previewFileNameRevision) previewFileNameRevision.textContent = file.name;
        if (previewFileSizeRevision) previewFileSizeRevision.textContent = `${(file.size / 1024).toFixed(1)} KB`;
        selectedFilePreviewRevision.style.display = "flex";
        if (uploadDropzoneRevision) uploadDropzoneRevision.style.display = "none";
    }

    function clearRevisionFileSelection() {
        if (uploadFileInputRevision) uploadFileInputRevision.value = "";
        if (selectedFilePreviewRevision) selectedFilePreviewRevision.style.display = "none";
        if (uploadDropzoneRevision) uploadDropzoneRevision.style.display = "block";
    }

    if (btnClearSelectedFileRevision) {
        btnClearSelectedFileRevision.addEventListener("click", clearRevisionFileSelection);
    }

    function loadVersionHistory() {
        if (!versionTimelineContainer) return;
        versionTimelineContainer.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--slate-muted); font-size: 13px;">
                <div class="viewer-spinner" style="margin: 0 auto 10px;"></div>
                <span>Loading revisions...</span>
            </div>
        `;

        fetch(`../actions/version-handler.php?action=get_versions&doc_id=${docId}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.versions)) {
                renderVersionTimeline(data.versions);
            } else {
                versionTimelineContainer.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--red);">${escapeHtml(data.message || 'Failed to load versions.')}</div>`;
            }
        })
        .catch(err => {
            console.error("Error fetching version lineage:", err);
            versionTimelineContainer.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--red);">Error loading versions.</div>`;
        });
    }
    window.loadVersionHistory = loadVersionHistory;

    function renderVersionTimeline(versions) {
        if (!versionTimelineContainer) return;
        if (versions.length === 0) {
            versionTimelineContainer.innerHTML = `<div style="text-align:center; padding: 24px 16px; color: var(--muted); font-size: 13px;">No revision records found.</div>`;
            return;
        }

        versionTimelineContainer.innerHTML = "";

        const activeVersionId = parseInt(config.activeVersionId, 10) || 0;
        const activeVersionName = config.activeVersionName || "";

        versions.forEach(v => {
            const vId = parseInt(v.id, 10);
            const isHeadCurrent = Boolean(parseInt(v.is_current, 10) === 1);
            const isCurrentlyViewed = activeVersionId > 0
                ? (vId === activeVersionId)
                : (isHeadCurrent || v.version_number === activeVersionName);

            const targetUrl = `view.php?id=${docId}&version_id=${v.id}`;

            const card = document.createElement("div");
            card.className = "version-timeline-card" + (isCurrentlyViewed ? " is-currently-viewed" : "");
            card.style.cssText = `
                background: ${isCurrentlyViewed ? 'var(--green-soft)' : 'var(--surface)'};
                border: 1px solid ${isCurrentlyViewed ? 'var(--green-line)' : 'var(--border)'};
                border-left: ${isCurrentlyViewed ? '3px solid var(--green)' : '1px solid var(--border)'};
                border-radius: 6px;
                padding: 9px 12px;
                cursor: pointer;
                transition: all 0.15s ease;
                position: relative;
            `;

            card.addEventListener("click", function (e) {
                if (e.target.closest("a") || e.target.closest("button") || e.target.closest("input") || e.target.closest(".version-details-panel")) {
                    return;
                }
                const urlParams = new URLSearchParams(window.location.search);
                const currentVerParam = parseInt(urlParams.get("version_id"), 10) || 0;
                if (!isCurrentlyViewed || currentVerParam !== vId) {
                    window.location.href = targetUrl;
                }
            });

            card.addEventListener("mouseenter", function () {
                if (!isCurrentlyViewed) {
                    card.style.background = "var(--surface-light)";
                    card.style.borderColor = "var(--slate-line)";
                }
            });
            card.addEventListener("mouseleave", function () {
                if (!isCurrentlyViewed) {
                    card.style.background = "var(--surface)";
                    card.style.borderColor = "var(--border)";
                }
            });

            const fileSizeFormatted = v.file_size ? `${(v.file_size / 1024).toFixed(1)} KB` : '';

            let statusPill = "";
            let st = (v.review_status || "").toLowerCase();
            if (v.is_current == 1 && v.doc_status === "suspend") st = "suspended";
            else if ((v.is_current == 1 && v.doc_status === "published") || (st === "approved" && /\.0$/.test(v.version_number))) st = "published";

            const colors = {
                suspended: ["var(--red-soft)", "var(--red-ink)", "var(--red-line)"],
                published: ["var(--success-badge-bg)", "var(--success-ink)", "var(--success-line)"],
                approved:  ["var(--success-badge-bg)", "var(--success-ink)", "var(--success-line)"],
                rejected:  ["var(--red-soft)", "var(--red-ink)", "var(--red-line)"],
                pending:   ["var(--amber-soft)", "var(--amber-ink)", "var(--amber-line)"]
            };
            if (colors[st]) {
                const [bg, col, brd] = colors[st];
                statusPill = `<span style="background:${bg}; color:${col}; border:1px solid ${brd}; font-size:10px; font-weight:700; padding:1px 6px; border-radius:4px; letter-spacing:0.02em;">${st.toUpperCase()}</span>`;
            }

            card.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-width: 0;">
                        <span style="font-family: monospace; font-size: 12.5px; font-weight: 700; color: var(--ink);">${escapeHtml(v.version_number)}</span>
                        ${statusPill}
                        <span title="${escapeHtml(v.created_at || '')} by ${escapeHtml(v.author_name || 'System')}" style="font-size: 11.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(v.author_name || 'System')} &bull; ${escapeHtml(v.created_at || '')}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 2px; flex-shrink: 0;">
                        <a href="download.php?id=${docId}&version_id=${v.id}" class="btn-action" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: var(--ink); text-decoration: none;" title="Download file" download>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                        <button type="button" class="btn-action btn-toggle-details" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: var(--muted); border: none; background: transparent; cursor: pointer;" title="Details">
                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="transition: transform 0.2s ease;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                    </div>
                </div>
                <div class="version-details-panel" style="display: none; margin-top: 8px; padding-top: 6px; border-top: 1px dashed var(--border); font-size: 11.5px; color: var(--muted);">
                    <div><strong>Created:</strong> ${escapeHtml(v.created_at || '')} by ${escapeHtml(v.author_name || 'System')}</div>
                    <div><strong>File:</strong> ${escapeHtml(v.file_original_name || '')} ${fileSizeFormatted ? `(${fileSizeFormatted})` : ''}</div>
                    ${v.change_summary ? `<div style="margin-top: 2px; color: var(--ink);"><strong>Note:</strong> ${escapeHtml(v.change_summary)}</div>` : ''}
                </div>
            `;

            const btnDetails = card.querySelector(".btn-toggle-details");
            const chevronIcon = card.querySelector(".chevron-icon");
            const panelDetails = card.querySelector(".version-details-panel");
            if (btnDetails && panelDetails) {
                btnDetails.addEventListener("click", function (ev) {
                    ev.stopPropagation();
                    const isHidden = panelDetails.style.display === "none";
                    panelDetails.style.display = isHidden ? "block" : "none";
                    if (chevronIcon) {
                        chevronIcon.style.transform = isHidden ? "rotate(180deg)" : "rotate(0deg)";
                    }
                });
            }

            versionTimelineContainer.appendChild(card);
        });
    }

    // Handle Upload New Revision Form Submission
    if (formUploadRevision) {
        formUploadRevision.addEventListener("submit", function (e) {
            e.preventDefault();

            if (!uploadFileInputRevision || !uploadFileInputRevision.files || uploadFileInputRevision.files.length === 0) {
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast("Please select a file to upload as revision.", "warning");
                } else {
                    alert("Please select a file to upload.");
                }
                return;
            }

            const formData = new FormData(formUploadRevision);

            if (btnSubmitRevision) {
                btnSubmitRevision.disabled = true;
                btnSubmitRevision.innerHTML = `
                    <div class="viewer-spinner" style="width:12px; height:12px; border-width:2px; margin-right:4px;"></div>
                    <span>Uploading...</span>
                `;
            }

            fetch("../actions/version-handler.php", {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `view.php?id=${docId}`;
                } else {
                    if (typeof window.showBottomToast === "function") {
                        window.showBottomToast(data.message || "Revision upload failed.", "error");
                    } else {
                        alert("Revision upload failed: " + (data.message || "Unknown error"));
                    }
                    if (btnSubmitRevision) {
                        btnSubmitRevision.disabled = false;
                        btnSubmitRevision.innerHTML = `<span>Upload Revision</span>`;
                    }
                }
            })
            .catch(err => {
                console.error("Error uploading revision:", err);
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast("Network error while uploading revision.", "error");
                } else {
                    alert("Network error while uploading revision.");
                }
                if (btnSubmitRevision) {
                    btnSubmitRevision.disabled = false;
                    btnSubmitRevision.innerHTML = `<span>Upload Revision</span>`;
                }
            });
        });
    }

    // --- Multi-Stage Document Review Pipeline Controller ---
    const btnOpenSubmitReviewModal = document.getElementById("btnOpenSubmitReviewModal");

    if (btnOpenSubmitReviewModal) {
        btnOpenSubmitReviewModal.addEventListener("click", function () {
            if (typeof window.openTieredWorkflowModal === "function") {
                const cfg = window.EDMS_WORKSPACE_CONFIG || config || {};
                const isDocHold = (cfg.isDocHold === true || cfg.docStatus === 'hold');
                const existingStages = (cfg.existingReviewStages && cfg.existingReviewStages.length > 0)
                    ? cfg.existingReviewStages
                    : null;

                if (isDocHold && existingStages) {
                    window.openTieredWorkflowModal({
                        stages: existingStages,
                        locked: true,
                        canManage: (cfg.canManageWorkflow === true)
                    });
                } else {
                    window.openTieredWorkflowModal({
                        stages: null,
                        locked: false,
                        canManage: true
                    });
                }
            }
        });
    }

    window.onTieredWorkflowCancelSubmission = function () {
        if (!confirm("Are you sure you want to cancel this review submission? The document will return to Active status.")) {
            return;
        }

        const btnCancelSub = document.getElementById("btnCancelSubmissionWf");
        if (btnCancelSub) {
            btnCancelSub.disabled = true;
            btnCancelSub.textContent = "Cancelling...";
        }

        fetch("../actions/review-handler.php?action=cancel_review", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify({ doc_id: docId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.href = `view.php?id=${docId}`;
            } else {
                alert(data.message || "Failed to cancel review submission.");
                if (btnCancelSub) {
                    btnCancelSub.disabled = false;
                    btnCancelSub.textContent = "Cancel Submission";
                }
            }
        })
        .catch(err => {
            console.error("Error cancelling review submission:", err);
            alert("Network error while cancelling review submission.");
            if (btnCancelSub) {
                btnCancelSub.disabled = false;
                btnCancelSub.textContent = "Cancel Submission";
            }
        });
    };

    window.onTieredWorkflowConfirmed = function (stages) {
        const btnConfirm = document.getElementById("btnConfirmWfPipeline");
        if (btnConfirm) {
            btnConfirm.disabled = true;
            btnConfirm.innerHTML = `<span>Submitting...</span>`;
        }

        fetch("../actions/review-handler.php?action=submit_review", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify({
                doc_id: docId,
                stages: stages
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (typeof window.closeTieredWorkflowModal === "function") {
                    window.closeTieredWorkflowModal();
                }
                window.location.href = `view.php?id=${docId}`;
            } else {
                alert(data.message || "Failed to submit review pipeline.");
                if (btnConfirm) {
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = `
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>Confirm Pipeline</span>
                    `;
                }
            }
        })
        .catch(err => {
            console.error("Error submitting review pipeline:", err);
            alert("Network error while submitting review pipeline.");
            if (btnConfirm) {
                btnConfirm.disabled = false;
                btnConfirm.innerHTML = `
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Confirm Pipeline</span>
                `;
            }
        });
    };

    // --- Finish Review & Verdict Handling ---
    const modalReviewVerdict = document.getElementById("modalReviewVerdict");
    const btnOpenFinishReviewModal = document.getElementById("btnOpenFinishReviewModal");
    const btnCloseVerdictModal = document.getElementById("btnCloseVerdictModal");
    const btnCancelVerdict = document.getElementById("btnCancelVerdict");
    const btnRejectVerdict = document.getElementById("btnRejectVerdict");
    const btnApproveVerdict = document.getElementById("btnApproveVerdict");
    const verdictComments = document.getElementById("verdictComments");

    bindModal(
        modalReviewVerdict,
        [btnOpenFinishReviewModal],
        [btnCloseVerdictModal, btnCancelVerdict],
        () => { if (verdictComments) verdictComments.value = ""; }
    );

    function executeReviewVerdict(verdict) {
        const comments = verdictComments?.value.trim() || "";

        if (btnApproveVerdict) btnApproveVerdict.disabled = true;
        if (btnRejectVerdict) btnRejectVerdict.disabled = true;
        if (btnCancelVerdict) btnCancelVerdict.disabled = true;

        const activeBtn = (verdict === "approved") ? btnApproveVerdict : btnRejectVerdict;
        if (activeBtn) activeBtn.innerHTML = `<span>Processing...</span>`;

        fetch("../actions/review-handler.php?action=submit_verdict", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify({
                doc_id: docId,
                verdict: verdict,
                comments: comments
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.href = `view.php?id=${docId}`;
            } else {
                alert(data.message || "Failed to record verdict.");
                if (btnApproveVerdict) {
                    btnApproveVerdict.disabled = false;
                    btnApproveVerdict.innerHTML = "Approve";
                }
                if (btnRejectVerdict) {
                    btnRejectVerdict.disabled = false;
                    btnRejectVerdict.innerHTML = "Reject";
                }
                if (btnCancelVerdict) btnCancelVerdict.disabled = false;
            }
        })
        .catch(err => {
            console.error("Error submitting verdict:", err);
            alert("Network error while recording verdict.");
            if (btnApproveVerdict) {
                btnApproveVerdict.disabled = false;
                btnApproveVerdict.innerHTML = "Approve";
            }
            if (btnRejectVerdict) {
                btnRejectVerdict.disabled = false;
                btnRejectVerdict.innerHTML = "Reject";
            }
            if (btnCancelVerdict) btnCancelVerdict.disabled = false;
        });
    }

    if (btnApproveVerdict) {
        btnApproveVerdict.addEventListener("click", () => executeReviewVerdict("approved"));
    }
    if (btnRejectVerdict) {
        btnRejectVerdict.addEventListener("click", () => executeReviewVerdict("rejected"));
    }

    // Retract Review Submission Handler
    const btnRetractReviewSubmission = document.getElementById("btnRetractReviewSubmission");
    if (btnRetractReviewSubmission) {
        btnRetractReviewSubmission.addEventListener("click", function () {
            if (!confirm("Are you sure you want to retract this review submission? The document will return to Active (Draft) status.")) {
                return;
            }

            btnRetractReviewSubmission.disabled = true;
            btnRetractReviewSubmission.textContent = "Retracting...";

            fetch("../actions/review-handler.php?action=cancel_review", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: JSON.stringify({ doc_id: docId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `view.php?id=${docId}`;
                } else {
                    alert(data.message || "Failed to retract review submission.");
                    btnRetractReviewSubmission.disabled = false;
                    btnRetractReviewSubmission.textContent = "Cancel Submission";
                }
            })
            .catch(err => {
                console.error("Error retracting review submission:", err);
                alert("Network error while retracting review submission.");
                btnRetractReviewSubmission.disabled = false;
                btnRetractReviewSubmission.textContent = "Cancel Submission";
            });
        });
    }

    // --- Document Access & Contributors Management ---
    const modalDocumentAccess = document.getElementById("modalDocumentAccess");
    const btnOpenDocAccessModal = document.getElementById("btnOpenDocAccessModal");
    const btnCloseAccessModal = document.getElementById("btnCloseAccessModal");
    const btnCancelAccessModal = document.getElementById("btnCancelAccessModal");
    const formDocumentAccess = document.getElementById("formDocumentAccess");
    const modeRestricted = document.getElementById("modeRestricted");
    const modePublic = document.getElementById("modePublic");
    const contributorsManagementSection = document.getElementById("contributorsManagementSection");
    const searchContributorInput = document.getElementById("searchContributorInput");
    const contributorsListContainer = document.getElementById("contributorsListContainer");
    const btnSaveAccessSettings = document.getElementById("btnSaveAccessSettings");

    let accessData = {
        access_mode: config.accessMode || 'restricted',
        owner: null,
        contributors: [],
        contributor_ids: [],
        eligible_users: []
    };

    function updateAccessModeUI(mode) {
        if (mode === "public") {
            if (modePublic) modePublic.checked = true;
            if (contributorsManagementSection) {
                contributorsManagementSection.style.opacity = "0.45";
                contributorsManagementSection.style.pointerEvents = "none";
            }
        } else {
            if (modeRestricted) modeRestricted.checked = true;
            if (contributorsManagementSection) {
                contributorsManagementSection.style.opacity = "1";
                contributorsManagementSection.style.pointerEvents = "auto";
            }
        }
    }

    if (modeRestricted) {
        modeRestricted.addEventListener("change", () => updateAccessModeUI("restricted"));
    }
    if (modePublic) {
        modePublic.addEventListener("change", () => updateAccessModeUI("public"));
    }

    function getContributorAvatarHtml(avatarPath, name, size = 28, bg = "var(--slate-soft)", color = "var(--ink)") {
        const initial = escapeHtml(name ? name.charAt(0).toUpperCase() : "U");
        if (avatarPath) {
            const cleanPath = String(avatarPath).replace(/^[\\\/]+/, "");
            const src = escapeHtml("../" + cleanPath);
            return `<div style="width: ${size}px; height: ${size}px; border-radius: 50%; overflow: hidden; background: ${bg}; color: ${color}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <img src="${src}" alt="${escapeHtml(name || '')}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.remove();">
                    </div>`;
        }
        return `<div style="width: ${size}px; height: ${size}px; border-radius: 50%; background: ${bg}; color: ${color}; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;">
                    ${initial}
                </div>`;
    }

    function renderContributorsList(searchQuery = "") {
        if (!contributorsListContainer) return;

        const q = searchQuery.trim().toLowerCase();
        let html = "";

        if (accessData.owner) {
            const o = accessData.owner;
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; background: var(--surface-light);">
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                        ${getContributorAvatarHtml(o.avatar, o.name, 28, 'var(--slate-soft)', 'var(--ink)')}
                        <div style="min-width: 0;">
                            <div style="font-size: 12.5px; font-weight: 600; color: var(--ink);">${escapeHtml(o.name)}</div>
                            <div style="font-size: 10.5px; color: var(--muted);">@${escapeHtml(o.username || '')}</div>
                        </div>
                    </div>
                    <span class="workspace-badge" style="font-size: 9.5px; background: var(--slate-soft); color: var(--slate-ink);">Owner</span>
                </div>
            `;
        }

        const currentContribs = (accessData.eligible_users || []).filter(u => (accessData.contributor_ids || []).includes(parseInt(u.id, 10)));
        currentContribs.forEach(u => {
            const uId = parseInt(u.id, 10);
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; background: var(--surface);">
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                        ${getContributorAvatarHtml(u.avatar, u.full_name || u.username, 28, 'var(--success-badge-bg)', 'var(--green-ink)')}
                        <div style="min-width: 0;">
                            <div style="font-size: 12.5px; font-weight: 600; color: var(--ink);">${escapeHtml(u.full_name || u.username)}</div>
                            <div style="font-size: 10.5px; color: var(--muted);">@${escapeHtml(u.username)} &bull; ${escapeHtml(u.department || 'Staff')}</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span class="workspace-badge" style="font-size: 9.5px; background: var(--green-soft); color: var(--green-ink);">Contributor</span>
                        <button type="button" class="btn-remove-contributor" data-uid="${uId}" style="border: none; background: transparent; color: var(--red); cursor: pointer; padding: 2px 6px; font-size: 14px; font-weight: 700;" title="Remove Contributor">&times;</button>
                    </div>
                </div>
            `;
        });

        if (q) {
            const nonContribs = (accessData.eligible_users || []).filter(u => {
                const isAlready = (accessData.contributor_ids || []).includes(parseInt(u.id, 10));
                if (isAlready) return false;
                const name = (u.full_name || "").toLowerCase();
                const uname = (u.username || "").toLowerCase();
                return name.includes(q) || uname.includes(q);
            });

            if (nonContribs.length > 0) {
                html += `<div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--muted); margin: 6px 0 2px 4px;">Click to Add:</div>`;
                nonContribs.forEach(u => {
                    const uId = parseInt(u.id, 10);
                    html += `
                        <div class="user-add-item" data-uid="${uId}" style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; border: 1px dashed var(--border); background: var(--surface-light); cursor: pointer; transition: all 0.12s ease;">
                            <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                ${getContributorAvatarHtml(u.avatar, u.full_name || u.username, 26, 'var(--slate-soft)', 'var(--ink)')}
                                <div style="min-width: 0;">
                                    <div style="font-size: 12px; font-weight: 600; color: var(--ink);">${escapeHtml(u.full_name || u.username)}</div>
                                    <div style="font-size: 10px; color: var(--muted);">@${escapeHtml(u.username)}</div>
                                </div>
                            </div>
                            <span style="font-size: 11px; font-weight: 600; color: var(--green);">+ Add</span>
                        </div>
                    `;
                });
            } else if (currentContribs.length === 0) {
                html += `<div style="text-align: center; padding: 12px; color: var(--muted); font-size: 12px;">No matching users found.</div>`;
            }
        } else if (currentContribs.length === 0) {
            html += `<div style="text-align: center; padding: 10px; color: var(--muted); font-size: 11.5px; font-style: italic;">No additional contributors invited yet. Use the search bar above to invite people.</div>`;
        }

        contributorsListContainer.innerHTML = html;

        contributorsListContainer.querySelectorAll(".btn-remove-contributor").forEach(btn => {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                const uid = parseInt(this.dataset.uid, 10);
                accessData.contributor_ids = accessData.contributor_ids.filter(id => id !== uid);
                renderContributorsList(searchContributorInput ? searchContributorInput.value : "");
            });
        });

        contributorsListContainer.querySelectorAll(".user-add-item").forEach(item => {
            item.addEventListener("click", function () {
                const uid = parseInt(this.dataset.uid, 10);
                if (!accessData.contributor_ids.includes(uid)) {
                    accessData.contributor_ids.push(uid);
                }
                if (searchContributorInput) searchContributorInput.value = "";
                renderContributorsList("");
            });
        });
    }

    if (searchContributorInput) {
        searchContributorInput.addEventListener("input", function () {
            renderContributorsList(this.value);
        });
    }

    bindModal(
        modalDocumentAccess,
        [btnOpenDocAccessModal],
        [btnCloseAccessModal, btnCancelAccessModal],
        () => {
            if (contributorsListContainer) {
                contributorsListContainer.innerHTML = `<div style="text-align:center; padding:12px; color:var(--muted); font-size:12px;">Loading access settings...</div>`;
            }
            fetch(`../actions/contributors-handler.php?action=get_access_data&doc_id=${docId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        accessData = data;
                        updateAccessModeUI(data.access_mode || 'restricted');
                        renderContributorsList("");
                    } else if (contributorsListContainer) {
                        contributorsListContainer.innerHTML = `<div style="color:var(--red); padding:10px; text-align:center;">Failed to load access settings.</div>`;
                    }
                })
                .catch(err => console.error("Error fetching access data:", err));
        }
    );

    if (formDocumentAccess) {
        formDocumentAccess.addEventListener("submit", function (e) {
            e.preventDefault();

            const selectedMode = modePublic && modePublic.checked ? "public" : "restricted";

            if (btnSaveAccessSettings) {
                btnSaveAccessSettings.disabled = true;
                btnSaveAccessSettings.textContent = "Saving...";
            }

            fetch("../actions/contributors-handler.php?action=save_access_data", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: JSON.stringify({
                    doc_id: docId,
                    access_mode: selectedMode,
                    contributor_ids: accessData.contributor_ids
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `view.php?id=${docId}`;
                } else {
                    alert(data.message || "Failed to save access settings.");
                    if (btnSaveAccessSettings) {
                        btnSaveAccessSettings.disabled = false;
                        btnSaveAccessSettings.textContent = "Save Changes";
                    }
                }
            })
            .catch(err => {
                console.error("Error saving access settings:", err);
                alert("Network error while saving access settings.");
                if (btnSaveAccessSettings) {
                    btnSaveAccessSettings.disabled = false;
                    btnSaveAccessSettings.textContent = "Save Changes";
                }
            });
        });
    }

    // --- Document Audit Trail Controller (Sidebar Versions Tab Dropdown) ---
    let auditHistoryLoaded = false;

    function fetchAndRenderAuditHistory(force = false) {
        if (!auditHistoryFeed || (auditHistoryLoaded && !force) || !docId) return;

        fetch(`view.php?action=get_audit_history&id=${docId}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && auditHistoryFeed) {
                auditHistoryFeed.innerHTML = data.html || '';
                auditHistoryLoaded = true;
            }
        })
        .catch(err => console.error("Error loading audit history:", err));
    }
    window.fetchAndRenderAuditHistory = fetchAndRenderAuditHistory;

    if (btnToggleVersionHistoryDropdown && versionTimelineContainer) {
        btnToggleVersionHistoryDropdown.addEventListener("click", function (e) {
            if (e.target.closest("#btnOpenUploadRevisionModal")) return;
            const isCurrentlyCollapsed = versionTimelineContainer.classList.contains("is-collapsed");
            versionTimelineContainer.classList.toggle("is-collapsed", !isCurrentlyCollapsed);
            this.classList.toggle("is-open", isCurrentlyCollapsed);
        });
    }

    if (btnToggleAuditHistoryDropdown && auditHistoryFeed) {
        btnToggleAuditHistoryDropdown.addEventListener("click", function () {
            const isCurrentlyCollapsed = auditHistoryFeed.classList.contains("is-collapsed");
            auditHistoryFeed.classList.toggle("is-collapsed", !isCurrentlyCollapsed);
            this.classList.toggle("is-open", isCurrentlyCollapsed);
            if (isCurrentlyCollapsed && !auditHistoryLoaded) {
                fetchAndRenderAuditHistory(true);
            }
        });
    }
});
