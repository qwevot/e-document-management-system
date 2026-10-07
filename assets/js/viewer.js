/**
 * EDMS Fullscreen Continuous Document Viewer & Multi-Layer Annotation Engine
 * Synchronizes and persists multi-user annotations to MySQL database
 */

document.addEventListener("DOMContentLoaded", function () {
    const config = window.EDMS_WORKSPACE_CONFIG || {};
    const docId = config.docId;
    const currentUserId = config.currentUserId || 1;
    const currentUserName = config.currentUserName || "You";
    
    // Configure PDF.js Worker
    if (window.pdfjsLib) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = "../assets/vendor/pdfjs/pdf.worker.min.js";
    }

    // DOM Elements
    const workspaceCenterStage = document.getElementById("workspaceCenterStage");
    const workspaceDocContainer = document.getElementById("workspaceDocContainer");
    const pdfPagesFeed = document.getElementById("pdfPagesFeed");
    const workspaceDocLoading = document.getElementById("workspaceDocLoading");
    const loadingStatusText = document.getElementById("loadingStatusText");
    
    // Zoom & Page Controls
    const btnZoomIn = document.getElementById("btnZoomIn");
    const btnZoomOut = document.getElementById("btnZoomOut");
    const btnZoomFit = document.getElementById("btnZoomFit");
    const btnZoomReset = document.getElementById("btnZoomReset");
    const zoomLevelDisplay = document.getElementById("zoomLevelDisplay");
    const pageIndicatorDisplay = document.getElementById("pageIndicatorDisplay");
    const btnPrevPage = document.getElementById("btnPrevPage");
    const btnNextPage = document.getElementById("btnNextPage");
    
    // Tool Palette
    const dockButtons = document.querySelectorAll(".floating-tool-dock .dock-btn[data-tool]");
    const colorSwatches = document.querySelectorAll(".tool-color-palette .color-swatch");
    const strokeButtons = document.querySelectorAll(".tool-stroke-group .stroke-btn");
    const btnUndoStroke = document.getElementById("btnUndoStroke");
    const btnClearMyLayer = document.getElementById("btnClearMyLayer");
    
    // Right Drawer Elements
    const workspaceRightDrawer = document.getElementById("workspaceRightDrawer");
    const btnToggleLayerDrawer = document.getElementById("btnToggleLayerDrawer");
    const btnCloseRightDrawer = document.getElementById("btnCloseRightDrawer");
    const selfStrokeCount = document.getElementById("selfStrokeCount");
    const btnToggleSelfLayer = document.getElementById("btnToggleSelfLayer");
    const layerOpacitySlider = document.getElementById("layerOpacitySlider");
    const opacityDisplayVal = document.getElementById("opacityDisplayVal");
    const layersList = document.getElementById("layersList");
    const annotationHistoryFeed = document.getElementById("annotationHistoryFeed");
    const historyCountBadge = document.getElementById("historyCountBadge");
    const btnToggleHistoryAccordion = document.getElementById("btnToggleHistoryAccordion");

    // --- State Management ---
    let pdfDocument = null;
    let totalPages = 0;
    let currentVisiblePage = 1;
    let baseScale = 1.25; // Default rendering scale
    let currentZoomPercent = 100; // 100%
    let activeTool = "pen"; // "pan", "pen", "highlighter", "text"
    let activeColor = "#16a34a"; // Default Emerald
    let activeStrokeWidth = 2;
    let layerOpacity = 1.0;
    let annotationHistoryList = [];
    
    // Active Drawing State
    let isDrawing = false;
    let activeDrawingPage = 1;
    let currentStroke = null;

    // Multi-User Layer Store: Keyed by user ID
    // { [userId]: { layerId, userId, name, color, visible: true, isSelf: bool, strokes: [] } }
    let annotationLayers = {
        [currentUserId]: {
            layerId: null,
            userId: currentUserId,
            name: currentUserName,
            color: activeColor,
            visible: true,
            isSelf: true,
            strokes: []
        }
    };

    // --- 1. Fetch & Hydrate Saved Database Annotations ---
    function fetchDatabaseAnnotations() {
        fetch(`view.php?action=get_annotations&id=${docId}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.layers) {
                // Merge database layers
                Object.keys(data.layers).forEach(uid => {
                    const dbLayer = data.layers[uid];
                    annotationLayers[uid] = {
                        layerId: dbLayer.layerId,
                        userId: dbLayer.userId,
                        name: dbLayer.name,
                        color: dbLayer.color || "#16a34a",
                        visible: dbLayer.visible !== false,
                        isSelf: parseInt(uid, 10) === parseInt(currentUserId, 10),
                        strokes: Array.isArray(dbLayer.strokes) ? dbLayer.strokes : []
                    };
                });

                // Ensure current user layer exists
                if (!annotationLayers[currentUserId]) {
                    annotationLayers[currentUserId] = {
                        layerId: null,
                        userId: currentUserId,
                        name: currentUserName,
                        color: activeColor,
                        visible: true,
                        isSelf: true,
                        strokes: []
                    };
                }

                // Load annotation history logs
                if (data.history && Array.isArray(data.history)) {
                    annotationHistoryList = data.history;
                    renderAnnotationHistory();
                }

                updateCollaboratorLayersUI();
                renderAllPagesAnnotations();
            }
        })
        .catch(err => console.error("Error loading saved annotations:", err));
    }

    // --- 2. Load Document (PDF via PDF.js vs DOCX via docx-preview) ---
    const streamUrl = config.streamUrl || `stream.php?id=${config.docId}`;
    const fileExt = (config.fileExt || "pdf").toLowerCase().replace(/^\./, "");
    const isDocx = fileExt === "docx" || fileExt === "doc";

    if (isDocx) {
        loadDocxDocument();
    } else if (window.pdfjsLib) {
        pdfjsLib.getDocument({
            url: streamUrl,
            cMapUrl: "https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/",
            cMapPacked: true
        }).promise
        .then(pdf => {
            pdfDocument = pdf;
            totalPages = pdf.numPages;
            updatePageIndicator(1, totalPages);
            renderAllPdfPages().then(() => {
                fetchDatabaseAnnotations();
            });
        })
        .catch(err => {
            console.error("PDF.js loading error:", err);
            if (loadingStatusText) {
                loadingStatusText.textContent = "Unable to preview document in canvas. Falling back...";
            }
            if (workspaceDocLoading) workspaceDocLoading.classList.add("is-hidden");
        });
    }

    // --- 2b. DOCX Client-Side Rendering Engine ---
    async function loadDocxDocument() {
        if (!pdfPagesFeed) return;
        if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        if (loadingStatusText) loadingStatusText.textContent = "Rendering Word document...";

        try {
            const response = await fetch(streamUrl);
            const docxBlob = await response.blob();

            // Clear container
            pdfPagesFeed.innerHTML = "";

            // Create temporary container for docx-preview to render into
            const docxWrapper = document.createElement("div");
            docxWrapper.className = "docx-render-host";
            pdfPagesFeed.appendChild(docxWrapper);

            // Render DOCX with docx-preview
            if (typeof docx !== "undefined" && docx.renderAsync) {
                await docx.renderAsync(docxBlob, docxWrapper, null, {
                    className: "docx",
                    inWrapper: true,
                    ignoreWidth: false,
                    ignoreHeight: false,
                    breakPages: true,
                    experimental: true
                });
            } else {
                throw new Error("docx-preview library is unavailable.");
            }

            // docx-preview generates article/section elements representing pages
            let docxSections = docxWrapper.querySelectorAll("section, .docx-wrapper > section, article");
            if (!docxSections || docxSections.length === 0) {
                docxSections = [docxWrapper];
            }

            totalPages = docxSections.length;
            updatePageIndicator(1, totalPages);

            const dpr = window.devicePixelRatio || 1;

            // Transform each DOCX section into an annotatable page wrapper
            docxSections.forEach((sec, idx) => {
                const pageNum = idx + 1;
                sec.style.position = "relative";
                sec.classList.add("pdf-page-wrapper");
                sec.id = `pageWrapper_${pageNum}`;
                sec.dataset.page = pageNum;

                const secRect = sec.getBoundingClientRect();
                const secW = Math.max(sec.offsetWidth || 816, 600);
                const secH = Math.max(sec.offsetHeight || 1056, 800);

                // Transparent Annotation Overlay Canvas
                const annoCanvas = document.createElement("canvas");
                annoCanvas.className = "pdf-annotation-canvas";
                annoCanvas.id = `annoCanvas_${pageNum}`;
                annoCanvas.dataset.page = pageNum;
                annoCanvas.width = Math.round(secW * dpr);
                annoCanvas.height = Math.round(secH * dpr);
                annoCanvas.style.width = secW + "px";
                annoCanvas.style.height = secH + "px";
                annoCanvas.style.opacity = layerOpacity.toString();

                attachDrawingListeners(annoCanvas, pageNum);
                sec.appendChild(annoCanvas);
            });

            if (workspaceDocLoading) workspaceDocLoading.classList.add("is-hidden");
            setupScrollObserver();
            fetchDatabaseAnnotations();
        } catch (err) {
            console.error("DOCX rendering error:", err);
            if (loadingStatusText) {
                loadingStatusText.textContent = "Failed to render Word document: " + (err.message || err);
            }
            if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        }
    }


    // --- 3. Render All Pages Sequentially in Continuous Scroll ---
    async function renderAllPdfPages() {
        if (!pdfDocument || !pdfPagesFeed) return;

        if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        if (loadingStatusText) loadingStatusText.textContent = "Rendering specimen pages...";

        pdfPagesFeed.innerHTML = "";
        const dpr = window.devicePixelRatio || 1;
        const scale = (currentZoomPercent / 100) * baseScale;

        for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
            const page = await pdfDocument.getPage(pageNum);
            const viewport = page.getViewport({ scale: scale });

            // Create Page Container
            const pageWrapper = document.createElement("div");
            pageWrapper.className = "pdf-page-wrapper";
            pageWrapper.id = `pageWrapper_${pageNum}`;
            pageWrapper.dataset.page = pageNum;
            pageWrapper.style.width = Math.round(viewport.width) + "px";
            pageWrapper.style.height = Math.round(viewport.height) + "px";

            // Base Document Render Canvas
            const baseCanvas = document.createElement("canvas");
            baseCanvas.className = "pdf-base-canvas";
            baseCanvas.id = `baseCanvas_${pageNum}`;
            baseCanvas.width = Math.round(viewport.width * dpr);
            baseCanvas.height = Math.round(viewport.height * dpr);
            baseCanvas.style.width = Math.round(viewport.width) + "px";
            baseCanvas.style.height = Math.round(viewport.height) + "px";

            const baseCtx = baseCanvas.getContext("2d");
            baseCtx.setTransform(dpr, 0, 0, dpr, 0, 0);

            // Transparent Synchronized Annotation Overlay Canvas
            const annoCanvas = document.createElement("canvas");
            annoCanvas.className = "pdf-annotation-canvas";
            annoCanvas.id = `annoCanvas_${pageNum}`;
            annoCanvas.dataset.page = pageNum;
            annoCanvas.width = Math.round(viewport.width * dpr);
            annoCanvas.height = Math.round(viewport.height * dpr);
            annoCanvas.style.width = Math.round(viewport.width) + "px";
            annoCanvas.style.height = Math.round(viewport.height) + "px";
            annoCanvas.style.opacity = layerOpacity.toString();

            // Bind Drawing Listeners to this page canvas
            attachDrawingListeners(annoCanvas, pageNum);

            pageWrapper.appendChild(baseCanvas);
            pageWrapper.appendChild(annoCanvas);
            pdfPagesFeed.appendChild(pageWrapper);

            // Render PDF Page onto base canvas
            const renderContext = {
                canvasContext: baseCtx,
                viewport: viewport
            };
            await page.render(renderContext).promise;

            // Composite any existing annotations on this page
            renderPageAnnotations(pageNum);
        }

        if (workspaceDocLoading) workspaceDocLoading.classList.add("is-hidden");
        setupScrollObserver();
    }

    // --- 4. Synchronized Per-Page Drawing & Object Manipulation Engine ---
    let selectedAnnotation = null;
    let isDraggingObject = false;
    let dragStartCoords = null;
    let dragInitialPoints = null;
    let dragInitialTextPos = null;
    let hasDragged = false;

    function attachDrawingListeners(canvas, pageNum) {
        canvas.addEventListener("mousedown", (e) => handleStartDrawing(e, canvas, pageNum));
        canvas.addEventListener("mousemove", (e) => handleDrawMove(e, canvas, pageNum));
        window.addEventListener("mouseup", handleStopDrawing);

        canvas.addEventListener("touchstart", (e) => handleStartDrawing(e, canvas, pageNum), { passive: true });
        canvas.addEventListener("touchmove", (e) => handleDrawMove(e, canvas, pageNum), { passive: true });
        window.addEventListener("touchend", handleStopDrawing);
    }

    function getRelativePageCoords(e, canvas) {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;

        return {
            xRel: Math.max(0, Math.min(1, (clientX - rect.left) / rect.width)),
            yRel: Math.max(0, Math.min(1, (clientY - rect.top) / rect.height))
        };
    }

    // Geometry Helper: Distance from Point (px, py) to Line Segment (x1, y1) -> (x2, y2)
    function distToSegment(px, py, x1, y1, x2, y2) {
        const l2 = (x2 - x1) * (x2 - x1) + (y2 - y1) * (y2 - y1);
        if (l2 === 0) return Math.hypot(px - x1, py - y1);
        let t = ((px - x1) * (x2 - x1) + (py - y1) * (y2 - y1)) / l2;
        t = Math.max(0, Math.min(1, t));
        return Math.hypot(px - (x1 + t * (x2 - x1)), py - (y1 + t * (y2 - y1)));
    }

    // Hit-Testing: Check if an annotation on pageNum is under cursor
    function hitTestAnnotation(pageNum, xRel, yRel, canvasWidth, canvasHeight) {
        const selfLayer = annotationLayers[currentUserId];
        if (!selfLayer || !selfLayer.visible || !Array.isArray(selfLayer.strokes)) return null;

        const pixelX = xRel * canvasWidth;
        const pixelY = yRel * canvasHeight;

        // Iterate top-to-bottom
        for (let i = selfLayer.strokes.length - 1; i >= 0; i--) {
            const item = selfLayer.strokes[i];
            if (item.page !== pageNum) continue;

            if (item.tool === "text") {
                const posX = item.xRel * canvasWidth;
                const posY = item.yRel * canvasHeight;
                const lines = (item.content || "").split("\n");
                const lineHeight = 18;
                const padding = 8;
                let maxW = 40;
                lines.forEach(l => { maxW = Math.max(maxW, l.length * 8.5 + padding * 2); });
                const boxW = maxW;
                const boxH = lines.length * lineHeight + padding * 2;

                if (pixelX >= posX - 4 && pixelX <= posX + boxW + 4 && pixelY >= posY - 4 && pixelY <= posY + boxH + 4) {
                    return item;
                }
            } else if (item.points && item.points.length >= 2) {
                const tol = Math.max(10, (item.width || 2) * 2.0);
                for (let j = 0; j < item.points.length - 1; j++) {
                    const p1 = item.points[j];
                    const p2 = item.points[j + 1];
                    const d = distToSegment(
                        pixelX, pixelY,
                        p1.xRel * canvasWidth, p1.yRel * canvasHeight,
                        p2.xRel * canvasWidth, p2.yRel * canvasHeight
                    );
                    if (d <= tol) {
                        return item;
                    }
                }
            }
        }
        return null;
    }

    function handleStartDrawing(e, canvas, pageNum) {
        const selfLayer = annotationLayers[currentUserId];
        if (!selfLayer || !selfLayer.visible) return;

        const rect = canvas.getBoundingClientRect();
        const coords = getRelativePageCoords(e, canvas);

        // --- SELECT & MOVE (HAND TOOL) ---
        if (activeTool === "pan") {
            const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height);
            if (hit) {
                selectedAnnotation = hit;
                isDraggingObject = true;
                hasDragged = false;
                activeDrawingPage = pageNum;
                dragStartCoords = coords;

                if (hit.tool === "text") {
                    dragInitialTextPos = { xRel: hit.xRel, yRel: hit.yRel };
                } else if (hit.points) {
                    dragInitialPoints = hit.points.map(pt => ({ xRel: pt.xRel, yRel: pt.yRel }));
                }

                canvas.style.cursor = "grabbing";
                renderPageAnnotations(pageNum);
            } else {
                selectedAnnotation = null;
                renderAllPagesAnnotations();
            }
            return;
        }

        // --- DRAWING / TEXT TOOLS ---
        selectedAnnotation = null;
        isDrawing = true;
        activeDrawingPage = pageNum;

        if (activeTool === "text") {
            createInlineTextInput(e, canvas, pageNum);
            isDrawing = false;
            return;
        }

        currentStroke = {
            id: null,
            page: pageNum,
            tool: activeTool,
            color: activeColor,
            width: activeTool === "highlighter" ? activeStrokeWidth * 3.5 : activeStrokeWidth,
            alpha: activeTool === "highlighter" ? 0.35 : 1.0,
            points: [coords]
        };

        selfLayer.strokes.push(currentStroke);
        updateStrokeStats();
    }

    function handleDrawMove(e, canvas, pageNum) {
        const rect = canvas.getBoundingClientRect();
        const coords = getRelativePageCoords(e, canvas);

        // --- DRAGGING SELECTED OBJECT (HAND TOOL) ---
        if (activeTool === "pan") {
            if (isDraggingObject && selectedAnnotation) {
                const dx = coords.xRel - dragStartCoords.xRel;
                const dy = coords.yRel - dragStartCoords.yRel;
                hasDragged = true;

                if (selectedAnnotation.tool === "text" && dragInitialTextPos) {
                    selectedAnnotation.xRel = Math.max(0, Math.min(0.95, dragInitialTextPos.xRel + dx));
                    selectedAnnotation.yRel = Math.max(0, Math.min(0.95, dragInitialTextPos.yRel + dy));
                } else if (selectedAnnotation.points && dragInitialPoints) {
                    selectedAnnotation.points.forEach((pt, idx) => {
                        pt.xRel = Math.max(0, Math.min(1, dragInitialPoints[idx].xRel + dx));
                        pt.yRel = Math.max(0, Math.min(1, dragInitialPoints[idx].yRel + dy));
                    });
                }

                renderPageAnnotations(pageNum);
            } else {
                // Hover hit-test to change cursor
                const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height);
                canvas.style.cursor = hit ? "grab" : "default";
            }
            return;
        }

        // --- DRAWING FREEHAND / HIGHLIGHTER ---
        if (!isDrawing || !currentStroke || activeDrawingPage !== pageNum) return;
        currentStroke.points.push(coords);
        renderPageAnnotations(pageNum);
    }

    function handleStopDrawing() {
        if (activeTool === "pan") {
            if (isDraggingObject) {
                isDraggingObject = false;
                const canvas = document.getElementById(`annoCanvas_${activeDrawingPage}`);
                if (canvas) canvas.style.cursor = "grab";

                if (hasDragged && selectedAnnotation) {
                    persistStrokeToDatabase(selectedAnnotation);
                }
            }
            return;
        }

        if (!isDrawing) return;
        isDrawing = false;
        const finishedPage = activeDrawingPage;
        const strokeToSave = currentStroke;
        currentStroke = null;

        if (finishedPage && strokeToSave) {
            renderPageAnnotations(finishedPage);
            persistStrokeToDatabase(strokeToSave);
        }
    }

    // Keyboard Handler: Delete selected object on Backspace / Delete
    window.addEventListener("keydown", function (e) {
        if ((e.key === "Delete" || e.key === "Backspace") && selectedAnnotation) {
            // Ignore if user is currently typing in an input
            if (e.target && (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA")) return;

            e.preventDefault();
            const selfLayer = annotationLayers[currentUserId];
            if (!selfLayer) return;

            const idx = selfLayer.strokes.indexOf(selectedAnnotation);
            if (idx !== -1) {
                const deleted = selfLayer.strokes.splice(idx, 1)[0];
                updateStrokeStats();
                if (deleted && deleted.page) {
                    renderPageAnnotations(deleted.page);
                }
                if (deleted && deleted.id) {
                    fetch(`view.php?action=delete_stroke&id=${docId}`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                        body: JSON.stringify({ id: deleted.id })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.history_event) {
                            prependHistoryEvent(data.history_event);
                        }
                    })
                    .catch(err => console.error("Error deleting stroke:", err));
                }
            }
            selectedAnnotation = null;
        }
    });

    // --- Interactive In-Place Text Box Engine ---
    let activeInlineInput = null;

    function createInlineTextInput(e, canvas, pageNum) {
        // Commit any previous uncommitted note
        commitActiveInlineInput();

        const pageWrapper = document.getElementById(`pageWrapper_${pageNum}`);
        if (!pageWrapper) return;

        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;

        const pixelX = Math.round(clientX - rect.left);
        const pixelY = Math.round(clientY - rect.top);
        const xRel = Math.max(0, Math.min(1, pixelX / rect.width));
        const yRel = Math.max(0, Math.min(1, pixelY / rect.height));

        const textarea = document.createElement("textarea");
        textarea.className = "inline-text-annotation-box";
        textarea.placeholder = "Type note...";
        textarea.rows = 1;
        textarea.style.left = `${pixelX}px`;
        textarea.style.top = `${pixelY}px`;
        textarea.style.setProperty("--anno-color", activeColor);

        activeInlineInput = {
            element: textarea,
            pageNum: pageNum,
            xRel: xRel,
            yRel: yRel,
            color: activeColor,
            committed: false
        };

        // Auto-expand dynamically as user types
        textarea.addEventListener("input", function () {
            this.style.height = "auto";
            this.style.height = (this.scrollHeight) + "px";
        });

        // Keydown handling: Enter commits, Escape cancels, Shift+Enter new line
        textarea.addEventListener("keydown", function (ev) {
            if (ev.key === "Enter" && !ev.shiftKey) {
                ev.preventDefault();
                commitActiveInlineInput();
            } else if (ev.key === "Escape") {
                ev.preventDefault();
                cancelActiveInlineInput();
            }
        });

        // Auto-commit on blur
        textarea.addEventListener("blur", function () {
            setTimeout(() => {
                if (activeInlineInput && activeInlineInput.element === textarea) {
                    commitActiveInlineInput();
                }
            }, 120);
        });

        pageWrapper.appendChild(textarea);

        setTimeout(() => {
            textarea.focus();
        }, 30);
    }

    function commitActiveInlineInput() {
        if (!activeInlineInput || activeInlineInput.committed) return;
        activeInlineInput.committed = true;

        const { element, pageNum, xRel, yRel, color } = activeInlineInput;
        const text = element.value ? element.value.trim() : "";

        if (element.parentNode) {
            element.parentNode.removeChild(element);
        }
        activeInlineInput = null;

        if (text === "") return;

        const textAnnotation = {
            id: null,
            page: pageNum,
            tool: "text",
            content: text,
            color: color,
            xRel: xRel,
            yRel: yRel
        };

        const selfLayer = annotationLayers[currentUserId];
        if (selfLayer) {
            selfLayer.strokes.push(textAnnotation);
            updateStrokeStats();
            renderPageAnnotations(pageNum);
            persistStrokeToDatabase(textAnnotation);
        }
    }

    function cancelActiveInlineInput() {
        if (!activeInlineInput) return;
        if (activeInlineInput.element && activeInlineInput.element.parentNode) {
            activeInlineInput.element.parentNode.removeChild(activeInlineInput.element);
        }
        activeInlineInput = null;
    }

    // --- 5. Backend Persistence Calls ---
    function persistStrokeToDatabase(strokeObj) {
        fetch(`view.php?action=save_stroke&id=${docId}`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify(strokeObj)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.annotation_id) {
                strokeObj.id = data.annotation_id;
                if (data.layer_id && annotationLayers[currentUserId]) {
                    annotationLayers[currentUserId].layerId = data.layer_id;
                }
                if (data.history_event) {
                    prependHistoryEvent(data.history_event);
                }
            }
        })
        .catch(err => console.error("Error auto-saving annotation:", err));
    }

    // --- 6. Render Annotations for a Specific Page ---
    function renderPageAnnotations(pageNum) {
        const annoCanvas = document.getElementById(`annoCanvas_${pageNum}`);
        if (!annoCanvas) return;

        const ctx = annoCanvas.getContext("2d");
        const dpr = window.devicePixelRatio || 1;
        const rect = annoCanvas.getBoundingClientRect();
        const width = rect.width;
        const height = rect.height;

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        // Composite all visible collaborator layers on this page
        Object.keys(annotationLayers).forEach(layerKey => {
            const layer = annotationLayers[layerKey];
            if (!layer.visible || !Array.isArray(layer.strokes)) return;

            layer.strokes.forEach(stroke => {
                if (stroke.page !== pageNum) return;

                if (stroke.tool === "text") {
                    // Render In-Place Text Badge with Multi-line Support
                    const posX = stroke.xRel * width;
                    const posY = stroke.yRel * height;
                    const lines = (stroke.content || "").split("\n");

                    ctx.save();
                    ctx.font = "bold 13px 'Plus Jakarta Sans', sans-serif";
                    const lineHeight = 18;
                    const padding = 8;

                    let maxLineWidth = 0;
                    lines.forEach(l => {
                        const w = ctx.measureText(l).width;
                        if (w > maxLineWidth) maxLineWidth = w;
                    });

                    const boxWidth = Math.max(30, maxLineWidth + padding * 2);
                    const boxHeight = lines.length * lineHeight + padding * 2 - 4;

                    // Draw white card container with colored border & soft shadow
                    ctx.fillStyle = "#ffffff";
                    ctx.shadowColor = "rgba(0, 0, 0, 0.12)";
                    ctx.shadowBlur = 5;
                    ctx.shadowOffsetY = 2;
                    ctx.beginPath();
                    ctx.roundRect(posX, posY, boxWidth, boxHeight, 5);
                    ctx.fill();

                    // Render colored border
                    ctx.shadowColor = "transparent";
                    ctx.strokeStyle = stroke.color;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();

                    // Render colored text
                    ctx.fillStyle = stroke.color;
                    lines.forEach((lineText, idx) => {
                        ctx.fillText(lineText, posX + padding, posY + padding + 13 + (idx * lineHeight));
                    });

                    ctx.restore();
                    return;
                }

                // Freehand Brush / Highlighter
                const points = stroke.points;
                if (!points || points.length < 2) return;

                ctx.save();
                ctx.strokeStyle = stroke.color;
                ctx.lineWidth = stroke.width;
                ctx.lineCap = "round";
                ctx.lineJoin = "round";
                ctx.globalAlpha = stroke.alpha || 1.0;

                ctx.beginPath();
                ctx.moveTo(points[0].xRel * width, points[0].yRel * height);

                for (let i = 1; i < points.length; i++) {
                    const pt = points[i];
                    ctx.lineTo(pt.xRel * width, pt.yRel * height);
                }

                ctx.stroke();
                ctx.restore();
            });
        });

        // Render Selection Bounding Box & Handles if an annotation is selected on this page
        if (selectedAnnotation && selectedAnnotation.page === pageNum) {
            ctx.save();
            ctx.strokeStyle = "#2563eb";
            ctx.lineWidth = 1.5;
            ctx.setLineDash([4, 4]);

            let bMinX = 0, bMinY = 0, bMaxX = 0, bMaxY = 0;

            if (selectedAnnotation.tool === "text") {
                const posX = selectedAnnotation.xRel * width;
                const posY = selectedAnnotation.yRel * height;
                ctx.font = "bold 13px 'Plus Jakarta Sans', sans-serif";
                const lines = (selectedAnnotation.content || "").split("\n");
                let maxW = 0;
                lines.forEach(l => {
                    const w = ctx.measureText(l).width;
                    if (w > maxW) maxW = w;
                });
                const boxW = Math.max(30, maxW + 16);
                const boxH = lines.length * 18 + 12;

                bMinX = posX - 3;
                bMinY = posY - 3;
                bMaxX = posX + boxW + 3;
                bMaxY = posY + boxH + 3;
            } else if (selectedAnnotation.points && selectedAnnotation.points.length >= 2) {
                let minPx = Infinity, minPy = Infinity, maxPx = -Infinity, maxPy = -Infinity;
                selectedAnnotation.points.forEach(pt => {
                    const px = pt.xRel * width;
                    const py = pt.yRel * height;
                    if (px < minPx) minPx = px;
                    if (px > maxPx) maxPx = px;
                    if (py < minPy) minPy = py;
                    if (py > maxPy) maxPy = py;
                });
                const pad = Math.max(6, (selectedAnnotation.width || 2));
                bMinX = minPx - pad;
                bMinY = minPy - pad;
                bMaxX = maxPx + pad;
                bMaxY = maxPy + pad;
            }

            if (bMaxX > bMinX && bMaxY > bMinY) {
                // Dashed outline
                ctx.strokeRect(bMinX, bMinY, bMaxX - bMinX, bMaxY - bMinY);

                // Corner anchor points
                ctx.setLineDash([]);
                ctx.fillStyle = "#ffffff";
                ctx.strokeStyle = "#2563eb";
                ctx.lineWidth = 2;

                const corners = [
                    [bMinX, bMinY],
                    [bMaxX, bMinY],
                    [bMinX, bMaxY],
                    [bMaxX, bMaxY]
                ];

                corners.forEach(([cx, cy]) => {
                    ctx.beginPath();
                    ctx.arc(cx, cy, 4, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.stroke();
                });
            }

        }
    }

    // Helper to generate a clean offscreen canvas containing only the annotations for a specific page (no selection boxes)
    function generateCleanPageAnnotationsCanvas(pageNum, targetWidth, targetHeight) {
        const offscreen = document.createElement("canvas");
        offscreen.width = targetWidth;
        offscreen.height = targetHeight;
        const ctx = offscreen.getContext("2d");
        if (!ctx) return offscreen;

        Object.keys(annotationLayers).forEach(uid => {
            const layer = annotationLayers[uid];
            if (!layer.visible || !layer.strokes) return;

            layer.strokes.forEach(stroke => {
                if (stroke.page !== pageNum) return;

                if (stroke.tool === "text") {
                    const posX = stroke.xRel * targetWidth;
                    const posY = stroke.yRel * targetHeight;
                    const lines = (stroke.content || "").split("\n");

                    ctx.save();
                    ctx.font = "bold 13px 'Plus Jakarta Sans', sans-serif";
                    const lineHeight = 18;
                    const padding = 8;

                    let maxLineWidth = 0;
                    lines.forEach(l => {
                        const w = ctx.measureText(l).width;
                        if (w > maxLineWidth) maxLineWidth = w;
                    });

                    const boxWidth = Math.max(30, maxLineWidth + padding * 2);
                    const boxHeight = lines.length * lineHeight + padding * 2 - 4;

                    ctx.fillStyle = "#ffffff";
                    ctx.shadowColor = "rgba(0, 0, 0, 0.12)";
                    ctx.shadowBlur = 5;
                    ctx.shadowOffsetY = 2;
                    ctx.beginPath();
                    ctx.roundRect(posX, posY, boxWidth, boxHeight, 5);
                    ctx.fill();

                    ctx.shadowColor = "transparent";
                    ctx.strokeStyle = stroke.color;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();

                    ctx.fillStyle = stroke.color;
                    lines.forEach((lineText, idx) => {
                        ctx.fillText(lineText, posX + padding, posY + padding + 13 + (idx * lineHeight));
                    });

                    ctx.restore();
                    return;
                }

                const points = stroke.points;
                if (!points || points.length < 2) return;

                ctx.save();
                ctx.strokeStyle = stroke.color;
                ctx.lineWidth = stroke.width;
                ctx.lineCap = "round";
                ctx.lineJoin = "round";
                ctx.globalAlpha = (stroke.alpha || 1.0) * layerOpacity;

                ctx.beginPath();
                ctx.moveTo(points[0].xRel * targetWidth, points[0].yRel * targetHeight);

                for (let i = 1; i < points.length; i++) {
                    const pt = points[i];
                    ctx.lineTo(pt.xRel * targetWidth, pt.yRel * targetHeight);
                }

                ctx.stroke();
                ctx.restore();
            });
        });

        return offscreen;
    }


    function renderAllPagesAnnotations() {
        for (let p = 1; p <= totalPages; p++) {
            renderPageAnnotations(p);
        }
    }

    // --- 7. Collaborators UI in Right Drawer ---
    function updateCollaboratorLayersUI() {
        if (!layersList) return;
        layersList.innerHTML = "";

        Object.keys(annotationLayers).forEach(uid => {
            const layer = annotationLayers[uid];
            const isSelf = layer.isSelf;
            const strokeCount = layer.strokes ? layer.strokes.length : 0;

            const row = document.createElement("div");
            row.className = "layer-item" + (isSelf ? " is-active-user" : "");
            row.id = `layerRow_${uid}`;

            const eyeIconSvg = layer.visible
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

            row.innerHTML = `
                <div class="layer-meta">
                    <span class="layer-color-indicator" style="background-color: ${layer.color || '#16a34a'};"></span>
                    <div class="layer-name-info">
                        <span class="layer-author">${escapeHtml(layer.name)} ${isSelf ? '<small>(You)</small>' : ''}</span>
                        <span class="layer-stroke-stat" id="statCount_${uid}">${strokeCount} ${strokeCount === 1 ? 'annotation' : 'annotations'}</span>
                    </div>
                </div>
                <div class="layer-actions">
                    <button type="button" class="btn-layer-toggle ${layer.visible ? 'is-visible' : ''}" data-uid="${uid}" title="Toggle Layer Visibility">
                        ${eyeIconSvg}
                    </button>
                </div>
            `;

            const toggleBtn = row.querySelector(".btn-layer-toggle");
            toggleBtn.addEventListener("click", () => {
                layer.visible = !layer.visible;
                toggleBtn.classList.toggle("is-visible", layer.visible);
                toggleBtn.innerHTML = layer.visible
                    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                renderAllPagesAnnotations();
            });

            layersList.appendChild(row);
        });
    }

    // --- 7b. Annotation History & Audit Trail Feed ---
    function renderAnnotationHistory() {
        if (!annotationHistoryFeed) return;
        annotationHistoryFeed.innerHTML = "";

        if (historyCountBadge) {
            const count = annotationHistoryList.length;
            historyCountBadge.textContent = `${count} ${count === 1 ? 'event' : 'events'}`;
        }

        if (!annotationHistoryList || annotationHistoryList.length === 0) {
            annotationHistoryFeed.innerHTML = `
                <div class="history-empty-state">
                    <span>No annotation events recorded yet.</span>
                </div>
            `;
            return;
        }

        annotationHistoryList.forEach(item => {
            const row = document.createElement("div");
            row.className = "history-item";
            const pageNum = parseInt(item.page_number, 10) || null;
            if (pageNum) {
                row.title = `Click to jump to Page ${pageNum}`;
            }

            const isErase = item.action_type === "delete" || item.action_type === "clear";
            const actionClass = isErase ? "is-erase" : "is-write";
            const actionText = escapeHtml(item.details || (isErase ? 'Erased item' : 'Added item'));

            row.innerHTML = `
                <div class="history-main-line">
                    <span class="history-user">${escapeHtml(item.author_name || 'Collaborator')}</span>
                    <span class="history-action-text ${actionClass}">${actionText}</span>
                </div>
                <div class="history-meta-line">
                    <span class="history-time">${escapeHtml(item.formatted_time || 'Just now')}</span>
                    ${pageNum ? `<span class="history-page-tag">Page ${pageNum}</span>` : ''}
                </div>
            `;

            if (pageNum) {
                row.addEventListener("click", () => {
                    scrollToPage(pageNum);
                });
            }

            annotationHistoryFeed.appendChild(row);
        });
    }

    function prependHistoryEvent(evt) {
        if (!evt) return;
        annotationHistoryList.unshift(evt);
        if (annotationHistoryList.length > 50) {
            annotationHistoryList.pop();
        }
        renderAnnotationHistory();
    }

    // Accordion Dropdown Toggle for Annotation History
    if (btnToggleHistoryAccordion && annotationHistoryFeed) {
        btnToggleHistoryAccordion.addEventListener("click", function () {
            const isCurrentlyCollapsed = annotationHistoryFeed.classList.contains("is-collapsed");
            annotationHistoryFeed.classList.toggle("is-collapsed", !isCurrentlyCollapsed);
            this.classList.toggle("is-open", isCurrentlyCollapsed);
        });
    }

    // --- 8. Continuous Scroll & Page Navigation Observer ---
    function setupScrollObserver() {
        if (!window.IntersectionObserver || !workspaceCenterStage) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.4) {
                    const pageNum = parseInt(entry.target.dataset.page, 10);
                    if (pageNum) {
                        currentVisiblePage = pageNum;
                        updatePageIndicator(currentVisiblePage, totalPages);
                    }
                }
            });
        }, {
            root: workspaceCenterStage,
            threshold: [0.4, 0.7]
        });

        document.querySelectorAll(".pdf-page-wrapper").forEach(pageEl => {
            observer.observe(pageEl);
        });
    }

    function scrollToPage(pageNum) {
        const targetPage = document.getElementById(`pageWrapper_${pageNum}`);
        if (targetPage && workspaceCenterStage) {
            targetPage.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }

    if (btnNextPage) {
        btnNextPage.addEventListener("click", () => {
            if (currentVisiblePage < totalPages) {
                scrollToPage(currentVisiblePage + 1);
            }
        });
    }

    if (btnPrevPage) {
        btnPrevPage.addEventListener("click", () => {
            if (currentVisiblePage > 1) {
                scrollToPage(currentVisiblePage - 1);
            }
        });
    }

    function updatePageIndicator(curr, total) {
        if (pageIndicatorDisplay) {
            pageIndicatorDisplay.textContent = `Page ${curr} / ${total}`;
        }
    }

    // --- 9. Zoom Engine ---
    function setZoom(newZoom) {
        currentZoomPercent = Math.max(50, Math.min(250, newZoom));
        if (zoomLevelDisplay) zoomLevelDisplay.textContent = Math.round(currentZoomPercent) + "%";
        renderAllPdfPages();
    }

    if (btnZoomIn) btnZoomIn.addEventListener("click", () => setZoom(currentZoomPercent + 15));
    if (btnZoomOut) btnZoomOut.addEventListener("click", () => setZoom(currentZoomPercent - 15));
    if (btnZoomReset) btnZoomReset.addEventListener("click", () => setZoom(100));
    if (btnZoomFit) {
        btnZoomFit.addEventListener("click", () => {
            if (!workspaceCenterStage) return;
            const containerWidth = workspaceCenterStage.clientWidth - 80;
            const standardPageWidth = 595 * baseScale; // Default PDF standard width
            const fitPercent = Math.round((containerWidth / standardPageWidth) * 100);
            setZoom(fitPercent);
        });
    }

    // Intercept Keyboard Zoom (Ctrl + / Ctrl - / Ctrl 0)
    window.addEventListener("keydown", function (e) {
        if (e.ctrlKey || e.metaKey) {
            if (e.key === "=" || e.key === "+" || e.keyCode === 187 || e.keyCode === 107) {
                e.preventDefault();
                setZoom(currentZoomPercent + 15);
            } else if (e.key === "-" || e.key === "_" || e.keyCode === 189 || e.keyCode === 109) {
                e.preventDefault();
                setZoom(currentZoomPercent - 15);
            } else if (e.key === "0" || e.keyCode === 96 || e.keyCode === 48) {
                e.preventDefault();
                setZoom(100);
            }
        }
    }, { passive: false });

    // Intercept Mouse Wheel Zoom (Ctrl + Scroll Wheel)
    window.addEventListener("wheel", function (e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            if (e.deltaY < 0) {
                setZoom(currentZoomPercent + 10);
            } else if (e.deltaY > 0) {
                setZoom(currentZoomPercent - 10);
            }
        }
    }, { passive: false });

    // --- 10. Tool Palette Switching ---
    dockButtons.forEach(btn => {
        btn.addEventListener("click", function () {
            dockButtons.forEach(b => b.classList.remove("is-active"));
            this.classList.add("is-active");
            activeTool = this.dataset.tool || "pen";

            const annoCanvases = document.querySelectorAll(".pdf-annotation-canvas");
            annoCanvases.forEach(c => c.style.pointerEvents = "auto");

            if (activeTool === "pan") {
                if (workspaceCenterStage) workspaceCenterStage.style.cursor = "default";
            } else {
                if (workspaceCenterStage) workspaceCenterStage.style.cursor = "crosshair";
            }
        });
    });

    // Color Swatches
    colorSwatches.forEach(swatch => {
        swatch.addEventListener("click", function () {
            colorSwatches.forEach(s => s.classList.remove("is-selected"));
            this.classList.add("is-selected");
            activeColor = this.dataset.color || "#16a34a";
            if (annotationLayers[currentUserId]) {
                annotationLayers[currentUserId].color = activeColor;
            }
        });
    });

    // Stroke Widths
    strokeButtons.forEach(btn => {
        btn.addEventListener("click", function () {
            strokeButtons.forEach(b => b.classList.remove("is-active"));
            this.classList.add("is-active");
            activeStrokeWidth = parseInt(this.dataset.stroke, 10) || 2;
        });
    });

    // --- 11. Undo & Clear Actions ---
    if (btnUndoStroke) {
        btnUndoStroke.addEventListener("click", function () {
            const selfLayer = annotationLayers[currentUserId];
            if (selfLayer && selfLayer.strokes.length > 0) {
                const popped = selfLayer.strokes.pop();
                updateStrokeStats();
                if (popped && popped.page) {
                    renderPageAnnotations(popped.page);
                } else {
                    renderAllPagesAnnotations();
                }

                // Delete from backend database
                if (popped && popped.id) {
                    fetch(`view.php?action=delete_stroke&id=${docId}`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                        body: JSON.stringify({ id: popped.id })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.history_event) {
                            prependHistoryEvent(data.history_event);
                        }
                    })
                    .catch(err => console.error("Error deleting stroke:", err));
                }
            }
        });
    }

    if (btnClearMyLayer) {
        btnClearMyLayer.addEventListener("click", function () {
            const selfLayer = annotationLayers[currentUserId];
            if (!selfLayer || selfLayer.strokes.length === 0) return;

            if (confirm("Clear all your annotations on this document?")) {
                selfLayer.strokes = [];
                updateStrokeStats();
                renderAllPagesAnnotations();

                fetch(`view.php?action=clear_my_layer&id=${docId}`, {
                    method: "POST",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.history_event) {
                        prependHistoryEvent(data.history_event);
                    }
                })
                .catch(err => console.error("Error clearing annotations:", err));
            }
        });
    }

    function updateStrokeStats() {
        const selfLayer = annotationLayers[currentUserId];
        const count = selfLayer && selfLayer.strokes ? selfLayer.strokes.length : 0;
        const countSpan = document.getElementById(`statCount_${currentUserId}`);
        if (countSpan) {
            countSpan.textContent = count + (count === 1 ? " annotation" : " annotations");
        }
    }

    // --- 12. Layer Drawer & Visibility Controls ---
    if (btnToggleLayerDrawer) {
        btnToggleLayerDrawer.addEventListener("click", function () {
            workspaceRightDrawer.classList.toggle("is-open");
            btnToggleLayerDrawer.classList.toggle("is-active", workspaceRightDrawer.classList.contains("is-open"));
        });
    }

    if (btnCloseRightDrawer) {
        btnCloseRightDrawer.addEventListener("click", function () {
            workspaceRightDrawer.classList.remove("is-open");
            if (btnToggleLayerDrawer) btnToggleLayerDrawer.classList.remove("is-active");
        });
    }

    // Opacity Slider
    if (layerOpacitySlider) {
        layerOpacitySlider.addEventListener("input", function () {
            const val = this.value;
            layerOpacity = val / 100;
            if (opacityDisplayVal) opacityDisplayVal.textContent = val + "%";
            document.querySelectorAll(".pdf-annotation-canvas").forEach(canvas => {
                canvas.style.opacity = layerOpacity.toString();
            });
        });
    }

    // --- 13. Download Dropdown & Annotated PDF Export Engine ---
    const workspaceDownloadDropdown = document.getElementById("workspaceDownloadDropdown");
    const btnDownloadDropdownToggle = document.getElementById("btnDownloadDropdownToggle");
    const btnDownloadWithAnnotations = document.getElementById("btnDownloadWithAnnotations");

    if (btnDownloadDropdownToggle && workspaceDownloadDropdown) {
        btnDownloadDropdownToggle.addEventListener("click", function (e) {
            e.stopPropagation();
            workspaceDownloadDropdown.classList.toggle("is-open");
        });

        // Close dropdown when clicking outside
        document.addEventListener("click", function (e) {
            if (!workspaceDownloadDropdown.contains(e.target)) {
                workspaceDownloadDropdown.classList.remove("is-open");
            }
        });
    }

    // Helper: Export DOCX pages as PDF using html2canvas & pdf-lib
    async function exportDocxToPdf(withAnnotations = false) {
        let docxSections = document.querySelectorAll(
            ".docx-render-host .pdf-page-wrapper, " +
            ".pdf-pages-feed .pdf-page-wrapper, " +
            ".docx-render-host section, " +
            ".docx-render-host .docx-wrapper > section, " +
            "section.docx-document, " +
            "section.docx"
        );

        if (!docxSections || docxSections.length === 0) {
            // Fallback: look for the host wrapper itself if rendered as single article
            const hostWrapper = document.querySelector(".docx-render-host");
            if (hostWrapper) {
                docxSections = [hostWrapper];
            } else {
                throw new Error("No rendered Word pages found to export.");
            }
        }

        // Create fresh empty PDF document
        const pdfDoc = await PDFLib.PDFDocument.create();

        for (let i = 0; i < docxSections.length; i++) {
            const sec = docxSections[i];
            const pageNum = i + 1;
            const annoCanvas = document.getElementById(`annoCanvas_${pageNum}`);

            // Temporarily hide or ensure canvas visibility based on parameter
            const originalCanvasDisplay = annoCanvas ? annoCanvas.style.display : "";
            if (annoCanvas) {
                annoCanvas.style.display = withAnnotations ? "block" : "none";
            }

            // Capture rendered Word page section
            const canvasShot = await html2canvas(sec, {
                scale: 1.5,
                useCORS: true,
                logging: false,
                backgroundColor: "#ffffff"
            });

            if (annoCanvas) {
                annoCanvas.style.display = originalCanvasDisplay;
            }

            const imgData = canvasShot.toDataURL("image/jpeg", 0.92);
            const imgBytes = await fetch(imgData).then(res => res.arrayBuffer());
            const embeddedImg = await pdfDoc.embedJpg(imgBytes);

            // Add page with standard points dimensions
            const pdfPage = pdfDoc.addPage([canvasShot.width / 1.5, canvasShot.height / 1.5]);
            pdfPage.drawImage(embeddedImg, {
                x: 0,
                y: 0,
                width: pdfPage.getWidth(),
                height: pdfPage.getHeight(),
            });
        }

        return await pdfDoc.save();
    }

    // Export As PDF button (for DOCX)
    const btnDownloadAsPdf = document.getElementById("btnDownloadAsPdf");
    if (btnDownloadAsPdf) {
        btnDownloadAsPdf.addEventListener("click", async function (e) {
            e.preventDefault();
            if (workspaceDownloadDropdown) {
                workspaceDownloadDropdown.classList.remove("is-open");
            }

            const origButtonHtml = btnDownloadDropdownToggle.innerHTML;
            btnDownloadDropdownToggle.disabled = true;
            btnDownloadDropdownToggle.innerHTML = `
                <div class="viewer-spinner" style="width:14px; height:14px; border-width:2px; margin-right:4px;"></div>
                <span>Converting PDF...</span>
            `;

            try {
                const pdfBytes = await exportDocxToPdf(false);
                let baseDocName = (config.docName || "document").replace(/\.(docx|doc)$/i, "");
                const downloadFileName = `${baseDocName}.pdf`;

                const blob = new Blob([pdfBytes], { type: "application/pdf" });
                const blobUrl = URL.createObjectURL(blob);
                const downloadLink = document.createElement("a");
                downloadLink.href = blobUrl;
                downloadLink.download = downloadFileName;
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);

                setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);
            } catch (err) {
                console.error("Error converting DOCX to PDF:", err);
                alert("Failed to export PDF: " + (err.message || err));
            } finally {
                btnDownloadDropdownToggle.disabled = false;
                btnDownloadDropdownToggle.innerHTML = origButtonHtml;
            }
        });
    }

    if (btnDownloadWithAnnotations) {
        btnDownloadWithAnnotations.addEventListener("click", async function (e) {
            e.preventDefault();
            if (workspaceDownloadDropdown) {
                workspaceDownloadDropdown.classList.remove("is-open");
            }

            // Verify pdf-lib availability
            if (typeof PDFLib === "undefined" || !PDFLib.PDFDocument) {
                alert("PDF export engine is still loading. Please try again shortly.");
                return;
            }

            // Visual loading state
            const origButtonHtml = btnDownloadDropdownToggle.innerHTML;
            btnDownloadDropdownToggle.disabled = true;
            btnDownloadDropdownToggle.innerHTML = `
                <div class="viewer-spinner" style="width:14px; height:14px; border-width:2px; margin-right:4px;"></div>
                <span>Exporting...</span>
            `;

            try {
                if (isDocx) {
                    // Export DOCX with annotations
                    const pdfBytes = await exportDocxToPdf(true);
                    let baseDocName = (config.docName || "document").replace(/\.(docx|doc)$/i, "");
                    const downloadFileName = `${baseDocName}_annotated.pdf`;

                    const blob = new Blob([pdfBytes], { type: "application/pdf" });
                    const blobUrl = URL.createObjectURL(blob);
                    const downloadLink = document.createElement("a");
                    downloadLink.href = blobUrl;
                    downloadLink.download = downloadFileName;
                    document.body.appendChild(downloadLink);
                    downloadLink.click();
                    document.body.removeChild(downloadLink);

                    setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);
                } else {
                    // Export Native PDF with annotations
                    const streamUrl = config.streamUrl || `stream.php?id=${config.docId}`;
                    const existingPdfBytes = await fetch(streamUrl).then(res => res.arrayBuffer());

                    // Load via PDF-Lib
                    const pdfDoc = await PDFLib.PDFDocument.load(existingPdfBytes);
                    const pages = pdfDoc.getPages();

                    // Loop through all pages and composite canvas overlays
                    for (let i = 0; i < pages.length; i++) {
                        const pageNum = i + 1;
                        const pdfPage = pages[i];
                        const { width: pageWidth, height: pageHeight } = pdfPage.getSize();

                        const exportScale = 2;
                        const canvasW = Math.round(pageWidth * exportScale);
                        const canvasH = Math.round(pageHeight * exportScale);

                        const offscreenCanvas = generateCleanPageAnnotationsCanvas(pageNum, canvasW, canvasH);

                        let hasAnnotations = false;
                        Object.keys(annotationLayers).forEach(uid => {
                            const layer = annotationLayers[uid];
                            if (layer.visible && layer.strokes && layer.strokes.some(s => s.page === pageNum)) {
                                hasAnnotations = true;
                            }
                        });

                        if (!hasAnnotations) continue;

                        const pngDataUrl = offscreenCanvas.toDataURL("image/png");
                        const pngImageBytes = await fetch(pngDataUrl).then(res => res.arrayBuffer());

                        const embeddedImage = await pdfDoc.embedPng(pngImageBytes);
                        pdfPage.drawImage(embeddedImage, {
                            x: 0,
                            y: 0,
                            width: pageWidth,
                            height: pageHeight,
                        });
                    }

                    const modifiedPdfBytes = await pdfDoc.save();

                    let baseDocName = (config.docName || "document").replace(/\.pdf$/i, "");
                    const downloadFileName = `${baseDocName}_annotated.pdf`;

                    const blob = new Blob([modifiedPdfBytes], { type: "application/pdf" });
                    const blobUrl = URL.createObjectURL(blob);
                    const downloadLink = document.createElement("a");
                    downloadLink.href = blobUrl;
                    downloadLink.download = downloadFileName;
                    document.body.appendChild(downloadLink);
                    downloadLink.click();
                    document.body.removeChild(downloadLink);

                    setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);
                }
            } catch (err) {
                console.error("Error generating annotated PDF:", err);
                alert("Failed to export annotated document: " + (err.message || err));
            } finally {
                btnDownloadDropdownToggle.disabled = false;
                btnDownloadDropdownToggle.innerHTML = origButtonHtml;
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }
});
