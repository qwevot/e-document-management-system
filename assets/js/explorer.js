(function () {
    "use strict";

    // ============================================================
    // Real-Time AJAX Explorer (Search, Dropdown Filters, Reset, Tabs)
    // ============================================================
    function initExplorerAjax() {
        const cardListContainer = document.getElementById("explorerCardList");

        const searchInput = document.getElementById("explorerSearchInput");
        const clearBtn = document.getElementById("explorerSearchClear");
        const searchForm = document.getElementById("explorerSearchForm");
        const filterType = document.getElementById("filterTypeSelect");
        const filterStatus = document.getElementById("filterStatusSelect");
        const modeInput = document.getElementById("explorerFilterMode");
        const folderInput = document.getElementById("explorerFilterFolder");
        const pillTabs = document.querySelectorAll(".pill-tab[data-mode]");
        const btnNewFolder = document.getElementById("btnNewFolder");

        let debounceTimer = null;
        let abortController = null;
        let currentPage = 1;
        let currentPerPage = 25;

        // Check if page/per_page/sort/order are preset in URL
        const initialParams = new URLSearchParams(window.location.search);
        let currentSort = initialParams.get("sort") || "name";
        let currentOrder = initialParams.get("order") || "asc";

        if (initialParams.has("page")) {
            currentPage = parseInt(initialParams.get("page"), 10) || 1;
        }
        if (initialParams.has("per_page")) {
            currentPerPage = parseInt(initialParams.get("per_page"), 10) || 25;
        }

        // Collect current state and perform AJAX fetch
        function performAjaxUpdate(targetPage = null) {
            if (!cardListContainer) {
                window.location.reload();
                return;
            }
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
            if (currentSort === "updated_at") {
                params.set("sort", "updated_at");
                params.set("order", currentOrder);
            } else if (currentSort === "name" && currentOrder === "desc") {
                params.set("sort", "name");
                params.set("order", "desc");
            } else {
                params.delete("sort");
                params.delete("order");
            }
            if (currentPage > 1) params.set("page", currentPage.toString());
            if (currentPerPage !== 25) params.set("per_page", currentPerPage.toString());

            // Update UI state
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
                if (typeof clearBatchSelection === "function") clearBatchSelection();
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

        // 5. Mode Switcher Pill Tabs (Instant AJAX, Resets to page 1)
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

        // 7. Delegated Pagination Controls & Table Column Sorting
        if (cardListContainer) {
            cardListContainer.addEventListener("click", function (e) {
                const pageBtn = e.target.closest(".pagination-btn, .pagination-page-btn");
                if (pageBtn && !pageBtn.disabled && !pageBtn.classList.contains("is-disabled") && !pageBtn.classList.contains("is-active")) {
                    e.preventDefault();
                    const targetPage = parseInt(pageBtn.dataset.page, 10);
                    if (targetPage > 0) {
                        performAjaxUpdate(targetPage);
                    }
                    return;
                }

                const sortCol = e.target.closest(".sortable-col");
                if (sortCol) {
                    e.preventDefault();
                    const targetSort = sortCol.dataset.sort;
                    const targetOrder = sortCol.dataset.order || "asc";
                    if (targetSort === "default" || targetSort === "reset") {
                        currentSort = "name";
                        currentOrder = "asc";
                    } else if (targetSort) {
                        currentSort = targetSort;
                        currentOrder = targetOrder;
                    }
                    performAjaxUpdate(1);
                }
            });

            // Keyboard accessibility for sort headers
            cardListContainer.addEventListener("keydown", function (e) {
                if (e.key === "Enter" || e.key === " ") {
                    const sortCol = e.target.closest(".sortable-col");
                    if (sortCol) {
                        e.preventDefault();
                        sortCol.click();
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
        }

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
                    helpFolderContext.innerHTML = "Target location: <strong>Projects</strong>";
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
                        showBottomToast(data.message || "Failed to create folder.", "error");
                    }
                })
                .catch(err => {
                    if (btnSubmitFolder) {
                        btnSubmitFolder.disabled = false;
                        btnSubmitFolder.style.opacity = "1";
                    }
                    console.error("Create folder error:", err);
                    showBottomToast("An error occurred while creating folder.", "error");
                });
            });
        }

        // ============================================================
        // 8. Upload Document Modal Controls & Drag-and-Drop Dropzone
        // ============================================================
        const btnUploadDoc = document.getElementById("btnUploadDoc");
        const modalUploadDoc = document.getElementById("modalUploadDoc");
        const formUploadDoc = document.getElementById("formUploadDoc");
        const uploadFileInput = document.getElementById("uploadFileInput");
        const uploadDropzone = document.getElementById("uploadDropzone");
        const selectedFilePreview = document.getElementById("selectedFilePreview");
        const previewFileName = document.getElementById("previewFileName");
        const previewFileSize = document.getElementById("previewFileSize");
        const btnClearSelectedFile = document.getElementById("btnClearSelectedFile");
        const uploadDocName = document.getElementById("uploadDocName");
        const uploadDocStatus = document.getElementById("uploadDocStatus");
        const uploadReviewerSection = document.getElementById("uploadReviewerSection");
        const uploadParentId = document.getElementById("uploadParentId");
        const uploadLocationHelp = document.getElementById("uploadLocationHelp");
        const btnCloseUpload = document.getElementById("btnCloseUploadModal");
        const btnCancelUpload = document.getElementById("btnCancelUpload");
        const btnSubmitUpload = document.getElementById("btnSubmitUpload");

        if (uploadDocStatus && uploadReviewerSection) {
            uploadDocStatus.addEventListener("change", function () {
                uploadReviewerSection.style.display = (this.value === "hold") ? "block" : "none";
            });
        }

        function formatBytes(bytes) {
            if (bytes === 0) return "0 Bytes";
            const k = 1024;
            const sizes = ["Bytes", "KB", "MB", "GB"];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
        }

        function handleFileSelection(file) {
            if (!file) return;
            if (previewFileName) previewFileName.textContent = file.name;
            if (previewFileSize) previewFileSize.textContent = formatBytes(file.size);

            if (selectedFilePreview) selectedFilePreview.style.display = "flex";
            if (uploadDropzone) uploadDropzone.style.display = "none";

            // Auto-fill Document Name if empty or default
            if (uploadDocName) {
                const baseName = file.name.replace(/\.[^/.]+$/, "").replace(/[_-]/g, " ");
                uploadDocName.value = baseName;
            }
        }

        function clearSelectedFile() {
            if (uploadFileInput) uploadFileInput.value = "";
            if (selectedFilePreview) selectedFilePreview.style.display = "none";
            if (uploadDropzone) uploadDropzone.style.display = "block";
        }

        function openUploadModal() {
            if (!modalUploadDoc) return;
            const currentFolder = folderInput ? folderInput.value : "";
            if (uploadParentId) uploadParentId.value = currentFolder;

            if (uploadLocationHelp) {
                const activeCrumb = document.querySelector(".breadcrumb-item.current");
                if (currentFolder && activeCrumb) {
                    uploadLocationHelp.innerHTML = "Target location: Inside <strong>" + activeCrumb.textContent.trim() + "</strong>";
                } else {
                    uploadLocationHelp.innerHTML = "Target location: <strong>Projects</strong>";
                }
            }

            clearSelectedFile();
            if (uploadDocName) uploadDocName.value = "";
            if (uploadDocStatus) uploadDocStatus.value = "active";
            if (uploadReviewerSection) {
                uploadReviewerSection.style.display = "none";
                uploadReviewerSection.querySelectorAll('input[name="reviewer_ids[]"]').forEach(cb => cb.checked = false);
            }
            openModal(modalUploadDoc, uploadFileInput);
        }

        if (btnUploadDoc) btnUploadDoc.addEventListener("click", openUploadModal);
        if (btnCloseUpload) btnCloseUpload.addEventListener("click", () => closeModal(modalUploadDoc));
        if (btnCancelUpload) btnCancelUpload.addEventListener("click", () => closeModal(modalUploadDoc));
        if (btnClearSelectedFile) btnClearSelectedFile.addEventListener("click", clearSelectedFile);

        if (modalUploadDoc) {
            modalUploadDoc.addEventListener("click", (e) => {
                if (e.target === modalUploadDoc) closeModal(modalUploadDoc);
            });
        }

        if (uploadFileInput) {
            uploadFileInput.addEventListener("change", function () {
                if (uploadFileInput.files && uploadFileInput.files[0]) {
                    handleFileSelection(uploadFileInput.files[0]);
                }
            });
        }

        // Drag and drop events for dropzone
        if (uploadDropzone) {
            ["dragenter", "dragover"].forEach(eventName => {
                uploadDropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadDropzone.classList.add("is-dragover");
                });
            });

            ["dragleave", "drop"].forEach(eventName => {
                uploadDropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadDropzone.classList.remove("is-dragover");
                });
            });

            uploadDropzone.addEventListener("drop", (e) => {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files[0]) {
                    uploadFileInput.files = dt.files;
                    handleFileSelection(dt.files[0]);
                }
            });
        }

        if (formUploadDoc) {
            formUploadDoc.addEventListener("submit", function (e) {
                e.preventDefault();
                if (!uploadFileInput || !uploadFileInput.files || !uploadFileInput.files[0]) {
                    showBottomToast("Please select a file to upload.", "warning");
                    return;
                }

                if (uploadDocStatus && uploadDocStatus.value === "hold") {
                    const checkedReviewers = formUploadDoc.querySelectorAll('input[name="reviewer_ids[]"]:checked');
                    if (checkedReviewers.length === 0) {
                        showBottomToast("Please assign at least one reviewer when setting initial status to On Hold.", "warning");
                        return;
                    }
                }

                const maxBytes = window.MAX_UPLOAD_BYTES || 41943040;
                if (uploadFileInput.files[0].size > maxBytes) {
                    showBottomToast(`File is too large. Server limit is ${formatBytes(maxBytes)}.`, "error");
                    return;
                }

                if (btnSubmitUpload) {
                    btnSubmitUpload.disabled = true;
                    btnSubmitUpload.innerHTML = '<span>Uploading file...</span>';
                    btnSubmitUpload.style.opacity = "0.7";
                }

                const formData = new FormData(formUploadDoc);

                fetch("explorer.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (btnSubmitUpload) {
                        btnSubmitUpload.disabled = false;
                        btnSubmitUpload.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg><span>Upload File</span>';
                        btnSubmitUpload.style.opacity = "1";
                    }

                    if (data.success) {
                        closeModal(modalUploadDoc);
                        performAjaxUpdate();
                    } else {
                        showBottomToast(data.message || "Failed to upload document.", "error");
                    }
                })
                .catch(err => {
                    if (btnSubmitUpload) {
                        btnSubmitUpload.disabled = false;
                        btnSubmitUpload.innerHTML = '<span>Upload File</span>';
                        btnSubmitUpload.style.opacity = "1";
                    }
                    console.error("Upload error:", err);
                    showBottomToast("Upload failed: file may be too large for server limits (40 MB).", "error");
                });
            });
        }

        // ============================================================
        // 8B. Drag-and-Drop Batch Ingestion & Confirmation Modal & Dock
        // ============================================================
        const dragOverlay = document.getElementById("explorerDragOverlay");
        const dragOverlayTargetLabel = document.getElementById("dragOverlayTargetLabel");
        const modalBatchUpload = document.getElementById("modalBatchUploadConfirm");
        const formBatchUpload = document.getElementById("formBatchUploadConfirm");
        const btnCloseBatchUpload = document.getElementById("btnCloseBatchUploadModal");
        const btnCancelBatchUpload = document.getElementById("btnCancelBatchUpload");
        const batchUploadCountText = document.getElementById("batchUploadCountText");
        const batchUploadTotalSize = document.getElementById("batchUploadTotalSize");
        const batchUploadFileList = document.getElementById("batchUploadFileList");
        const batchUploadStatusSelect = document.getElementById("batchUploadStatusSelect");
        const batchReviewerSection = document.getElementById("batchReviewerSection");
        const batchUploadLocationHelp = document.getElementById("batchUploadLocationHelp");
        const btnConfirmBatchUpload = document.getElementById("btnConfirmBatchUpload");
        const btnConfirmBatchUploadText = document.getElementById("btnConfirmBatchUploadText");

        if (batchUploadStatusSelect && batchReviewerSection) {
            batchUploadStatusSelect.addEventListener("change", function () {
                batchReviewerSection.style.display = (this.value === "hold") ? "block" : "none";
            });
        }

        // Floating Upload Dock Elements
        const edmsUploadDock = document.getElementById("edmsUploadDock");
        const uploadDockHeader = document.getElementById("uploadDockHeader");
        const uploadDockTitleText = document.getElementById("uploadDockTitleText");
        const uploadDockSpinner = document.getElementById("uploadDockSpinner");
        const btnToggleUploadDock = document.getElementById("btnToggleUploadDock");
        const btnCloseUploadDock = document.getElementById("btnCloseUploadDock");
        const uploadDockOverallBar = document.getElementById("uploadDockOverallBar");
        const uploadDockItemList = document.getElementById("uploadDockItemList");

        let batchWorkingFiles = [];
        let batchTarget = { id: "", name: "Projects" };
        let dragCounter = 0;
        let hoveredFolderRow = null;

        function escapeBatchHtml(str) {
            return String(str ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function getFileIconSvg(filename) {
            const ext = (filename.split(".").pop() || "").toLowerCase();
            if (["pdf"].includes(ext)) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 15h2a2 2 0 0 0 0-4H9v6"></path><path d="M9 13h2"></path></svg>';
            }
            if (["doc", "docx"].includes(ext)) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="var(--info-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg>';
            }
            if (["xlsx", "xls", "csv"].includes(ext)) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M8 13l4 4m0-4l-4 4"></path></svg>';
            }
            if (["png", "jpg", "jpeg", "webp", "gif"].includes(ext)) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="var(--info-sky-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
            }
            if (["zip", "rar", "7z", "tar", "gz"].includes(ext)) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><circle cx="12" cy="14" r="2"></circle></svg>';
            }
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
        }

        function getCurrentTargetFolderInfo() {
            const currentFolderId = folderInput ? folderInput.value : "";
            const activeCrumb = document.querySelector(".breadcrumb-item.current");
            const currentFolderName = (currentFolderId && activeCrumb) ? activeCrumb.textContent.trim() : "Projects";
            return { id: currentFolderId, name: currentFolderName };
        }

        function renderBatchModalList() {
            if (!batchUploadFileList) return;
            batchUploadFileList.innerHTML = "";

            let totalBytes = 0;
            batchWorkingFiles.forEach((file, index) => {
                totalBytes += file.size;
                const row = document.createElement("div");
                row.className = "batch-file-item";
                row.innerHTML = `
                    <div class="batch-file-icon">${getFileIconSvg(file.name)}</div>
                    <div class="batch-file-details">
                        <span class="batch-file-name" title="${escapeBatchHtml(file.name)}">${escapeBatchHtml(file.name)}</span>
                        <span class="batch-file-size">${formatBytes(file.size)}</span>
                    </div>
                    <button type="button" class="batch-file-remove-btn" data-index="${index}" title="Remove file">&times;</button>
                `;
                batchUploadFileList.appendChild(row);
            });

            // Update summary
            const count = batchWorkingFiles.length;
            if (batchUploadCountText) {
                batchUploadCountText.innerHTML = `Are you sure you want to upload <strong>${count} document${count === 1 ? "" : "s"}</strong>?`;
            }
            if (batchUploadTotalSize) {
                batchUploadTotalSize.textContent = formatBytes(totalBytes) + " total";
            }
            if (btnConfirmBatchUploadText) {
                btnConfirmBatchUploadText.textContent = `Upload ${count} Document${count === 1 ? "" : "s"}`;
            }
            if (btnConfirmBatchUpload) {
                btnConfirmBatchUpload.disabled = count === 0;
            }

            // Bind remove button handlers
            batchUploadFileList.querySelectorAll(".batch-file-remove-btn").forEach((btn) => {
                btn.addEventListener("click", function (e) {
                    e.stopPropagation();
                    const removeIdx = parseInt(this.dataset.index, 10);
                    batchWorkingFiles.splice(removeIdx, 1);
                    if (batchWorkingFiles.length === 0) {
                        closeModal(modalBatchUpload);
                    } else {
                        renderBatchModalList();
                    }
                });
            });
        }

        function openBatchModal(files, targetFolder) {
            if (!files || files.length === 0 || !modalBatchUpload) return;
            batchWorkingFiles = Array.from(files);
            batchTarget = targetFolder || getCurrentTargetFolderInfo();

            if (batchUploadLocationHelp) {
                batchUploadLocationHelp.innerHTML = `Target location: Inside <strong>${escapeBatchHtml(batchTarget.name)}</strong>`;
            }
            if (batchUploadStatusSelect) {
                batchUploadStatusSelect.value = "active";
            }
            if (batchReviewerSection) {
                batchReviewerSection.style.display = "none";
                batchReviewerSection.querySelectorAll('input[name="batch_reviewer_ids[]"]').forEach(cb => cb.checked = false);
            }

            renderBatchModalList();
            openModal(modalBatchUpload, btnConfirmBatchUpload);
        }

        // Window & Explorer Drag Listeners
        window.addEventListener("dragenter", function (e) {
            if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes("Files")) {
                dragCounter++;
                if (dragOverlay) {
                    dragOverlay.classList.add("is-active");
                    const target = getCurrentTargetFolderInfo();
                    if (dragOverlayTargetLabel) dragOverlayTargetLabel.textContent = target.name;
                }
            }
        });

        window.addEventListener("dragleave", function (e) {
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                if (dragOverlay) dragOverlay.classList.remove("is-active");
                if (hoveredFolderRow) {
                    hoveredFolderRow.classList.remove("is-drop-target");
                    hoveredFolderRow = null;
                }
            }
        });

        window.addEventListener("dragover", function (e) {
            e.preventDefault();
            // Highlight individual folder cards under mouse cursor
            const cardRow = e.target.closest('.explorer-item-card[data-type="folder"]');
            if (cardRow && cardRow !== hoveredFolderRow) {
                if (hoveredFolderRow) hoveredFolderRow.classList.remove("is-drop-target");
                hoveredFolderRow = cardRow;
                hoveredFolderRow.classList.add("is-drop-target");
                if (dragOverlayTargetLabel) {
                    dragOverlayTargetLabel.textContent = "Folder: " + (hoveredFolderRow.dataset.name || "Folder");
                }
            } else if (!cardRow && hoveredFolderRow) {
                hoveredFolderRow.classList.remove("is-drop-target");
                hoveredFolderRow = null;
                const defTarget = getCurrentTargetFolderInfo();
                if (dragOverlayTargetLabel) dragOverlayTargetLabel.textContent = defTarget.name;
            }
        });

        window.addEventListener("drop", function (e) {
            e.preventDefault();
            dragCounter = 0;
            if (dragOverlay) dragOverlay.classList.remove("is-active");

            // Ignore drops inside single upload dropzone (it handles itself)
            if (e.target.closest("#uploadDropzone")) return;

            let targetFolder = getCurrentTargetFolderInfo();
            if (hoveredFolderRow) {
                targetFolder = {
                    id: hoveredFolderRow.dataset.id || "",
                    name: hoveredFolderRow.dataset.name || "Folder"
                };
                hoveredFolderRow.classList.remove("is-drop-target");
                hoveredFolderRow = null;
            }

            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                // If any existing modal is open, avoid opening another
                const anyOpenModal = document.querySelector(".edms-modal-backdrop.is-open");
                if (anyOpenModal) return;

                openBatchModal(e.dataTransfer.files, targetFolder);
            }
        });

        // Batch Modal Actions
        if (btnCloseBatchUpload) btnCloseBatchUpload.addEventListener("click", () => closeModal(modalBatchUpload));
        if (btnCancelBatchUpload) btnCancelBatchUpload.addEventListener("click", () => closeModal(modalBatchUpload));
        if (modalBatchUpload) {
            modalBatchUpload.addEventListener("click", (e) => {
                if (e.target === modalBatchUpload) closeModal(modalBatchUpload);
            });
        }

        if (formBatchUpload) {
            formBatchUpload.addEventListener("submit", function (e) {
                e.preventDefault();
                if (batchWorkingFiles.length === 0) return;

                const selectedStatus = batchUploadStatusSelect ? batchUploadStatusSelect.value : "active";
                let batchReviewerIds = [];
                if (selectedStatus === "hold") {
                    const checked = formBatchUpload.querySelectorAll('input[name="batch_reviewer_ids[]"]:checked');
                    if (checked.length === 0) {
                        showBottomToast("Please assign at least one reviewer when setting initial status to On Hold.", "warning");
                        return;
                    }
                    batchReviewerIds = Array.from(checked).map(cb => cb.value);
                }

                const filesToUpload = batchWorkingFiles.slice();
                const targetId = batchTarget.id;

                closeModal(modalBatchUpload);
                startBatchUploadQueue(filesToUpload, targetId, selectedStatus, batchReviewerIds);
            });
        }

        // ============================================================
        // Asynchronous Sequential Upload Queue & Floating Dock Engine
        // ============================================================
        function startBatchUploadQueue(files, targetFolderId, initialStatus, batchReviewerIds = []) {
            if (!edmsUploadDock || !files || files.length === 0) return;

            // Make dock visible and expanded
            edmsUploadDock.style.display = "block";
            edmsUploadDock.classList.remove("is-minimized");
            if (btnCloseUploadDock) btnCloseUploadDock.style.display = "none";
            if (uploadDockSpinner) uploadDockSpinner.style.display = "inline-block";
            if (uploadDockOverallBar) uploadDockOverallBar.style.width = "0%";

            // Prepare Queue Model
            const queue = files.map((file, idx) => ({
                id: idx,
                file: file,
                name: file.name,
                size: file.size,
                status: "waiting", // waiting | uploading | success | error
                progress: 0,
                errorMessage: ""
            }));

            // Populate dock items
            if (uploadDockItemList) {
                uploadDockItemList.innerHTML = "";
                queue.forEach((item) => {
                    const row = document.createElement("div");
                    row.className = "dock-item-row";
                    row.id = `dockItem_${item.id}`;
                    row.innerHTML = `
                        <div class="dock-item-icon">${getFileIconSvg(item.name)}</div>
                        <div class="dock-item-details">
                            <span class="dock-item-name" title="${escapeBatchHtml(item.name)}">${escapeBatchHtml(item.name)}</span>
                            <span class="dock-item-status-text" id="dockItemStatusText_${item.id}">Waiting...</span>
                        </div>
                        <div class="dock-item-state is-waiting" id="dockItemState_${item.id}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        </div>
                    `;
                    uploadDockItemList.appendChild(row);
                });
            }

            processUploadQueueSequentially(queue, targetFolderId, initialStatus, batchReviewerIds);
        }

        async function processUploadQueueSequentially(queue, targetFolderId, initialStatus, batchReviewerIds = []) {
            let successCount = 0;
            let errorCount = 0;
            const total = queue.length;

            for (let i = 0; i < total; i++) {
                const item = queue[i];
                if (uploadDockTitleText) {
                    uploadDockTitleText.textContent = `Uploading ${i + 1} of ${total} file${total === 1 ? "" : "s"}...`;
                }

                // Upload single file
                const success = await uploadSingleItem(item, targetFolderId, initialStatus, batchReviewerIds);
                if (success) {
                    successCount++;
                } else {
                    errorCount++;
                }

                // Update overall progress bar
                const overallPct = Math.round(((i + 1) / total) * 100);
                if (uploadDockOverallBar) uploadDockOverallBar.style.width = overallPct + "%";
            }

            // All finished
            if (uploadDockSpinner) uploadDockSpinner.style.display = "none";
            if (btnCloseUploadDock) btnCloseUploadDock.style.display = "flex";

            if (uploadDockTitleText) {
                if (errorCount === 0) {
                    uploadDockTitleText.innerHTML = `All ${total} document${total === 1 ? "" : "s"} uploaded`;
                } else {
                    uploadDockTitleText.innerHTML = `${successCount} uploaded, ${errorCount} failed`;
                }
            }

            // Refresh Explorer folder view asynchronously so new items appear immediately!
            if (successCount > 0) {
                performAjaxUpdate();
            }
        }

        function uploadSingleItem(item, targetFolderId, initialStatus, batchReviewerIds = []) {
            return new Promise((resolve) => {
                const stateEl = document.getElementById(`dockItemState_${item.id}`);
                const statusTextEl = document.getElementById(`dockItemStatusText_${item.id}`);

                if (stateEl) {
                    stateEl.className = "dock-item-state is-uploading";
                    stateEl.innerHTML = '<span class="dock-spinner-sm"></span>';
                }
                if (statusTextEl) {
                    statusTextEl.textContent = "Uploading (0%)...";
                }

                const fd = new FormData();
                fd.append("action", "upload_document");
                if (targetFolderId) {
                    fd.append("parent_id", targetFolderId);
                }
                fd.append("status", initialStatus);
                if (initialStatus === "hold" && Array.isArray(batchReviewerIds)) {
                    batchReviewerIds.forEach(id => fd.append("reviewer_ids[]", id));
                }
                const baseName = item.file.name.replace(/\.[^/.]+$/, "").replace(/[_-]/g, " ");
                fd.append("name", baseName);
                fd.append("file", item.file);

                const xhr = new XMLHttpRequest();
                xhr.open("POST", "explorer.php?ajax=1");
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content")
                    || window.EDMS_CSRF_TOKEN
                    || document.querySelector('input[name="csrf_token"]')?.value
                    || "";
                if (csrfToken) {
                    xhr.setRequestHeader("X-CSRF-Token", csrfToken);
                    fd.append("csrf_token", csrfToken);
                }
                xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

                xhr.upload.onprogress = function (e) {
                    if (e.lengthComputable) {
                        const pct = Math.round((e.loaded / e.total) * 100);
                        if (statusTextEl) statusTextEl.textContent = `Uploading (${pct}%)...`;
                    }
                };

                xhr.onload = function () {
                    let res = null;
                    try {
                        res = JSON.parse(xhr.responseText);
                    } catch (_) {}

                    if (xhr.status >= 200 && xhr.status < 300 && res && res.success) {
                        if (stateEl) {
                            stateEl.className = "dock-item-state is-success";
                            stateEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        }
                        if (statusTextEl) {
                            statusTextEl.textContent = formatBytes(item.size) + " - Completed";
                        }
                        resolve(true);
                    } else {
                        const errMsg = (res && res.message) ? res.message : "Upload rejected";
                        if (stateEl) {
                            stateEl.className = "dock-item-state is-error";
                            stateEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                            stateEl.title = errMsg;
                        }
                        if (statusTextEl) {
                            statusTextEl.textContent = errMsg;
                            statusTextEl.style.color = "var(--red)";
                        }
                        if (typeof window.showBottomToast === "function") {
                            window.showBottomToast(errMsg, "error");
                        }
                        resolve(false);
                    }
                };

                xhr.onerror = function () {
                    if (stateEl) {
                        stateEl.className = "dock-item-state is-error";
                        stateEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                    }
                    if (statusTextEl) {
                        statusTextEl.textContent = "Network error";
                        statusTextEl.style.color = "var(--red)";
                    }
                    if (typeof window.showBottomToast === "function") {
                        window.showBottomToast("Network error occurred during upload.", "error");
                    }
                    resolve(false);
                };

                xhr.send(fd);
            });
        }

        // Dock toggle minimize / expand
        if (btnToggleUploadDock) {
            btnToggleUploadDock.addEventListener("click", function (e) {
                e.stopPropagation();
                if (edmsUploadDock) edmsUploadDock.classList.toggle("is-minimized");
            });
        }
        if (uploadDockHeader) {
            uploadDockHeader.addEventListener("click", function (e) {
                if (e.target.closest(".dock-btn-icon")) return;
                if (edmsUploadDock) edmsUploadDock.classList.toggle("is-minimized");
            });
        }
        if (btnCloseUploadDock) {
            btnCloseUploadDock.addEventListener("click", function (e) {
                e.stopPropagation();
                if (edmsUploadDock) edmsUploadDock.style.display = "none";
            });
        }

        // ============================================================
        // 8C. Action Popover Menu & Item Operations (Rename, Move, Delete)
        // ============================================================
        // CONTEXT POPOVER & ACTION MODALS (RENAME, MOVE, DELETE, VIEWER)
        // ============================================================
        const popover = document.getElementById("edmsActionPopover");
        const popoverView = document.getElementById("popoverActionView");
        const popoverDownload = document.getElementById("popoverActionDownload");
        const popoverCopyLink = document.getElementById("popoverActionCopyLink");
        const popoverRename = document.getElementById("popoverActionRename");
        const popoverMove = document.getElementById("popoverActionMove");
        const popoverDelete = document.getElementById("popoverActionDelete");
        const popoverAuditHistory = document.getElementById("popoverActionAuditHistory");

        // Modals & Forms
        const modalAuditHistory = document.getElementById("modalAuditHistory");
        const auditModalDocName = document.getElementById("auditModalDocName");
        const auditModalFeed = document.getElementById("auditModalFeed");
        const btnCloseAuditModal = document.getElementById("btnCloseAuditModal");
        const btnCancelAuditModal = document.getElementById("btnCancelAuditModal");
        const modalRename = document.getElementById("modalRenameNode");
        const formRename = document.getElementById("formRenameNode");
        const inputRenameId = document.getElementById("renameNodeId");
        const inputRenameName = document.getElementById("renameNodeName");
        const btnCloseRename = document.getElementById("btnCloseRenameModal");
        const btnCancelRename = document.getElementById("btnCancelRename");

        const modalMove = document.getElementById("modalMoveNode");
        const formMove = document.getElementById("formMoveNode");
        const inputMoveAction = document.getElementById("moveNodeAction");
        const inputMoveId = document.getElementById("moveNodeId");
        const inputMoveIds = document.getElementById("moveNodeIds");
        const inputMoveTargetFolderId = document.getElementById("moveTargetFolderId");
        const moveModalTitleName = document.getElementById("moveModalTitleName");
        const moveItemLocationDisplay = document.getElementById("moveItemLocationDisplay");
        const driveMoveBreadcrumbs = document.getElementById("driveMoveBreadcrumbs");
        const driveMoveFolderList = document.getElementById("driveMoveFolderList");
        const driveMoveSelectedName = document.getElementById("driveMoveSelectedName");
        const btnCloseMove = document.getElementById("btnCloseMoveModal");
        const btnCancelMove = document.getElementById("btnCancelMove");

        const modalDelete = document.getElementById("modalDeleteNode");
        const formDelete = document.getElementById("formDeleteNode");
        const inputDeleteAction = document.getElementById("deleteNodeAction");
        const inputDeleteId = document.getElementById("deleteNodeId");
        const inputDeleteIds = document.getElementById("deleteNodeIds");
        const displayDeleteName = document.getElementById("deleteItemNameDisplay");
        const btnCloseDelete = document.getElementById("btnCloseDeleteModal");
        const btnCancelDelete = document.getElementById("btnCancelDelete");

        // Popover Elements for Context Menu
        const popoverHeaderLabel = document.getElementById("popoverHeaderLabel");
        const popoverActionMoveLabel = document.getElementById("popoverActionMoveLabel");
        const popoverActionDeleteLabel = document.getElementById("popoverActionDeleteLabel");

        // Selection State
        const selectedItemsMap = new Map();
        let isMarqueeDragging = false;
        let marqueeStartX = 0;
        let marqueeStartY = 0;
        let marqueeEl = null;

        function updateSelectionUI() {
            document.querySelectorAll(".explorer-item-card").forEach(card => {
                const id = card.dataset.id;
                const isSel = selectedItemsMap.has(id);
                card.classList.toggle("is-selected", isSel);
                const cb = card.querySelector(".item-checkbox");
                if (cb) cb.checked = isSel;
            });
        }

        function clearBatchSelection() {
            selectedItemsMap.clear();
            updateSelectionUI();
        }

        function getItemDataFromRow(el) {
            const card = el ? el.closest(".explorer-item-card") : null;
            if (!card) return null;
            return {
                id: card.dataset.id,
                name: card.dataset.name,
                type: card.dataset.type || "document",
                location: card.dataset.location || "Root",
                ext: card.dataset.ext || "pdf",
                version: card.dataset.version || "v1.0",
                status: card.dataset.status || "active"
            };
        }

        // Drive Move State
        let moveTreeData = [];
        let moveCurrentViewingFolderId = null; // null = root
        let moveSelectedDestinationFolderId = null; // null = root

        let currentActiveItem = null;

        // State to differentiate between 3-Dots popover (always single item) and Right-Click context menu (supports bulk)
        let isContextMenuActive = false;

        function hidePopover() {
            if (popover) popover.style.display = "none";
            isContextMenuActive = false;
        }

        // Delegated Item Click Handler (3-Dots Popover: Always Single Selection)
        document.addEventListener("click", function (e) {
            // Check 3-Dots button
            const btn = e.target.closest(".btn-more-actions");
            if (!btn || !popover) return;
            e.preventDefault();
            e.stopImmediatePropagation();

            isContextMenuActive = false; // Explicitly NOT right-click context menu

            const nodeId = btn.dataset.id;
            const nodeName = btn.dataset.name;
            const nodeType = btn.dataset.type || "document";
            const nodeLocation = btn.dataset.location || "Root";
            const nodeExt = btn.dataset.ext || "pdf";
            const nodeVersion = btn.dataset.version || "v1.0";

            const card = btn.closest(".explorer-item-card");
            const nodeStatus = (card ? card.dataset.status : "") || btn.dataset.status || "active";

            currentActiveItem = {
                id: nodeId,
                name: nodeName,
                type: nodeType,
                location: nodeLocation,
                ext: nodeExt,
                version: nodeVersion,
                status: nodeStatus
            };

            // 3-Dots actions are strictly single-item actions:
            const isObsolete = (nodeStatus === "obsolete");
            if (popoverHeaderLabel) popoverHeaderLabel.style.display = "none";
            if (popoverRename) popoverRename.style.display = isObsolete ? "none" : "flex";
            if (popoverMove) popoverMove.style.display = isObsolete ? "none" : "flex";
            if (popoverActionMoveLabel) popoverActionMoveLabel.textContent = "Move to...";
            if (popoverActionDeleteLabel) popoverActionDeleteLabel.textContent = isObsolete ? "Restore" : "Trash";
            if (popoverDelete) popoverDelete.style.color = isObsolete ? "var(--green)" : "";

            // Configure Open Workspace link in popover
            if (popoverView) {
                if (nodeType === "document") {
                    popoverView.style.display = "flex";
                    popoverView.href = "view.php?id=" + encodeURIComponent(nodeId);
                    popoverView.onclick = () => hidePopover();
                } else {
                    popoverView.style.display = "none";
                }
            }

            // Configure Download link in popover
            if (popoverDownload) {
                if (nodeType === "document") {
                    popoverDownload.style.display = "flex";
                    popoverDownload.href = "download.php?id=" + encodeURIComponent(nodeId);
                    popoverDownload.onclick = () => hidePopover();
                } else {
                    popoverDownload.style.display = "none";
                }
            }

            // Configure Copy Link in popover
            if (popoverCopyLink) {
                popoverCopyLink.style.display = "flex";
            }

            // Configure Audit History in popover
            if (popoverAuditHistory) {
                if (nodeType === "document") {
                    popoverAuditHistory.style.display = "flex";
                } else {
                    popoverAuditHistory.style.display = "none";
                }
            }

            // Position popover
            const rect = btn.getBoundingClientRect();
            popover.style.display = "flex";
            const popoverWidth = 175;
            let left = rect.right - popoverWidth;
            if (left < 10) left = 10;
            let top = rect.bottom + 4;
            if (top + 220 > window.innerHeight) {
                top = rect.top - 220;
            }

            popover.style.left = left + "px";
            popover.style.top = top + "px";
        });

        // Close popover when clicking anywhere else
        document.addEventListener("click", function (e) {
            if (popover && !popover.contains(e.target) && !e.target.closest(".btn-more-actions")) {
                hidePopover();
            }
        });

        // Close popover / modals on Escape key
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                hidePopover();
                if (typeof clearBatchSelection === "function") clearBatchSelection();
                if (modalNewFolder && modalNewFolder.classList.contains("is-open")) closeModal(modalNewFolder);
                if (modalUploadDoc && modalUploadDoc.classList.contains("is-open")) closeModal(modalUploadDoc);
                if (modalBatchUpload && modalBatchUpload.classList.contains("is-open")) closeModal(modalBatchUpload);
                if (modalRename && modalRename.classList.contains("is-open")) closeModal(modalRename);
                if (modalMove && modalMove.classList.contains("is-open")) closeModal(modalMove);
                if (modalDelete && modalDelete.classList.contains("is-open")) closeModal(modalDelete);
                if (modalAuditHistory && modalAuditHistory.classList.contains("is-open")) closeModal(modalAuditHistory);
            }
        });

        // --- Action: Copy Link ---
        if (popoverCopyLink) {
            popoverCopyLink.addEventListener("click", function () {
                hidePopover();
                if (!currentActiveItem || !currentActiveItem.id) return;

                const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, "/");
                let targetUrl = "";
                let label = "Link";
                if (currentActiveItem.type === "folder") {
                    targetUrl = basePath + "explorer.php?mode=project&folder=" + encodeURIComponent(currentActiveItem.id);
                    label = "Folder";
                } else {
                    targetUrl = basePath + "view.php?id=" + encodeURIComponent(currentActiveItem.id);
                    label = "Document";
                }
                window.copyTextToClipboard(targetUrl, `${label} link copied to clipboard!`);
            });
        }

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
                        showBottomToast(data.message || "Failed to rename item.", "error");
                    }
                })
                .catch(err => {
                    console.error("Rename error:", err);
                    showBottomToast("An error occurred while renaming.", "error");
                });
            });
        }

        const chevronRightIconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
        const folderIconSvg = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';

        // Helper: Compute full hierarchical string for a folder
        function getFullFolderPath(folderId) {
            if (folderId === null || folderId === "" || !folderId) {
                return "Projects";
            }
            const pathNames = [];
            let currId = parseInt(folderId, 10);
            while (currId) {
                const f = moveTreeData.find(item => item.id === currId);
                if (!f) break;
                pathNames.unshift(f.name);
                currId = f.parent_id;
            }
            return "Projects > " + pathNames.join(" > ");
        }

        function renderDriveMoveExplorer() {
            if (!driveMoveFolderList || !driveMoveBreadcrumbs) return;

            // 1. Render Breadcrumbs
            driveMoveBreadcrumbs.innerHTML = "";
            const breadcrumbItems = [];

            // Root Crumb
            const rootCrumb = document.createElement("button");
            rootCrumb.type = "button";
            rootCrumb.className = "drive-crumb-btn" + (moveCurrentViewingFolderId === null ? " is-current" : "");
            rootCrumb.textContent = "Projects";
            rootCrumb.addEventListener("click", () => {
                if (moveCurrentViewingFolderId !== null) {
                    moveCurrentViewingFolderId = null;
                    moveSelectedDestinationFolderId = null;
                    if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = "";
                    if (driveMoveSelectedName) driveMoveSelectedName.textContent = getFullFolderPath(null);
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
                            if (driveMoveSelectedName) driveMoveSelectedName.textContent = getFullFolderPath(f.id);
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
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = getFullFolderPath(folder.id);
                        renderDriveMoveExplorer();
                    });
                    row.appendChild(navBtn);
                }

                if (!folder.disabled) {
                    row.addEventListener("click", () => {
                        moveSelectedDestinationFolderId = folder.id;
                        if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = folder.id;
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = getFullFolderPath(folder.id);
                        renderDriveMoveExplorer();
                    });

                    // Double click to enter folder
                    row.addEventListener("dblclick", () => {
                        moveCurrentViewingFolderId = folder.id;
                        moveSelectedDestinationFolderId = folder.id;
                        if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = folder.id;
                        if (driveMoveSelectedName) driveMoveSelectedName.textContent = getFullFolderPath(folder.id);
                        renderDriveMoveExplorer();
                    });
                }

                driveMoveFolderList.appendChild(row);
            });
        }

        // ============================================================
        // Unified Move & Archive Modal Openers (Single & Batch)
        // ============================================================
        function openMoveModalForItems(items) {
            if (!items || items.length === 0) return;
            hidePopover();

            const isBatch = items.length > 1;
            if (inputMoveAction) inputMoveAction.value = isBatch ? "batch_move" : "move_node";
            if (inputMoveId) inputMoveId.value = isBatch ? "" : items[0].id;
            if (inputMoveIds) inputMoveIds.value = isBatch ? items.map(i => i.id).join(",") : "";

            if (moveModalTitleName) {
                moveModalTitleName.textContent = isBatch ? `${items.length} selected items` : items[0].name;
            }
            if (moveItemLocationDisplay) {
                moveItemLocationDisplay.textContent = isBatch ? "Various locations" : (items[0].location || "Projects");
            }

            // Reset Move Navigator State
            moveTreeData = [];
            moveCurrentViewingFolderId = null;
            moveSelectedDestinationFolderId = null;
            if (inputMoveTargetFolderId) inputMoveTargetFolderId.value = "";
            if (driveMoveSelectedName) driveMoveSelectedName.textContent = "Projects";

            if (driveMoveFolderList) {
                driveMoveFolderList.innerHTML = '<div class="drive-move-empty">Loading folder structure...</div>';
            }

            openModal(modalMove);

            // Fetch available move destinations hierarchy
            const fd = new FormData();
            fd.append("action", "get_move_destinations");
            if (isBatch) {
                fd.append("ids", items.map(i => i.id).join(","));
                fd.append("type", "folder");
            } else {
                fd.append("id", items[0].id);
                fd.append("type", items[0].type);
            }

            fetch("explorer.php", {
                method: "POST",
                body: fd,
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.folders)) {
                    moveTreeData = data.folders;

                    if (!isBatch && items[0].location && items[0].location !== "Root") {
                        const parentMatch = moveTreeData.find(f => f.name === items[0].location);
                        if (parentMatch) {
                            moveItemLocationDisplay.textContent = getFullFolderPath(parentMatch.id);
                        }
                    }

                    renderDriveMoveExplorer();
                }
            })
            .catch(err => {
                console.error("Fetch destinations error:", err);
                if (driveMoveFolderList) {
                    driveMoveFolderList.innerHTML = '<div class="drive-move-empty">Unable to load destinations.</div>';
                }
            });
        }

        function openArchiveModalForItems(items) {
            if (!items || items.length === 0) return;
            hidePopover();

            const isBatch = items.length > 1;
            if (inputDeleteAction) inputDeleteAction.value = isBatch ? "batch_archive" : "delete_node";
            if (inputDeleteId) inputDeleteId.value = isBatch ? "" : items[0].id;
            if (inputDeleteIds) inputDeleteIds.value = isBatch ? items.map(i => i.id).join(",") : "";

            if (displayDeleteName) {
                displayDeleteName.textContent = isBatch ? `${items.length} selected items` : items[0].name;
            }

            openModal(modalDelete);
        }

        // --- Action: Move ---
        if (popoverMove) {
            popoverMove.addEventListener("click", function () {
                const isBulk = isContextMenuActive && selectedItemsMap.size > 1;
                const itemsToMove = isBulk ? Array.from(selectedItemsMap.values()) : (currentActiveItem ? [currentActiveItem] : []);
                hidePopover();
                if (itemsToMove.length > 0) {
                    openMoveModalForItems(itemsToMove);
                }
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
                        clearBatchSelection();
                        performAjaxUpdate();
                    } else {
                        showBottomToast(data.message || "Failed to move item(s).", "error");
                    }
                })
                .catch(err => {
                    console.error("Move error:", err);
                    showBottomToast("An error occurred while moving item(s).", "error");
                });
            });
        }

        // --- Action: Delete / Archive ---
        if (popoverDelete) {
            popoverDelete.addEventListener("click", function () {
                if (currentActiveItem && currentActiveItem.status === "obsolete") {
                    hidePopover();
                    const fd = new FormData();
                    fd.append("action", "restore_node");
                    fd.append("id", currentActiveItem.id);
                    fetch("explorer.php", { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
                        .then(r => r.json())
                        .then(d => d.success ? window.location.reload() : showBottomToast(d.message || "Failed to restore item.", "error"));
                    return;
                }
                const isBulk = isContextMenuActive && selectedItemsMap.size > 1;
                const itemsToArchive = isBulk ? Array.from(selectedItemsMap.values()) : (currentActiveItem ? [currentActiveItem] : []);
                hidePopover();
                if (itemsToArchive.length > 0) {
                    openArchiveModalForItems(itemsToArchive);
                }
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
                        clearBatchSelection();
                        performAjaxUpdate();
                    } else {
                        showBottomToast(data.message || "Failed to archive item(s).", "error");
                    }
                })
                .catch(err => {
                    console.error("Delete error:", err);
                    showBottomToast("An error occurred while archiving item(s).", "error");
                });
            });
        }

        // --- Action: Document Audit History Modal ---
        if (popoverAuditHistory) {
            popoverAuditHistory.addEventListener("click", function () {
                hidePopover();
                if (!currentActiveItem || currentActiveItem.type !== "document") return;

                if (auditModalDocName) auditModalDocName.textContent = currentActiveItem.name;
                if (auditModalFeed) {
                    auditModalFeed.innerHTML = '<div class="history-empty-state"><span>Loading audit records...</span></div>';
                }

                openModal(modalAuditHistory);

                fetch(`explorer.php?action=get_audit_history&doc_id=${currentActiveItem.id}`, {
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (auditModalFeed && data.success) {
                        auditModalFeed.innerHTML = data.html || '';
                    }
                })
                .catch(err => console.error("Error fetching audit history:", err));
            });
        }

        if (btnCloseAuditModal) btnCloseAuditModal.addEventListener("click", () => closeModal(modalAuditHistory));
        if (btnCancelAuditModal) btnCancelAuditModal.addEventListener("click", () => closeModal(modalAuditHistory));
        if (modalAuditHistory) {
            modalAuditHistory.addEventListener("click", (e) => {
                if (e.target === modalAuditHistory) closeModal(modalAuditHistory);
            });
        }

        // Checkbox Direct Click Selection Toggle
        document.addEventListener("change", function (e) {
            const cb = e.target.closest(".item-checkbox");
            if (!cb) return;
            const card = cb.closest(".explorer-item-card");
            if (!card) return;
            const itemData = getItemDataFromRow(card);
            if (!itemData) return;

            if (cb.checked) {
                selectedItemsMap.set(itemData.id, itemData);
            } else {
                selectedItemsMap.delete(itemData.id);
            }
            updateSelectionUI();
        });

        // Double-click row card to open Workspace (Documents) or navigate inside (Folders)
        document.addEventListener("dblclick", function (e) {
            if (e.target.closest("input, button, a, .item-checkbox, .btn-action, .btn-more-actions, .edms-action-popover, .edms-modal-backdrop")) {
                return;
            }
            const card = e.target.closest(".explorer-item-card");
            if (!card) return;

            const id = card.dataset.id;
            const type = card.dataset.type || "document";

            if (type === "folder") {
                if (folderInput) {
                    folderInput.value = id;
                    performAjaxUpdate(1);
                } else {
                    const currentMode = modeInput ? modeInput.value : "project";
                    window.location.href = `explorer.php?mode=${encodeURIComponent(currentMode)}&folder=${encodeURIComponent(id)}`;
                }
            } else {
                window.location.href = `view.php?id=${encodeURIComponent(id)}`;
            }
        });

        // ============================================================
        // 8D. Marquee Drag-to-Select & Contextual Right-Click Engine
        // ============================================================
        // 1. Mouse Drag Selection (Marquee / Lasso)
        document.addEventListener("mousedown", function (e) {
            if (e.button !== 0) return; // Left click only
            if (!e.target.closest(".explorer-card-list, #explorerCardList, .explorer-table-header")) return;
            if (e.target.closest("button, a, input, select, textarea, .btn-action, .edms-action-popover, .edms-modal-backdrop")) {
                return;
            }

            const clickedCard = e.target.closest(".explorer-item-card");
            const isModifier = e.ctrlKey || e.metaKey;

            if (clickedCard) {
                const itemData = getItemDataFromRow(clickedCard);
                if (isModifier) {
                    if (selectedItemsMap.has(itemData.id)) {
                        selectedItemsMap.delete(itemData.id);
                    } else {
                        selectedItemsMap.set(itemData.id, itemData);
                    }
                    updateSelectionUI();
                    return;
                } else if (!selectedItemsMap.has(itemData.id)) {
                    selectedItemsMap.clear();
                    selectedItemsMap.set(itemData.id, itemData);
                    updateSelectionUI();
                }
            } else if (!isModifier) {
                clearBatchSelection();
            }

            // Begin Marquee Box Drag
            isMarqueeDragging = true;
            marqueeStartX = e.clientX;
            marqueeStartY = e.clientY;

            if (!marqueeEl) {
                marqueeEl = document.createElement("div");
                marqueeEl.className = "selection-marquee";
                marqueeEl.style.display = "none";
                document.body.appendChild(marqueeEl);
            }
        });

        window.addEventListener("mousemove", function (e) {
            if (!isMarqueeDragging || !marqueeEl) return;
            e.preventDefault();

            const currentX = e.clientX;
            const currentY = e.clientY;
            const x = Math.min(marqueeStartX, currentX);
            const y = Math.min(marqueeStartY, currentY);
            const w = Math.abs(currentX - marqueeStartX);
            const h = Math.abs(currentY - marqueeStartY);

            if (w > 4 || h > 4) {
                marqueeEl.style.display = "block";
                marqueeEl.style.left = x + "px";
                marqueeEl.style.top = y + "px";
                marqueeEl.style.width = w + "px";
                marqueeEl.style.height = h + "px";

                const marqueeRect = marqueeEl.getBoundingClientRect();
                const cards = document.querySelectorAll(".explorer-item-card");

                cards.forEach(card => {
                    if (card.offsetParent === null) return;
                    const cardRect = card.getBoundingClientRect();
                    const intersects = !(
                        marqueeRect.right < cardRect.left ||
                        marqueeRect.left > cardRect.right ||
                        marqueeRect.bottom < cardRect.top ||
                        marqueeRect.top > cardRect.bottom
                    );

                    const itemData = getItemDataFromRow(card);
                    if (!itemData) return;
                    if (intersects) {
                        selectedItemsMap.set(itemData.id, itemData);
                    } else if (!e.ctrlKey && !e.metaKey) {
                        selectedItemsMap.delete(itemData.id);
                    }
                });

                updateSelectionUI();
            }
        });

        window.addEventListener("mouseup", function () {
            if (isMarqueeDragging) {
                isMarqueeDragging = false;
                if (marqueeEl) {
                    marqueeEl.style.display = "none";
                }
            }
        });

        // 2. Right-Click Context Menu (Single & Bulk)
        document.addEventListener("contextmenu", function (e) {
            if (e.target.closest(".edms-modal-dialog, .edms-action-popover")) return;
            const clickedCard = e.target.closest(".explorer-item-card");
            if (!clickedCard) return;

            e.preventDefault();
            isContextMenuActive = true;

            if (clickedCard) {
                const itemData = getItemDataFromRow(clickedCard);
                if (!selectedItemsMap.has(itemData.id)) {
                    selectedItemsMap.clear();
                    selectedItemsMap.set(itemData.id, itemData);
                    updateSelectionUI();
                }
            }

            const count = selectedItemsMap.size;
            if (count === 0) {
                hidePopover();
                return;
            }

            if (count > 1) {
                if (popoverHeaderLabel) {
                    popoverHeaderLabel.style.display = "block";
                    popoverHeaderLabel.textContent = `${count} items selected`;
                }
                if (popoverView) popoverView.style.display = "none";
                if (popoverDownload) popoverDownload.style.display = "none";
                if (popoverCopyLink) popoverCopyLink.style.display = "none";
                if (popoverAuditHistory) popoverAuditHistory.style.display = "none";
                if (popoverRename) popoverRename.style.display = "none";
                if (popoverActionMoveLabel) popoverActionMoveLabel.textContent = `Move ${count} items...`;
                if (popoverActionDeleteLabel) popoverActionDeleteLabel.textContent = `Trash (${count})`;
                if (popoverDelete) popoverDelete.style.color = "";
            } else {
                const singleItem = Array.from(selectedItemsMap.values())[0];
                currentActiveItem = singleItem;

                if (popoverHeaderLabel) popoverHeaderLabel.style.display = "none";
                if (popoverView) {
                    popoverView.style.display = singleItem.type === "document" ? "flex" : "none";
                    popoverView.href = "view.php?id=" + encodeURIComponent(singleItem.id);
                    popoverView.onclick = () => hidePopover();
                }
                if (popoverDownload) {
                    popoverDownload.style.display = singleItem.type === "document" ? "flex" : "none";
                    popoverDownload.href = "download.php?id=" + encodeURIComponent(singleItem.id);
                    popoverDownload.onclick = () => hidePopover();
                }
                if (popoverCopyLink) {
                    popoverCopyLink.style.display = "flex";
                }
                if (popoverAuditHistory) {
                    popoverAuditHistory.style.display = singleItem.type === "document" ? "flex" : "none";
                }
                const isObsolete = (singleItem.status === "obsolete");
                if (popoverRename) popoverRename.style.display = isObsolete ? "none" : "flex";
                if (popoverMove) popoverMove.style.display = isObsolete ? "none" : "flex";
                if (popoverActionMoveLabel) popoverActionMoveLabel.textContent = "Move to...";
                if (popoverActionDeleteLabel) popoverActionDeleteLabel.textContent = isObsolete ? "Restore" : "Trash";
                if (popoverDelete) popoverDelete.style.color = isObsolete ? "var(--green)" : "";
            }

            // Position Context Popover at cursor with boundary safety
            popover.style.display = "flex";
            const popoverWidth = 185;
            let left = e.clientX;
            let top = e.clientY;

            if (left + popoverWidth > window.innerWidth) {
                left = window.innerWidth - popoverWidth - 10;
            }
            if (top + 220 > window.innerHeight) {
                top = window.innerHeight - 220;
            }

            popover.style.left = Math.max(10, left) + "px";
            popover.style.top = Math.max(10, top) + "px";
        });
    }

    // Initialize on DOM Ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initExplorerAjax);
    } else {
        initExplorerAjax();
    }
})();

