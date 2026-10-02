// ============================================================
// EDMS CORE SYSTEM: Sidebar & UI Controls
// ============================================================

(function () {
    "use strict";

    // Toggle nav groups in sidebar
    window.toggleNavGroup = function (btn) {
        btn.classList.toggle("open");
        const group = btn.nextElementSibling;
        if (group) group.classList.toggle("open");
        try {
            const open = [];
            document.querySelectorAll(".nav-group-toggle[data-group]").forEach((t) => {
                if (t.classList.contains("open")) open.push(t.dataset.group);
            });
            localStorage.setItem("edms-sidebar-open-groups", JSON.stringify(open));
        } catch (_) {}
    };

    // Toggle sidebar collapse
    window.toggleSidebar = function () {
        document.body.classList.toggle("sidebar-collapsed");
        try {
            localStorage.setItem("edms-sidebar-collapsed", document.body.classList.contains("sidebar-collapsed") ? "1" : "0");
        } catch (_) {}
    };

    // Restore sidebar state from localStorage
    (function () {
        let savedCollapsed = null;
        let savedGroups = null;
        try {
            savedCollapsed = localStorage.getItem("edms-sidebar-collapsed");
            savedGroups = JSON.parse(localStorage.getItem("edms-sidebar-open-groups"));
        } catch (_) {}

        if (savedCollapsed === "1") {
            document.body.classList.add("sidebar-collapsed");
        }

        const activeItem = document.querySelector(".nav-item.active");
        const activeGroup = activeItem ? activeItem.closest(".nav-group") : null;
        const activeToggle = activeGroup ? activeGroup.previousElementSibling : null;
        const activeKey = activeToggle ? activeToggle.dataset.group : null;

        document.querySelectorAll(".nav-group-toggle[data-group]").forEach((btn) => {
            const key = btn.dataset.group;
            const group = btn.nextElementSibling;
            const open = (savedGroups && savedGroups.includes(key)) || key === activeKey;
            btn.classList.toggle("open", open);
            if (group) group.classList.toggle("open", open);
        });
    })();

    // Live Sidebar Server Clock Ticker
    function initSidebarClock() {
        const clockEl = document.getElementById("sidebarServerClock");
        if (!clockEl) return;
        const initialTs = parseInt(clockEl.dataset.serverTs, 10);
        if (!initialTs) return;

        let currentMs = initialTs * 1000;
        const pad = (n) => String(n).padStart(2, "0");

        function tick() {
            currentMs += 1000;
            const d = new Date(currentMs);
            clockEl.textContent = pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds());
        }

        setInterval(tick, 1000);
    }
    initSidebarClock();

    // ============================================================
    // Real-Time AJAX Explorer (Search, Dropdown Filters, Reset, Tabs)
    // ============================================================
    function initExplorerAjax() {
        const cardListContainer = document.getElementById("explorerCardList");
        if (!cardListContainer) return; // Not on Explorer page

        const searchInput = document.getElementById("explorerSearchInput");
        const clearBtn = document.getElementById("explorerSearchClear");
        const searchForm = document.getElementById("explorerSearchForm");
        const filterType = document.getElementById("filterTypeSelect");
        const filterStatus = document.getElementById("filterStatusSelect");
        const resetBtn = document.getElementById("explorerResetBtn");
        const modeInput = document.getElementById("explorerFilterMode");
        const folderInput = document.getElementById("explorerFilterFolder");
        const pillTabs = document.querySelectorAll(".pill-tab[data-mode]");
        const btnNewFolder = document.getElementById("btnNewFolder");

        let debounceTimer = null;
        let abortController = null;
        let currentPage = 1;
        let currentPerPage = 25;

        // Check if page/per_page are preset in URL
        const initialParams = new URLSearchParams(window.location.search);
        if (initialParams.has("page")) {
            currentPage = parseInt(initialParams.get("page"), 10) || 1;
        }
        if (initialParams.has("per_page")) {
            currentPerPage = parseInt(initialParams.get("per_page"), 10) || 25;
        }

        // Collect current state and perform AJAX fetch
        function performAjaxUpdate(targetPage = null) {
            if (abortController) {
                abortController.abort(); // Cancel any prior pending request
            }
            abortController = new AbortController();

            if (targetPage !== null) {
                currentPage = targetPage;
            }

            const currentMode = modeInput ? modeInput.value : "project";
            const currentFolder = folderInput ? folderInput.value : "";
            const currentQ = searchInput ? searchInput.value.trim() : "";
            const currentType = filterType ? filterType.value : "";
            const currentStatus = filterStatus ? filterStatus.value : "";

            // Build query params
            const params = new URLSearchParams();
            if (currentMode) params.set("mode", currentMode);
            if (currentFolder && currentMode === "project") params.set("folder", currentFolder);
            if (currentQ) params.set("q", currentQ);
            if (currentType) params.set("type", currentType);
            if (currentStatus) params.set("status", currentStatus);
            if (currentPage > 1) params.set("page", currentPage.toString());
            if (currentPerPage !== 25) params.set("per_page", currentPerPage.toString());

            // Update UI state
            if (resetBtn) {
                resetBtn.style.display = (currentType || currentStatus) ? "inline-flex" : "none";
            }
            if (clearBtn && searchInput) {
                clearBtn.style.display = currentQ.length > 0 ? "block" : "none";
            }
            if (btnNewFolder) {
                btnNewFolder.style.display = currentMode === "project" ? "" : "none";
            }

            // Update pill tabs hrefs for graceful fallback/new tab
            pillTabs.forEach(tab => {
                const tabMode = tab.dataset.mode;
                const tabParams = new URLSearchParams();
                tabParams.set("mode", tabMode);
                if (currentType) tabParams.set("type", currentType);
                if (currentStatus) tabParams.set("status", currentStatus);
                tab.href = "?" + tabParams.toString();
                tab.classList.toggle("active", tabMode === currentMode);
            });

            // Smooth loading indicator opacity
            cardListContainer.style.opacity = "0.6";
            cardListContainer.style.pointerEvents = "none";

            const fetchUrl = "explorer.php?" + params.toString() + "&ajax=1";

            fetch(fetchUrl, {
                signal: abortController.signal,
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
            .then(res => {
                if (!res.ok) throw new Error("Explorer query failed");
                return res.text();
            })
            .then(html => {
                cardListContainer.innerHTML = html;
                cardListContainer.style.opacity = "1";
                cardListContainer.style.pointerEvents = "";
                // Silently update browser address bar without reload
                window.history.replaceState({}, "", "?" + params.toString());
            })
            .catch(err => {
                if (err.name !== "AbortError") {
                    console.error("Explorer AJAX error:", err);
                    cardListContainer.style.opacity = "1";
                    cardListContainer.style.pointerEvents = "";
                }
            });
        }

        // 1. Search Input Debouncing (Resets to page 1)
        if (searchInput) {
            searchInput.addEventListener("input", function () {
                if (clearBtn) {
                    clearBtn.style.display = searchInput.value.trim().length > 0 ? "block" : "none";
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    performAjaxUpdate(1);
                }, 250);
            });
        }

        // 2. Clear Search Button (Resets to page 1)
        if (clearBtn) {
            clearBtn.addEventListener("click", function (e) {
                e.preventDefault();
                searchInput.value = "";
                clearBtn.style.display = "none";
                searchInput.focus();
                performAjaxUpdate(1);
            });
        }

        // 3. Search Form Submit Prevent
        if (searchForm) {
            searchForm.addEventListener("submit", function (e) {
                e.preventDefault();
                performAjaxUpdate(1);
            });
        }

        // 4. Dropdown Filters Change Handlers (Instant AJAX, Resets to page 1)
        if (filterType) {
            filterType.addEventListener("change", () => performAjaxUpdate(1));
        }
        if (filterStatus) {
            filterStatus.addEventListener("change", () => performAjaxUpdate(1));
        }

        // 5. Reset Filters Button Handler (Resets to page 1)
        if (resetBtn) {
            resetBtn.addEventListener("click", function (e) {
                e.preventDefault();
                if (filterType) filterType.value = "";
                if (filterStatus) filterStatus.value = "";
                performAjaxUpdate(1);
            });
        }

        // 6. Mode Switcher Pill Tabs (Instant AJAX, Resets to page 1)
        pillTabs.forEach(tab => {
            tab.addEventListener("click", function (e) {
                e.preventDefault();
                const selectedMode = tab.dataset.mode;
                if (modeInput) modeInput.value = selectedMode;
                // If switching mode, reset folder parameter
                if (folderInput) folderInput.value = "";
                performAjaxUpdate(1);
            });
        });

        // 7. Delegated Pagination Controls Click & Change Handlers
        cardListContainer.addEventListener("click", function (e) {
            const pageBtn = e.target.closest(".pagination-btn, .pagination-page-btn");
            if (pageBtn && !pageBtn.disabled && !pageBtn.classList.contains("is-disabled") && !pageBtn.classList.contains("is-active")) {
                e.preventDefault();
                const targetPage = parseInt(pageBtn.dataset.page, 10);
                if (targetPage > 0) {
                    performAjaxUpdate(targetPage);
                }
            }
        });

        cardListContainer.addEventListener("change", function (e) {
            const perPageSelect = e.target.closest("#paginationPerPageSelect");
            if (perPageSelect) {
                currentPerPage = parseInt(perPageSelect.value, 10) || 25;
                performAjaxUpdate(1); // Return to page 1 with new page size
            }
        });

        // Helper for generic modal open/close
        function openModal(modalEl, focusInputEl) {
            if (!modalEl) return;
            modalEl.style.display = "flex";
            requestAnimationFrame(() => {
                modalEl.classList.add("is-open");
                if (focusInputEl) focusInputEl.focus();
            });
        }

        function closeModal(modalEl) {
            if (!modalEl) return;
            modalEl.classList.remove("is-open");
            setTimeout(() => {
                modalEl.style.display = "none";
            }, 200);
        }

        // 7. New Folder Modal Controls
        const modalNewFolder = document.getElementById("modalNewFolder");
        const formNewFolder = document.getElementById("formNewFolder");
        const inputFolderName = document.getElementById("newFolderName");
        const inputNewFolderParentId = document.getElementById("newFolderParentId");
        const btnCloseNewFolder = document.getElementById("btnCloseNewFolderModal");
        const btnCancelNewFolder = document.getElementById("btnCancelNewFolder");
        const btnSubmitFolder = document.getElementById("btnSubmitNewFolder");
        const helpFolderContext = document.getElementById("newFolderContextHelp");

        function openNewFolderModal() {
            if (!modalNewFolder) return;
            const currentFolder = folderInput ? folderInput.value : "";
            if (inputNewFolderParentId) {
                inputNewFolderParentId.value = currentFolder;
            }
            if (helpFolderContext) {
                const activeCrumb = document.querySelector(".breadcrumb-item.current");
                if (currentFolder && activeCrumb) {
                    helpFolderContext.innerHTML = "Target location: Inside <strong>" + activeCrumb.textContent.trim() + "</strong>";
                } else if (!currentFolder) {
                    helpFolderContext.innerHTML = "Target location: <strong>Root Level</strong>";
                }
            }
            if (inputFolderName) inputFolderName.value = "";
            openModal(modalNewFolder, inputFolderName);
        }

        if (btnNewFolder) btnNewFolder.addEventListener("click", openNewFolderModal);
        if (btnCloseNewFolder) btnCloseNewFolder.addEventListener("click", () => closeModal(modalNewFolder));
        if (btnCancelNewFolder) btnCancelNewFolder.addEventListener("click", () => closeModal(modalNewFolder));
        if (modalNewFolder) {
            modalNewFolder.addEventListener("click", (e) => {
                if (e.target === modalNewFolder) closeModal(modalNewFolder);
            });
        }

        if (formNewFolder) {
            formNewFolder.addEventListener("submit", function (e) {
                e.preventDefault();
                const folderName = inputFolderName ? inputFolderName.value.trim() : "";
                if (!folderName) return;

                if (btnSubmitFolder) {
                    btnSubmitFolder.disabled = true;
                    btnSubmitFolder.style.opacity = "0.7";
                }

                const formData = new FormData(formNewFolder);

                fetch("explorer.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (btnSubmitFolder) {
                        btnSubmitFolder.disabled = false;
                        btnSubmitFolder.style.opacity = "1";
                    }
                    if (data.success) {
                        closeModal(modalNewFolder);
                        performAjaxUpdate();
                    } else {
                        alert(data.message || "Failed to create folder.");
                    }
                })
                .catch(err => {
                    if (btnSubmitFolder) {
                        btnSubmitFolder.disabled = false;
                        btnSubmitFolder.style.opacity = "1";
                    }
                    console.error("Create folder error:", err);
                    alert("An error occurred while creating folder.");
                });
            });
        }

        // ============================================================
        // 8. Action Popover Menu & Item Operations (Rename, Move, Delete)
        // ============================================================
        const popover = document.getElementById("edmsActionPopover");
        const popoverRename = document.getElementById("popoverActionRename");
        const popoverMove = document.getElementById("popoverActionMove");
        const popoverDelete = document.getElementById("popoverActionDelete");

        // Modals & Forms
        const modalRename = document.getElementById("modalRenameNode");
        const formRename = document.getElementById("formRenameNode");
        const inputRenameId = document.getElementById("renameNodeId");
        const inputRenameName = document.getElementById("renameNodeName");
        const btnCloseRename = document.getElementById("btnCloseRenameModal");
        const btnCancelRename = document.getElementById("btnCancelRename");

        const modalMove = document.getElementById("modalMoveNode");
        const formMove = document.getElementById("formMoveNode");
        const inputMoveId = document.getElementById("moveNodeId");
        const inputMoveTargetFolderId = document.getElementById("moveTargetFolderId");
        const displayMoveName = document.getElementById("moveItemNameDisplay");
        const driveMoveBreadcrumbs = document.getElementById("driveMoveBreadcrumbs");
        const driveMoveFolderList = document.getElementById("driveMoveFolderList");
        const driveMoveSelectedName = document.getElementById("driveMoveSelectedName");
        const btnCloseMove = document.getElementById("btnCloseMoveModal");
        const btnCancelMove = document.getElementById("btnCancelMove");

        const modalDelete = document.getElementById("modalDeleteNode");
        const formDelete = document.getElementById("formDeleteNode");
        const inputDeleteId = document.getElementById("deleteNodeId");
        const displayDeleteName = document.getElementById("deleteItemNameDisplay");
        const btnCloseDelete = document.getElementById("btnCloseDeleteModal");
        const btnCancelDelete = document.getElementById("btnCancelDelete");

        // Drive Move State
        let moveTreeData = [];
        let moveCurrentViewingFolderId = null; // null = root
        let moveSelectedDestinationFolderId = null; // null = root

        let currentActiveItem = null;

        function hidePopover() {
            if (popover) popover.style.display = "none";
        }

        // Delegated 3-Dots Button Click Handler
        cardListContainer.addEventListener("click", function (e) {
            const btn = e.target.closest(".btn-more-actions");
            if (!btn || !popover) return;
            e.preventDefault();
            e.stopPropagation();

            const nodeId = btn.dataset.id;
            const nodeName = btn.dataset.name;
            const nodeType = btn.dataset.type || "document";

            currentActiveItem = { id: nodeId, name: nodeName, type: nodeType };

            // Position popover
            const rect = btn.getBoundingClientRect();
            popover.style.display = "flex";
            const popoverWidth = 160;
            let left = rect.right - popoverWidth;
            if (left < 10) left = 10;
            let top = rect.bottom + 4;
            if (top + 130 > window.innerHeight) {
                top = rect.top - 130;
            }

            popover.style.left = left + "px";
            popover.style.top = top + "px";
        });

        // Close popover when clicking anywhere else
        document.addEventListener("click", function (e) {
            if (popover && !popover.contains(e.target)) {
                hidePopover();
            }
        });

        // Close popover / modals on Escape key
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                hidePopover();
                if (modalNewFolder && modalNewFolder.classList.contains("is-open")) closeModal(modalNewFolder);
                if (modalRename && modalRename.classList.contains("is-open")) closeModal(modalRename);
                if (modalMove && modalMove.classList.contains("is-open")) closeModal(modalMove);
                if (modalDelete && modalDelete.classList.contains("is-open")) closeModal(modalDelete);
            }
        });

        // --- Action: Rename ---
        if (popoverRename) {
            popoverRename.addEventListener("click", function () {
                hidePopover();
                if (!currentActiveItem) return;
                if (inputRenameId) inputRenameId.value = currentActiveItem.id;
                if (inputRenameName) inputRenameName.value = currentActiveItem.name;
                openModal(modalRename, inputRenameName);
            });
        }

        if (btnCloseRename) btnCloseRename.addEventListener("click", () => closeModal(modalRename));
        if (btnCancelRename) btnCancelRename.addEventListener("click", () => closeModal(modalRename));
        if (modalRename) {
            modalRename.addEventListener("click", (e) => {
                if (e.target === modalRename) closeModal(modalRename);
            });
        }

        if (formRename) {
            formRename.addEventListener("submit", function (e) {
                e.preventDefault();
                const newName = inputRenameName ? inputRenameName.value.trim() : "";
                if (!newName) return;

                const formData = new FormData(formRename);
                fetch("explorer.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        closeModal(modalRename);
                        performAjaxUpdate();
                    } else {
                        alert(data.message || "Failed to rename item.");
                    }
                })
                .catch(err => {
                    console.error("Rename error:", err);
                    alert("An error occurred while renaming.");
                });
            });
        }

        const chevronRightIconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
        const folderIconSvg = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';

        function renderDriveMoveExplorer() {
            if (!driveMoveFolderList || !driveMoveBreadcrumbs) return;

            // 1. Render Breadcrumbs
            driveMoveBreadcrumbs.innerHTML = "";
            const breadcrumbItems = [];

            // Root Crumb
            const rootCrumb = document.createElement("button");
            rootCrumb.type = "button";
            rootCrumb.className = "drive-crumb-btn" + (moveCurrentViewingFolderId === null ? " is-current" : "");
            rootCrumb.innerHTML = "📁 Root";
            rootCrumb.addEventListener("click", () => {
                if (moveCurrentViewingFolderId !== null) {
                    moveCurrentViewingFolderId = null;
                    moveSelectedDestinationFolderId = null;
                    if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = "";
                    if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 Root (Top Level)";
                    renderDriveMoveExplorer();
                }
            });
            breadcrumbItems.push(rootCrumb);

            // Calculate path to current viewing folder
            if (moveCurrentViewingFolderId !== null) {
                const pathFolders = [];
                let currId = moveCurrentViewingFolderId;
                while (currId !== null) {
                    const f = moveTreeData.find(item => item.id === currId);
                    if (!f) break;
                    pathFolders.unshift(f);
                    currId = f.parent_id;
                }

                pathFolders.forEach((f, idx) => {
                    const sep = document.createElement("span");
                    sep.className = "drive-crumb-sep";
                    sep.textContent = "/";
                    breadcrumbItems.push(sep);

                    const isLast = idx === pathFolders.length - 1;
                    const crumb = document.createElement("button");
                    crumb.type = "button";
                    crumb.className = "drive-crumb-btn" + (isLast ? " is-current" : "");
                    crumb.textContent = f.name;
                    if (!isLast) {
                        crumb.addEventListener("click", () => {
                            moveCurrentViewingFolderId = f.id;
                            moveSelectedDestinationFolderId = f.id;
                            if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = f.id;
                            if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 " + f.name;
                            renderDriveMoveExplorer();
                        });
                    }
                    breadcrumbItems.push(crumb);
                });
            }

            breadcrumbItems.forEach(el => driveMoveBreadcrumbs.appendChild(el));

            // 2. Render Folder List for current directory
            driveMoveFolderList.innerHTML = "";
            const directChildren = moveTreeData.filter(f => f.parent_id === moveCurrentViewingFolderId);

            if (directChildren.length === 0) {
                const emptyMsg = document.createElement("div");
                emptyMsg.className = "drive-move-empty";
                emptyMsg.innerHTML = "<em>No subfolders here. Click 'Move Here' to place the item into this location.</em>";
                driveMoveFolderList.appendChild(emptyMsg);
                return;
            }

            directChildren.forEach(folder => {
                const row = document.createElement("div");
                row.className = "drive-folder-row" + 
                    (folder.disabled ? " is-disabled" : "") + 
                    (moveSelectedDestinationFolderId === folder.id ? " is-selected" : "");

                const left = document.createElement("div");
                left.className = "drive-folder-left";
                left.innerHTML = `<span class="drive-folder-icon">${folderIconSvg}</span><span class="drive-folder-name" title="${folder.name}">${folder.name}</span>`;

                row.appendChild(left);

                // Check if this folder has subfolders
                const hasSubfolders = moveTreeData.some(f => f.parent_id === folder.id && !f.disabled);
                if (!folder.disabled && hasSubfolders) {
                    const navBtn = document.createElement("button");
                    navBtn.type = "button";
                    navBtn.className = "drive-folder-nav-btn";
                    navBtn.title = "Open subfolder";
                    navBtn.innerHTML = chevronRightIconSvg;
                    navBtn.addEventListener("click", (e) => {
                        e.stopPropagation();
                        moveCurrentViewingFolderId = folder.id;
                        moveSelectedDestinationFolderId = folder.id;
                        if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = folder.id;
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 " + folder.name;
                        renderDriveMoveExplorer();
                    });
                    row.appendChild(navBtn);
                }

                if (!folder.disabled) {
                    row.addEventListener("click", () => {
                        moveSelectedDestinationFolderId = folder.id;
                        if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = folder.id;
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 " + folder.name;
                        renderDriveMoveExplorer();
                    });

                    // Double click to enter folder
                    row.addEventListener("dblclick", () => {
                        moveCurrentViewingFolderId = folder.id;
                        moveSelectedDestinationFolderId = folder.id;
                        if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = folder.id;
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 " + folder.name;
                        renderDriveMoveExplorer();
                    });
                }

                driveMoveFolderList.appendChild(row);
            });
        }

        // --- Action: Move ---
        if (popoverMove) {
            popoverMove.addEventListener("click", function () {
                hidePopover();
                if (!currentActiveItem) return;
                if (inputMoveId) inputMoveId.value = currentActiveItem.id;
                if (displayMoveName) displayMoveName.textContent = currentActiveItem.name;

                // Reset Move Navigator State
                moveTreeData = [];
                moveCurrentViewingFolderId = null;
                moveSelectedDestinationFolderId = null;
                if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = "";
                if (driveMoveSelectedName) driveMoveSelectedName.textContent = "📁 Root (Top Level)";

                if (driveMoveFolderList) {
                    driveMoveFolderList.innerHTML = '<div class="drive-move-empty">Loading folder structure...</div>';
                }

                openModal(modalMove);

                // Fetch available move destinations hierarchy
                const fd = new FormData();
                fd.append("action", "get_move_destinations");
                fd.append("id", currentActiveItem.id);
                fd.append("type", currentActiveItem.type);

                fetch("explorer.php", {
                    method: "POST",
                    body: fd,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && Array.isArray(data.folders)) {
                        moveTreeData = data.folders;
                        renderDriveMoveExplorer();
                    }
                })
                .catch(err => {
                    console.error("Fetch destinations error:", err);
                    if (driveMoveFolderList) {
                        driveMoveFolderList.innerHTML = '<div class="drive-move-empty">Unable to load destinations.</div>';
                    }
                });
            });
        }

        if (btnCloseMove) btnCloseMove.addEventListener("click", () => closeModal(modalMove));
        if (btnCancelMove) btnCancelMove.addEventListener("click", () => closeModal(modalMove));
        if (modalMove) {
            modalMove.addEventListener("click", (e) => {
                if (e.target === modalMove) closeModal(modalMove);
            });
        }

        if (formMove) {
            formMove.addEventListener("submit", function (e) {
                e.preventDefault();
                const formData = new FormData(formMove);
                fetch("explorer.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        closeModal(modalMove);
                        performAjaxUpdate();
                    } else {
                        alert(data.message || "Failed to move item.");
                    }
                })
                .catch(err => {
                    console.error("Move error:", err);
                    alert("An error occurred while moving item.");
                });
            });
        }

        // --- Action: Delete ---
        if (popoverDelete) {
            popoverDelete.addEventListener("click", function () {
                hidePopover();
                if (!currentActiveItem) return;
                if (inputDeleteId) inputDeleteId.value = currentActiveItem.id;
                if (displayDeleteName) displayDeleteName.textContent = currentActiveItem.name;
                openModal(modalDelete);
            });
        }

        if (btnCloseDelete) btnCloseDelete.addEventListener("click", () => closeModal(modalDelete));
        if (btnCancelDelete) btnCancelDelete.addEventListener("click", () => closeModal(modalDelete));
        if (modalDelete) {
            modalDelete.addEventListener("click", (e) => {
                if (e.target === modalDelete) closeModal(modalDelete);
            });
        }

        if (formDelete) {
            formDelete.addEventListener("submit", function (e) {
                e.preventDefault();
                const formData = new FormData(formDelete);
                fetch("explorer.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        closeModal(modalDelete);
                        performAjaxUpdate();
                    } else {
                        alert(data.message || "Failed to delete item.");
                    }
                })
                .catch(err => {
                    console.error("Delete error:", err);
                    alert("An error occurred while deleting item.");
                });
            });
        }
    }

    // Initialize on DOM Ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initExplorerAjax);
    } else {
        initExplorerAjax();
    }
})();
