<?php
/**
 * EDMS Tiered Sequential Workflow Modal Specimen
 * Location: includes/workflow-modal.php
 * Reuses the audit-timeline line & dots aesthetic adapted into a horizontal, scrollable approval chain.
 * Borderless, lightweight design with Google-style user search.
 */

$wfCurrentUid = (int)(current_user()['id'] ?? 0);
$wfReviewers = function_exists('get_eligible_reviewers') ? get_eligible_reviewers($wfCurrentUid) : [];
?>

<!-- Tiered Workflow Configuration Modal -->
<div class="edms-modal-backdrop" id="modalTieredWorkflow" aria-hidden="true">
    <div class="edms-modal-dialog" style="max-width: 860px; width: 95%;">
        
        <!-- Header -->
        <div class="edms-modal-header" style="padding: 18px 24px; border-bottom: 1px solid var(--border);">
            <div class="edms-modal-title" style="display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px;">
                <?php echo function_exists('render_icon') ? render_icon('files') : '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>'; ?>
                <span>Submit Review</span>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseWfModal" title="Close" style="font-size: 22px; width: 32px; height: 32px;">&times;</button>
        </div>

        <form id="formTieredWorkflow">
            <div class="edms-modal-body" style="padding: 20px 20px 14px;">
                
                <!-- Horizontal Scrollable Audit-Timeline Stepper Canvas -->
                <div id="wfStepperViewport" style="width: 100%; overflow-x: auto; overflow-y: hidden; padding: 10px 4px 18px; box-sizing: border-box;">
                    <div style="position: relative; display: inline-flex; align-items: flex-start; min-width: 100%; padding: 12px 14px 6px; box-sizing: border-box;">
                        
                        <!-- Connecting Line (Audit Trail Line) -->
                        <div style="position: absolute; top: 26px; left: 55px; right: 55px; height: 2px; background: var(--border); z-index: 1;"></div>

                        <!-- Dynamic Stages Container -->
                        <div id="wfStagesList" style="display: flex; align-items: flex-start; gap: 16px; z-index: 2;">
                            <!-- Populated dynamically by JS -->
                        </div>

                        <!-- Add Stage Control on Timeline Axis -->
                        <div id="btnAddWfStage" role="button" tabindex="0" title="Add Stage" style="position: relative; display: flex; flex-direction: column; align-items: center; width: 65px; margin-left: 10px; cursor: pointer; user-select: none; z-index: 2;" onmouseover="this.querySelector('.wf-dot-add').style.background='var(--green)'; this.querySelector('.wf-dot-add').style.color='#fff'; this.querySelector('span').style.color='var(--green)';" onmouseout="this.querySelector('.wf-dot-add').style.background='var(--surface)'; this.querySelector('.wf-dot-add').style.color='var(--green)'; this.querySelector('span').style.color='var(--muted)';">
                            <div class="wf-dot-add" style="width: 30px; height: 30px; border-radius: 50%; box-sizing: border-box; box-shadow: 0 0 0 2px var(--green), 0 0 0 4px var(--surface); display: flex; align-items: center; justify-content: center; background: var(--surface); color: var(--green); margin-bottom: 12px;">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </div>
                            <span style="font-size: 12.5px; font-weight: 600; color: var(--muted); text-align: center;">+ Stage</span>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="edms-modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" id="btnCancelSubmissionWf" style="padding: 9px 18px; font-size: 13.5px; color: var(--red); border-color: var(--red-line); background: var(--surface); display: none;">Cancel Submission</button>
                <button type="button" class="btn btn-secondary" id="btnEditReviewerWf" style="padding: 9px 18px; font-size: 13.5px; display: none;">Edit Reviewer</button>
                <button type="button" class="btn btn-secondary" id="btnCancelWfModal" style="padding: 9px 18px; font-size: 13.5px;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnConfirmWfPipeline" style="padding: 9px 20px; font-size: 13.5px; gap: 7px;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Submit</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Embedded Reviewers Registry for Modal Autocompletion -->
<script>
window.EDMS_WORKFLOW_REVIEWERS = <?php echo json_encode(array_map(function($r) {
    return [
        'id'         => (int)$r['id'],
        'name'       => !empty($r['full_name']) ? $r['full_name'] : $r['username'],
        'username'   => $r['username'],
        'role'       => ucfirst($r['role']),
        'department' => $r['department'] ?? 'Staff',
        'avatar'     => $r['avatar'] ?? null,
        'initials'   => get_initials(!empty($r['full_name']) ? $r['full_name'] : $r['username'])
    ];
}, $wfReviewers)); ?>;
</script>

<!-- Multi-Stage Review Pipeline Interaction Engine -->
<script>
(function() {
    const reviewers = window.EDMS_WORKFLOW_REVIEWERS || [];
    const viewport = document.getElementById("wfStepperViewport");
    const stagesList = document.getElementById("wfStagesList");
    const btnAddStage = document.getElementById("btnAddWfStage");
    const modal = document.getElementById("modalTieredWorkflow");
    const btnClose = document.getElementById("btnCloseWfModal");
    const btnCancel = document.getElementById("btnCancelWfModal");
    const btnCancelSubmissionWf = document.getElementById("btnCancelSubmissionWf");
    const btnEditReviewerWf = document.getElementById("btnEditReviewerWf");
    const btnConfirm = document.getElementById("btnConfirmWfPipeline");
    const form = document.getElementById("formTieredWorkflow");

    // Internal model: start clean with no pre-assigned reviewers to prevent mistakes
    let stages = [
        { id: 1, reviewerIds: [] }
    ];
    let isLocked = false;
    let canManage = true;

    function getAvatarHtml(avatarPath, name, initials) {
        if (avatarPath) {
            const cleanPath = String(avatarPath).replace(/^[\\\/]+/, "");
            return `<img src="../${cleanPath}" alt="${name}" style="width:100%; height:100%; object-fit:cover;" onerror="this.remove();">`;
        }
        return `<span>${initials || 'U'}</span>`;
    }

    function updateFooterButtons() {
        if (isLocked) {
            if (canManage) {
                if (btnCancelSubmissionWf) btnCancelSubmissionWf.style.display = "inline-flex";
                if (btnEditReviewerWf) btnEditReviewerWf.style.display = "inline-flex";
                if (btnCancel) btnCancel.style.display = "none";
                if (btnConfirm) btnConfirm.style.display = "none";
            } else {
                if (btnCancelSubmissionWf) btnCancelSubmissionWf.style.display = "none";
                if (btnEditReviewerWf) btnEditReviewerWf.style.display = "none";
                if (btnCancel) {
                    btnCancel.style.display = "inline-flex";
                    btnCancel.textContent = "Close";
                }
                if (btnConfirm) btnConfirm.style.display = "none";
            }
        } else {
            if (btnCancelSubmissionWf) btnCancelSubmissionWf.style.display = "none";
            if (btnEditReviewerWf) btnEditReviewerWf.style.display = "none";
            if (btnCancel) {
                btnCancel.style.display = "inline-flex";
                btnCancel.textContent = "Cancel";
            }
            if (btnConfirm) {
                btnConfirm.style.display = "inline-flex";
                btnConfirm.innerHTML = `
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Submit</span>
                `;
            }
        }
    }

    function renderStages() {
        if (!stagesList) return;
        stagesList.innerHTML = "";

        if (btnAddStage) {
            btnAddStage.style.display = isLocked ? "none" : "flex";
        }

        stages.forEach((st, idx) => {
            const stageNum = idx + 1;
            const stageStatus = st.status || "waiting";
            const isStageApproved = (stageStatus === "approved");
            const isRemovable = !isLocked && !isStageApproved && (stages.length > 1);
            const node = document.createElement("div");
            node.dataset.index = idx;
            node.style.cssText = "position: relative; display: flex; flex-direction: column; align-items: center; width: 235px; flex-shrink: 0; z-index: 2;";

            // Render current stage assigned pills
            let assignedPillsHtml = "";
            const currentIds = st.reviewerIds || [];

            if (currentIds.length === 0) {
                assignedPillsHtml = '<div style="text-align: center; padding: 12px 4px; color: var(--muted); font-size: 12px; font-style: italic;">No reviewers yet</div>';
            } else {
                currentIds.forEach(uid => {
                    const r = reviewers.find(item => item.id === uid) || (st.reviewers && st.reviewers.find(item => item.id === uid));
                    if (!r) return;

                    let reviewerStatusBadge = "";
                    let rStat = "waiting";
                    if (st.reviewers) {
                        const rData = st.reviewers.find(item => item.id === uid);
                        rStat = rData ? rData.status : "waiting";
                        if (rStat === "approved") {
                            reviewerStatusBadge = `<span style="font-size: 11px; font-weight: 700; color: var(--green);" title="Approved">&#10003;</span>`;
                        } else if (rStat === "pending") {
                            reviewerStatusBadge = `<span style="font-size: 10.5px; font-weight: 600; color: var(--amber-ink); background: var(--amber-soft); padding: 1px 6px; border-radius: 4px;">Pending</span>`;
                        } else if (rStat === "rejected") {
                            reviewerStatusBadge = `<span style="font-size: 11px; font-weight: 700; color: var(--red);" title="Rejected">&times;</span>`;
                        }
                    }

                    const canRemovePill = !isLocked && !isStageApproved && (rStat !== "approved");
                    assignedPillsHtml += `
                        <div class="wf-reviewer-pill" data-uid="${r.id}" style="display: flex; align-items: center; justify-content: space-between; padding: 5px 8px; border-radius: 6px; background: var(--surface-light); font-size: 12.5px; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                <div style="width: 22px; height: 22px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; overflow: hidden;">
                                    ${getAvatarHtml(r.avatar, r.name, r.initials)}
                                </div>
                                <span style="font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: ${canRemovePill ? '155px' : '120px'};" title="${r.name}">${r.name}</span>
                            </div>
                            ${canRemovePill ? '<button type="button" class="wf-btn-pill-remove" title="Remove" style="border: none; background: transparent; color: var(--muted); cursor: pointer; padding: 0 2px; font-size: 15px; line-height: 1; font-weight: 700;">&times;</button>' : reviewerStatusBadge}
                        </div>
                    `;
                });
            }

            // Dot status color mapping: yellow = pending/current, green fill = approved, green hollow = waiting, red = rejected
            let dotBg = "var(--green)";
            let dotColor = "#fff";
            let dotBorder = "none";
            let dotBoxShadow = "0 0 0 3px var(--surface)";
            let dotContentHtml = `<span class="wf-dot-num">${stageNum}</span>`;
            let dotTitle = `Stage ${stageNum}`;

            if (isStageApproved) {
                dotBg = "var(--green)";
                dotColor = "#fff";
                dotTitle = `Stage ${stageNum} (Approved - Locked)`;
                dotContentHtml = `
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                `;
            } else if (isLocked) {
                if (stageStatus === "pending") {
                    dotBg = "var(--amber)";
                    dotColor = "#fff";
                    dotBoxShadow = "0 0 0 3px var(--amber-soft)";
                    dotTitle = `Stage ${stageNum} (Current Stage - In Review)`;
                    dotContentHtml = `<span class="wf-dot-num">${stageNum}</span>`;
                } else if (stageStatus === "rejected") {
                    dotBg = "var(--red)";
                    dotColor = "#fff";
                    dotTitle = `Stage ${stageNum} (Rejected)`;
                    dotContentHtml = `
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    `;
                } else {
                    // waiting / future stage (green border/hollow only)
                    dotBg = "var(--surface)";
                    dotColor = "var(--green)";
                    dotBorder = "2px solid var(--green)";
                    dotTitle = `Stage ${stageNum} (Waiting)`;
                    dotContentHtml = `<span class="wf-dot-num" style="color: var(--green);">${stageNum}</span>`;
                }
            } else if (isRemovable) {
                dotTitle = `Click to remove Stage ${stageNum}`;
            }

            let searchBoxHtml = "";
            if (isStageApproved) {
                searchBoxHtml = '<div style="text-align: center; font-size: 11px; font-weight: 600; color: var(--green); padding: 4px 0;">&#10003; Approved (Locked)</div>';
            } else if (!isLocked) {
                searchBoxHtml = `
                    <!-- Search Input Box (Pill Style, matching view.php standards) -->
                    <div style="position: relative; width: 100%;">
                        <div style="display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); border-radius: var(--radius-pill, 20px); padding: 6px 12px; background: var(--surface-light);">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="var(--muted)" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" class="wf-search-input" placeholder="Add reviewers..." autocomplete="off" style="border: none; background: transparent; outline: none; width: 100%; font-size: 12.5px; color: var(--ink);">
                        </div>
                        <div class="wf-search-dropdown" style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm, 6px); box-shadow: var(--shadow-dropdown, 0 4px 14px rgba(0,0,0,0.12)); max-height: 160px; overflow-y: auto; z-index: 50; display: none; flex-direction: column; padding: 4px; gap: 2px;"></div>
                    </div>
                `;
            }

            node.innerHTML = `
                <div class="wf-node-dot" 
                     title="${dotTitle}"
                     ${isRemovable ? 'role="button" tabindex="0"' : ''}
                     style="width: 30px; height: 30px; border-radius: 50%; box-sizing: border-box; border: ${dotBorder}; box-shadow: ${dotBoxShadow}; display: flex; align-items: center; justify-content: center; font-size: 13.5px; font-weight: 700; color: ${dotColor}; background: ${dotBg}; margin-bottom: 12px; cursor: ${isRemovable ? 'pointer' : 'default'};">
                    ${dotContentHtml}
                    ${isRemovable ? `
                    <span class="wf-dot-cross" style="display: none; align-items: center; justify-content: center;">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </span>` : ''}
                </div>
                <div style="width: 100%; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box;">
                    <div style="font-size: 13.5px; font-weight: 700; color: var(--ink); text-align: center;">Stage ${stageNum}</div>
                    ${searchBoxHtml}
                    <!-- Assigned Reviewers List -->
                    <div class="wf-assigned-list" style="display: flex; flex-direction: column; gap: 5px; max-height: 150px; overflow-y: auto;">
                        ${assignedPillsHtml}
                    </div>
                </div>
            `;

            // Bind Search & Suggestions if unlocked
            if (!isLocked) {
                const searchInput = node.querySelector(".wf-search-input");
                const dropdown = node.querySelector(".wf-search-dropdown");

                if (searchInput && dropdown) {
                    function updateDropdown() {
                        const q = searchInput.value.trim().toLowerCase();
                        const unselected = reviewers.filter(r => !(st.reviewerIds || []).includes(r.id));
                        const filtered = unselected.filter(r => {
                            if (!q) return true;
                            return r.name.toLowerCase().includes(q) || r.username.toLowerCase().includes(q) || r.role.toLowerCase().includes(q);
                        });

                        if (filtered.length === 0) {
                            dropdown.innerHTML = '<div style="font-size:11.5px; color:var(--muted); padding:8px 6px; text-align:center;">No matching people</div>';
                        } else {
                            dropdown.innerHTML = filtered.map(r => `
                                <div class="wf-user-opt-item" data-uid="${r.id}" style="display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 4px; cursor: pointer;" onmouseover="this.style.background='var(--surface-light)'" onmouseout="this.style.background='transparent'">
                                    <div class="wf-pill-avatar" style="width:24px; height:24px; font-size:10.5px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; overflow: hidden;">${getAvatarHtml(r.avatar, r.name, r.initials)}</div>
                                    <div style="min-width:0; flex:1;">
                                        <div style="font-size:12.5px; font-weight:600; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${r.name}</div>
                                        <div style="font-size:10.5px; color:var(--muted);">${r.role}</div>
                                    </div>
                                    <span style="font-size:13px; font-weight:700; color:var(--green);">+</span>
                                </div>
                            `).join("");

                            dropdown.querySelectorAll(".wf-user-opt-item").forEach(item => {
                                item.addEventListener("click", function(e) {
                                    e.stopPropagation();
                                    const uid = parseInt(this.dataset.uid, 10);
                                    if (!st.reviewerIds) st.reviewerIds = [];
                                    if (!st.reviewerIds.includes(uid)) {
                                        st.reviewerIds.push(uid);
                                    }
                                    renderStages();
                                });
                            });
                        }
                    }

                    searchInput.addEventListener("focus", function() {
                        updateDropdown();
                        dropdown.style.display = "flex";
                    });

                    searchInput.addEventListener("input", function() {
                        updateDropdown();
                        dropdown.style.display = "flex";
                    });
                }
            }

            // Bind Remove Pill if unlocked
            if (!isLocked) {
                node.querySelectorAll(".wf-btn-pill-remove").forEach(btn => {
                    btn.addEventListener("click", function() {
                        const pill = this.closest(".wf-reviewer-pill");
                        const uid = parseInt(pill.dataset.uid, 10);
                        st.reviewerIds = (st.reviewerIds || []).filter(id => id !== uid);
                        renderStages();
                    });
                });
            }

            // Bind click & hover removal to dot when stages > 1
            if (isRemovable) {
                const dotBtn = node.querySelector(".wf-node-dot");
                if (dotBtn) {
                    dotBtn.addEventListener("mouseenter", function() {
                        this.style.background = "var(--red)";
                        const numEl = this.querySelector(".wf-dot-num");
                        const crossEl = this.querySelector(".wf-dot-cross");
                        if (numEl) numEl.style.display = "none";
                        if (crossEl) crossEl.style.display = "flex";
                    });
                    dotBtn.addEventListener("mouseleave", function() {
                        this.style.background = "var(--green)";
                        const numEl = this.querySelector(".wf-dot-num");
                        const crossEl = this.querySelector(".wf-dot-cross");
                        if (numEl) numEl.style.display = "inline";
                        if (crossEl) crossEl.style.display = "none";
                    });
                    const handleRemove = function(e) {
                        e.stopPropagation();
                        if (stages.length > 1) {
                            stages.splice(idx, 1);
                            renderStages();
                        }
                    };
                    dotBtn.addEventListener("click", handleRemove);
                    dotBtn.addEventListener("keydown", function(e) {
                        if (e.key === "Enter" || e.key === " ") {
                            e.preventDefault();
                            handleRemove(e);
                        }
                    });
                }
            }

            stagesList.appendChild(node);
        });
    }

    // Convert vertical mouse wheel into horizontal timeline scrolling
    if (viewport) {
        viewport.addEventListener("wheel", function(e) {
            // Allow native vertical scroll if hovering inside a vertically scrollable child list
            const scrollableChild = e.target.closest(".wf-search-dropdown, .wf-assigned-list");
            if (scrollableChild && scrollableChild.scrollHeight > scrollableChild.clientHeight) {
                const atTop = scrollableChild.scrollTop <= 0 && e.deltaY < 0;
                const atBottom = (scrollableChild.scrollTop + scrollableChild.clientHeight >= scrollableChild.scrollHeight - 1) && e.deltaY > 0;
                if (!atTop && !atBottom) {
                    return;
                }
            }

            if (e.deltaY !== 0) {
                e.preventDefault();
                viewport.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }

    if (btnAddStage) {
        const handleAddStage = function() {
            stages.push({
                id: Date.now(),
                reviewerIds: []
            });
            renderStages();
            // Scroll viewport to the right
            if (viewport) {
                setTimeout(() => { viewport.scrollLeft = viewport.scrollWidth; }, 50);
            }
        };

        btnAddStage.addEventListener("click", handleAddStage);
        btnAddStage.addEventListener("keydown", function(e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                handleAddStage();
            }
        });
    }

    // Action listeners for Edit Reviewer and Cancel Submission
    if (btnEditReviewerWf) {
        btnEditReviewerWf.addEventListener("click", function() {
            isLocked = false;
            renderStages();
            if (btnCancelSubmissionWf) btnCancelSubmissionWf.style.display = "none";
            if (btnEditReviewerWf) btnEditReviewerWf.style.display = "none";
            if (btnCancel) {
                btnCancel.style.display = "inline-flex";
                btnCancel.textContent = "Cancel";
            }
            if (btnConfirm) {
                btnConfirm.style.display = "inline-flex";
                btnConfirm.innerHTML = `
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Save Changes</span>
                `;
            }
        });
    }

    if (btnCancelSubmissionWf) {
        btnCancelSubmissionWf.addEventListener("click", function() {
            if (typeof window.onTieredWorkflowCancelSubmission === "function") {
                window.onTieredWorkflowCancelSubmission();
            }
        });
    }

    // Modal openers / closers
    window.openTieredWorkflowModal = function(opts = null) {
        if (opts && typeof opts === "object" && !Array.isArray(opts)) {
            stages = (opts.stages && Array.isArray(opts.stages) && opts.stages.length > 0)
                ? JSON.parse(JSON.stringify(opts.stages))
                : [{ id: 1, reviewerIds: [] }];
            isLocked = !!opts.locked;
            canManage = (opts.canManage !== undefined) ? !!opts.canManage : true;
        } else if (Array.isArray(opts) && opts.length > 0) {
            stages = JSON.parse(JSON.stringify(opts));
            isLocked = false;
            canManage = true;
        } else {
            stages = [
                { id: 1, reviewerIds: [] }
            ];
            isLocked = false;
            canManage = true;
        }
        renderStages();
        updateFooterButtons();
        if (modal) modal.classList.add("is-open");
    };

    window.closeTieredWorkflowModal = function() {
        if (modal) modal.classList.remove("is-open");
    };

    if (btnClose) btnClose.addEventListener("click", window.closeTieredWorkflowModal);
    if (btnCancel) btnCancel.addEventListener("click", window.closeTieredWorkflowModal);
    if (modal) {
        modal.addEventListener("click", (e) => {
            if (e.target === modal) window.closeTieredWorkflowModal();
        });
    }

    document.addEventListener("click", function(e) {
        if (!stagesList) return;
        stagesList.querySelectorAll(".wf-stage-node").forEach(stageNode => {
            if (!stageNode.contains(e.target)) {
                const dd = stageNode.querySelector(".wf-search-dropdown");
                if (dd) dd.style.display = "none";
            }
        });
    });

    if (form) {
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            const unassigned = stages.findIndex(s => !s.reviewerIds || s.reviewerIds.length === 0);
            if (unassigned !== -1) {
                alert(`Please assign at least one reviewer for Stage ${unassigned + 1}.`);
                return;
            }

            if (typeof window.onTieredWorkflowConfirmed === "function") {
                window.onTieredWorkflowConfirmed(stages);
            } else {
                window.closeTieredWorkflowModal();
            }
        });
    }

    // Initial render
    renderStages();
    updateFooterButtons();
})();
</script>
