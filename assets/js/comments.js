// ============================================================
// EDMS Document Page Marginalia Comments Engine
// ============================================================

(function () {
    "use strict";

    let commentsCache = {};
    let fetchPromise = null;

    // --- Configuration Getters ---
    const getConfig = () => window.EDMS_WORKSPACE_CONFIG || window.EDMS_DOC || {};
    const getDocId = () => parseInt(getConfig().docId || getConfig().id || 0, 10);
    const getVersionId = () => getConfig().activeVersionId || null;
    const canAnnotate = () => !!getConfig().canAnnotate;
    const canResolve = (c) => !!(getConfig().canManageAccess || getConfig().canActOnReview || c.is_self);
    const getBaseUrl = () => (getConfig().baseUrl || window.BASE_URL || '..').replace(/\/+$/, '');

    // --- Reusable API Fetch Helper ---
    async function apiPost(action, payload = {}) {
        const docId = getDocId();
        if (!docId) return null;
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
            const res = await fetch("../actions/comments-handler.php", {
                method: "POST",
                headers: { 
                    "Content-Type": "application/json",
                    "X-CSRF-Token": token
                },
                body: JSON.stringify({ action, doc_id: docId, version_id: getVersionId(), csrf_token: token, ...payload })
            });
            return await res.json();
        } catch (e) {
            console.error(`Comment API [${action}] failed:`, e);
            return null;
        }
    }

    // --- 1. Fetch & Initialize Comments ---
    window.initDocumentComments = async function () {
        const docId = getDocId();
        if (!docId) return;
        await fetchComments();
        renderAllGutters();
    };

    async function fetchComments() {
        const docId = getDocId();
        const verId = getVersionId();
        if (!docId) return;
        if (fetchPromise) return fetchPromise;

        fetchPromise = (async () => {
            try {
                const url = `../actions/comments-handler.php?action=get_comments&doc_id=${docId}${verId ? '&version_id=' + verId : ''}`;
                const res = await fetch(url);
                const data = await res.json();
                if (data.success) commentsCache = data.comments_by_page || {};
            } catch (e) {
                console.error("Failed to load document comments:", e);
            } finally {
                fetchPromise = null;
            }
        })();

        return fetchPromise;
    }

    // --- 2. Attach Gutter to Page Container ---
    window.renderPageGutter = function (pageNum, pageWrapper) {
        if (!pageWrapper) return;

        let row = pageWrapper.closest(".pdf-page-row");
        if (!row) {
            row = document.createElement("div");
            row.className = "pdf-page-row";
            row.id = `pageRow_${pageNum}`;
            pageWrapper.parentNode.insertBefore(row, pageWrapper);
            row.appendChild(pageWrapper);
        }

        let gutter = row.querySelector(".pdf-page-gutter");
        if (!gutter) {
            gutter = document.createElement("aside");
            gutter.className = "pdf-page-gutter";
            gutter.id = `pageGutter_${pageNum}`;
            gutter.dataset.page = pageNum;
            row.appendChild(gutter);
        }

        renderGutterContents(pageNum, gutter);
    };

    function renderAllGutters() {
        document.querySelectorAll(".pdf-page-wrapper, section.docx").forEach(el => {
            const pageNum = parseInt(el.dataset.page, 10);
            if (pageNum) window.renderPageGutter(pageNum, el);
        });
    }

    // --- 3. Render Gutter Contents ---
    function renderGutterContents(pageNum, gutter) {
        const threads = commentsCache[pageNum] || [];
        const isAnnotatable = canAnnotate();

        // Preserve input state if user already had it open with text
        const existingInput = gutter.querySelector(`#commentInput_${pageNum}`);
        const savedDraft = existingInput ? existingInput.value : "";
        const wasOpen = existingInput && existingInput.closest(".comment-input-box")?.style.display !== "none";

        let html = '';

        // Launcher Button & Collapsible Input Box
        if (isAnnotatable) {
            const commentSvg = getConfig().icons?.comment || '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21C16.97 21 21 16.97 21 12C21 7.03 16.97 3 12 3C7.03 3 3 7.03 3 12c0 1.49.36 2.89 1 4.13L3 21l4.87-1C9.11 20.64 10.51 21 12 21Z"/><line x1="12" y1="9" x2="12" y2="15"/><line x1="9" y1="12" x2="15" y2="12"/></svg>';
            html += `
                <div class="comment-launcher-wrap">
                    <button type="button" class="btn-comment-launcher" id="commentLauncher_${pageNum}" onclick="window.showCommentInput(${pageNum})" title="Add note on page ${pageNum}" ${wasOpen ? 'style="display:none;"' : ''}>
                        ${commentSvg}
                    </button>
                    <div class="comment-input-box" id="commentAddBox_${pageNum}" ${wasOpen ? '' : 'style="display:none;"'}>
                        <textarea class="form-input comment-input" id="commentInput_${pageNum}" placeholder="Add note on page ${pageNum}..." rows="2"></textarea>
                        <div style="display:flex; justify-content:flex-end; gap:6px;">
                            <button type="button" class="btn btn-sm btn-secondary" style="padding: 3px 8px; font-size: 11px;" onclick="window.hideCommentInput(${pageNum})">Cancel</button>
                            <button type="button" class="btn btn-sm btn-primary" style="padding: 3px 8px; font-size: 11px;" onclick="window.postComment(${pageNum})">Comment</button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Thread List
        html += `<div class="comment-threads-list" id="commentList_${pageNum}">`;
        threads.forEach(c => { html += renderCommentCardHtml(c); });
        html += `</div>`;

        gutter.innerHTML = html;

        if (savedDraft) {
            const restoredInput = gutter.querySelector(`#commentInput_${pageNum}`);
            if (restoredInput) restoredInput.value = savedDraft;
        }
    }

    // --- 4. Reusable Avatar Generator ---
    function getAvatarHtml(avatarPath, initials, size = 26) {
        if (avatarPath) {
            const src = `${getBaseUrl()}/${avatarPath.replace(/^\/+/, '')}`;
            return `<span class="user-avatar-sm" style="width:${size}px;height:${size}px;font-size:${size < 24 ? 10 : 12}px;"><img src="${escapeHtml(src)}" alt="" onerror="this.onerror=null;this.parentElement.textContent='${escapeHtml(initials || 'U')}';"></span>`;
        }
        return `<span class="user-avatar-sm" style="width:${size}px;height:${size}px;font-size:${size < 24 ? 10 : 12}px;">${escapeHtml(initials || 'U')}</span>`;
    }

    // --- 5. Render Comment Card & Nested Replies ---
    function renderCommentCardHtml(c) {
        let repliesHtml = '';
        if (c.replies && c.replies.length > 0) {
            c.replies.forEach(r => {
                repliesHtml += `
                    <div class="comment-reply-item">
                        <div class="comment-header">
                            ${getAvatarHtml(r.avatar, r.initials, 20)}
                            <div class="comment-meta">
                                <span class="comment-username">${escapeHtml(r.author_name)}</span>
                                <span class="comment-role">${escapeHtml(r.role)} &bull; ${escapeHtml(r.created_at)}</span>
                            </div>
                        </div>
                        <div class="comment-body">${escapeHtml(r.content)}</div>
                        ${r.is_self ? `<div class="comment-actions" style="margin-top:2px;"><button type="button" class="btn-comment-action" style="color:var(--red);" onclick="window.deletePageComment(${r.id}, ${c.page_number})">Delete</button></div>` : ''}
                    </div>
                `;
            });
        }

        const canRes = canResolve(c);

        return `
            <div class="comment-card ${c.is_resolved ? 'is-resolved' : ''}" id="commentCard_${c.id}">
                <div class="comment-header">
                    ${getAvatarHtml(c.avatar, c.initials, 26)}
                    <div class="comment-meta">
                        <span class="comment-username">${escapeHtml(c.author_name)}</span>
                        <span class="comment-role">${escapeHtml(c.role)} &bull; ${escapeHtml(c.created_at)}</span>
                    </div>
                    ${c.is_resolved ? '<span class="comment-badge-resolved">Resolved</span>' : ''}
                </div>
                <div class="comment-body">${escapeHtml(c.content)}</div>
                <div class="comment-actions">
                    ${canAnnotate() ? `<button type="button" class="btn-comment-action" onclick="window.toggleReplyInput(${c.id})">Reply</button>` : ''}
                    ${canRes ? `<button type="button" class="btn-comment-action" onclick="window.toggleResolveComment(${c.id})">${c.is_resolved ? 'Unresolve' : 'Resolve'}</button>` : ''}
                    ${c.is_self ? `<button type="button" class="btn-comment-action" style="color:var(--red);" onclick="window.deletePageComment(${c.id}, ${c.page_number})">Delete</button>` : ''}
                </div>
                <div class="comment-replies" id="commentReplies_${c.id}" ${(!c.replies || c.replies.length === 0) ? 'style="display:none;"' : ''}>
                    ${repliesHtml}
                </div>
                <div class="comment-reply-box" id="replyBox_${c.id}" style="display:none; margin-top:6px;">
                    <textarea class="form-input comment-input" id="replyInput_${c.id}" placeholder="Write a reply..." rows="2"></textarea>
                    <div style="display:flex; justify-content:flex-end; gap:6px; margin-top:4px;">
                        <button type="button" class="btn btn-sm btn-secondary" style="padding: 3px 8px; font-size: 11px;" onclick="window.toggleReplyInput(${c.id})">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" style="padding: 3px 8px; font-size: 11px;" onclick="window.postComment(${c.page_number}, ${c.id})">Reply</button>
                    </div>
                </div>
            </div>
        `;
    }

    // --- 6. Unified Comment / Reply Submission (Reused Code) ---
    window.postComment = async function (pageNum, parentId = null) {
        const inputId = parentId ? `replyInput_${parentId}` : `commentInput_${pageNum}`;
        const input = document.getElementById(inputId);
        if (!input) return;
        const text = input.value.trim();
        if (!text) return;

        const data = await apiPost("add_comment", {
            page_number: pageNum,
            parent_id: parentId,
            content: text
        });

        if (data && data.success && data.comment) {
            commentsCache[pageNum] = commentsCache[pageNum] || [];
            if (parentId) {
                const thread = commentsCache[pageNum].find(c => c.id === parentId);
                if (thread) {
                    thread.replies = thread.replies || [];
                    thread.replies.push(data.comment);
                }
            } else {
                commentsCache[pageNum].push(data.comment);
            }
            input.value = "";
            const gutter = document.getElementById(`pageGutter_${pageNum}`);
            if (gutter) renderGutterContents(pageNum, gutter);
        }
    };

    // --- 7. Toggle Resolve Status ---
    window.toggleResolveComment = async function (commentId) {
        const data = await apiPost("resolve_comment", { comment_id: commentId });
        if (data && data.success) {
            for (let p in commentsCache) {
                const thread = commentsCache[p].find(c => c.id === commentId);
                if (thread) {
                    thread.is_resolved = data.is_resolved;
                    const gutter = document.getElementById(`pageGutter_${p}`);
                    if (gutter) renderGutterContents(p, gutter);
                    break;
                }
            }
        }
    };

    // --- 8. Silent Deletion (Strictly Author Only, No Confirm Alerts) ---
    window.deletePageComment = async function (commentId, pageNum) {
        const data = await apiPost("delete_comment", { comment_id: commentId });
        if (data && data.success) {
            if (commentsCache[pageNum]) {
                commentsCache[pageNum] = commentsCache[pageNum].filter(c => c.id !== commentId);
                commentsCache[pageNum].forEach(c => {
                    if (c.replies) c.replies = c.replies.filter(r => r.id !== commentId);
                });
                const gutter = document.getElementById(`pageGutter_${pageNum}`);
                if (gutter) renderGutterContents(pageNum, gutter);
            }
        }
    };

    // --- 9. UI Helpers (Launcher Toggle & Reply Collapse) ---
    window.showCommentInput = function (pageNum) {
        const launcher = document.getElementById(`commentLauncher_${pageNum}`);
        const box = document.getElementById(`commentAddBox_${pageNum}`);
        if (launcher) launcher.style.display = "none";
        if (box) {
            box.style.display = "flex";
            const input = document.getElementById(`commentInput_${pageNum}`);
            if (input) input.focus();
        }
    };

    window.hideCommentInput = function (pageNum) {
        const launcher = document.getElementById(`commentLauncher_${pageNum}`);
        const box = document.getElementById(`commentAddBox_${pageNum}`);
        if (box) box.style.display = "none";
        if (launcher) launcher.style.display = "inline-flex";
    };

    window.toggleReplyInput = function (commentId) {
        const box = document.getElementById(`replyBox_${commentId}`);
        if (!box) return;
        box.style.display = box.style.display === "none" ? "block" : "none";
        if (box.style.display === "block") {
            const input = document.getElementById(`replyInput_${commentId}`);
            if (input) input.focus();
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
    }

    // Prefetch early
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => { if (getDocId()) fetchComments(); });
    } else {
        if (getDocId()) fetchComments();
    }

})();
