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
    const btnAddLayerGroup = document.getElementById("btnAddLayerGroup");
    
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
    const btnToggleAuditHistoryDropdown = document.getElementById("btnToggleAuditHistoryDropdown");
    const auditHistoryFeed = document.getElementById("auditHistoryFeed");
    const auditHistoryCountBadge = document.getElementById("auditHistoryCountBadge");
    // Annotation Lock Rule:
    // If a document is PUBLISHED, or viewing a historical snapshot, or user is not a contributor:
    const isDocPublished = Boolean(config.isDocPublished || config.docStatus === 'published');
    const isHistoricalSnapshot = Boolean(config.isHistoricalSnapshot);
    const canAnnotate = (typeof config.canAnnotate !== 'undefined') ? Boolean(config.canAnnotate) : true;
    const isAnnotationLocked = isDocPublished || isHistoricalSnapshot || !canAnnotate;

    function getAnnotationLockMessage() {
        if (config.docStatus === 'rejected') return "Revision rejected. Annotations are locked until a new revision is uploaded.";
        if (isDocPublished) return "Document is Published. Annotations are locked.";
        if (isHistoricalSnapshot) return "Viewing historical snapshot (read-only).";
        if (!canAnnotate) return "You have read-only access (Contributor permissions required).";
        return "Annotations are locked.";
    }

    // --- State Management ---
    let pdfDocument = null;
    let totalPages = 0;
    let currentVisiblePage = 1;
    let baseScale = 1.25; // Default rendering scale
    let currentZoomPercent = 100; // 100%
    let activeTool = isAnnotationLocked ? "pan" : "pen"; // If locked, force "pan"
    let activeColor = "#16a34a"; // Default Emerald
    let activeStrokeWidth = 2;
    let layerOpacity = 1.0;
    let annotationHistoryList = [];
    
    // Active Drawing State
    let isDrawing = false;
    let activeDrawingPage = 1;
    let currentStroke = null;

    // Multi-Layer Store: Keyed by layerId
    // { [layerId]: { layerId, userId, userName, layerName, color, visible: true, isSelf: bool, strokes: [] } }
    let annotationLayers = {};
    let activeLayerId = null;
    const versionQueryParam = config.activeVersionId ? `&version_id=${config.activeVersionId}` : '';

    // Helper: Get currently active layer object for drawing
    function getActiveSelfLayer() {
        if (!canAnnotate || isAnnotationLocked) return null;
        if (activeLayerId && annotationLayers[activeLayerId]) {
            return annotationLayers[activeLayerId];
        }
        // Fallback: Find the latest layer belonging to current user
        const selfLayerIds = Object.keys(annotationLayers).filter(lid => annotationLayers[lid].isSelf);
        if (selfLayerIds.length > 0) {
            activeLayerId = parseInt(selfLayerIds[selfLayerIds.length - 1], 10);
            return annotationLayers[activeLayerId];
        }
        return null;
    }

    // --- 1. Fetch & Hydrate Saved Database Annotations ---
    function fetchDatabaseAnnotations() {
        fetch(`view.php?action=get_annotations&id=${docId}${versionQueryParam}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.layers)) {
                annotationLayers = {};
                data.layers.forEach(layerItem => {
                    const lid = layerItem.layerId;
                    annotationLayers[lid] = {
                        layerId: lid,
                        userId: layerItem.userId,
                        userName: layerItem.userName,
                        userAvatar: layerItem.userAvatar || null,
                        layerName: layerItem.layerName || "Layer 1",
                        color: layerItem.color || "#16a34a",
                        visible: layerItem.visible !== false,
                        isSelf: Boolean(layerItem.isSelf),
                        strokes: Array.isArray(layerItem.strokes) ? layerItem.strokes : []
                    };
                });

                // Set initial activeLayerId to the most recent personal layer if user is allowed to annotate
                if (canAnnotate && !isAnnotationLocked) {
                    if (!activeLayerId || !annotationLayers[activeLayerId] || !annotationLayers[activeLayerId].isSelf) {
                        const selfLayers = Object.values(annotationLayers).filter(l => l.isSelf);
                        if (selfLayers.length > 0) {
                            activeLayerId = selfLayers[selfLayers.length - 1].layerId;
                        }
                    }
                } else {
                    activeLayerId = null;
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

    // --- 2. Load Document (PDF vs DOCX Fallback vs Image) ---
    const streamUrl = config.streamUrl || `stream.php?id=${config.docId}`;
    const fileExt = (config.fileExt || "pdf").toLowerCase().replace(/^\./, "");
    const imageExtensions = ["png", "jpg", "jpeg", "webp", "gif", "svg", "bmp", "ico"];
    const isImage = imageExtensions.includes(fileExt);
    const renderMode = config.renderMode || (isImage ? "image" : (fileExt === "pdf" ? "pdf" : "docx_fallback"));

    let loadedImageObj = null;

    if (renderMode === "image") {
        loadImageDocument();
    } else if (renderMode === "docx_fallback") {
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

    // --- 2a. Image Client-Side Rendering Engine ---
    async function loadImageDocument() {
        if (!pdfPagesFeed) return;
        if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        if (loadingStatusText) loadingStatusText.textContent = "Loading image specimen...";

        try {
            const img = new Image();
            img.crossOrigin = "anonymous";
            await new Promise((resolve, reject) => {
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error("Unable to load image file."));
                img.src = streamUrl;
            });

            loadedImageObj = img;
            totalPages = 1;
            updatePageIndicator(1, 1);
            renderImagePage();
            fetchDatabaseAnnotations();
        } catch (err) {
            console.error("Image loading error:", err);
            if (loadingStatusText) {
                loadingStatusText.textContent = "Failed to load image: " + (err.message || err);
            }
            if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        }
    }

    function renderImagePage() {
        if (!loadedImageObj || !pdfPagesFeed) return;
        if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");

        pdfPagesFeed.innerHTML = "";
        const dpr = window.devicePixelRatio || 1;

        const natW = loadedImageObj.naturalWidth || 800;
        const natH = loadedImageObj.naturalHeight || 600;

        const baseDisplayWidth = Math.min(natW, 900);
        const aspect = natH / natW;
        const baseDisplayHeight = baseDisplayWidth * aspect;

        const scale = (currentZoomPercent / 100);
        const displayW = Math.round(baseDisplayWidth * scale);
        const displayH = Math.round(baseDisplayHeight * scale);

        const pageNum = 1;
        const pageWrapper = document.createElement("div");
        pageWrapper.className = "pdf-page-wrapper";
        pageWrapper.id = `pageWrapper_${pageNum}`;
        pageWrapper.dataset.page = pageNum;
        pageWrapper.style.width = displayW + "px";
        pageWrapper.style.height = displayH + "px";

        // Base Canvas
        const baseCanvas = document.createElement("canvas");
        baseCanvas.className = "pdf-base-canvas";
        baseCanvas.id = `baseCanvas_${pageNum}`;
        baseCanvas.width = Math.round(displayW * dpr);
        baseCanvas.height = Math.round(displayH * dpr);
        baseCanvas.style.width = displayW + "px";
        baseCanvas.style.height = displayH + "px";

        const baseCtx = baseCanvas.getContext("2d");
        baseCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
        baseCtx.drawImage(loadedImageObj, 0, 0, displayW, displayH);

        // Transparent Synchronized Annotation Overlay Canvas
        const annoCanvas = document.createElement("canvas");
        annoCanvas.className = "pdf-annotation-canvas";
        annoCanvas.id = `annoCanvas_${pageNum}`;
        annoCanvas.dataset.page = pageNum;
        annoCanvas.width = Math.round(displayW * dpr);
        annoCanvas.height = Math.round(displayH * dpr);
        annoCanvas.style.width = displayW + "px";
        annoCanvas.style.height = displayH + "px";
        annoCanvas.style.opacity = layerOpacity.toString();

        attachDrawingListeners(annoCanvas, pageNum);

        pageWrapper.appendChild(baseCanvas);
        pageWrapper.appendChild(annoCanvas);
        pdfPagesFeed.appendChild(pageWrapper);

        renderPageAnnotations(pageNum);

        if (workspaceDocLoading) workspaceDocLoading.classList.add("is-hidden");
        setupScrollObserver();
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


    // --- 3. Render Pages Lazily with Viewport Observer ---
    const renderedPdfPages = new Set();
    let lazyPageObserver = null;

    async function renderSinglePdfPage(pageNum) {
        if (!pdfDocument || renderedPdfPages.has(pageNum)) return;
        renderedPdfPages.add(pageNum);

        const pageWrapper = document.getElementById(`pageWrapper_${pageNum}`);
        if (!pageWrapper) return;

        try {
            const page = await pdfDocument.getPage(pageNum);
            const scale = (currentZoomPercent / 100) * baseScale;
            const viewport = page.getViewport({ scale: scale });
            const dpr = window.devicePixelRatio || 1;

            pageWrapper.style.width = Math.round(viewport.width) + "px";
            pageWrapper.style.height = Math.round(viewport.height) + "px";

            let baseCanvas = document.getElementById(`baseCanvas_${pageNum}`);
            if (!baseCanvas) {
                baseCanvas = document.createElement("canvas");
                baseCanvas.className = "pdf-base-canvas";
                baseCanvas.id = `baseCanvas_${pageNum}`;
                pageWrapper.appendChild(baseCanvas);
            }
            baseCanvas.width = Math.round(viewport.width * dpr);
            baseCanvas.height = Math.round(viewport.height * dpr);
            baseCanvas.style.width = Math.round(viewport.width) + "px";
            baseCanvas.style.height = Math.round(viewport.height) + "px";

            const baseCtx = baseCanvas.getContext("2d");
            baseCtx.setTransform(dpr, 0, 0, dpr, 0, 0);

            let annoCanvas = document.getElementById(`annoCanvas_${pageNum}`);
            if (!annoCanvas) {
                annoCanvas = document.createElement("canvas");
                annoCanvas.className = "pdf-annotation-canvas";
                annoCanvas.id = `annoCanvas_${pageNum}`;
                annoCanvas.dataset.page = pageNum;
                attachDrawingListeners(annoCanvas, pageNum);
                pageWrapper.appendChild(annoCanvas);
            }
            annoCanvas.width = Math.round(viewport.width * dpr);
            annoCanvas.height = Math.round(viewport.height * dpr);
            annoCanvas.style.width = Math.round(viewport.width) + "px";
            annoCanvas.style.height = Math.round(viewport.height) + "px";
            annoCanvas.style.opacity = layerOpacity.toString();

            // Render PDF Page onto base canvas
            await page.render({
                canvasContext: baseCtx,
                viewport: viewport
            }).promise;

            // Composite any existing annotations on this page
            renderPageAnnotations(pageNum);
        } catch (err) {
            console.error(`Error rendering page ${pageNum}:`, err);
            renderedPdfPages.delete(pageNum);
        }
    }

    async function renderAllPdfPages() {
        if (!pdfDocument || !pdfPagesFeed) return;

        if (workspaceDocLoading) workspaceDocLoading.classList.remove("is-hidden");
        if (loadingStatusText) loadingStatusText.textContent = "Rendering specimen pages...";

        if (lazyPageObserver) {
            lazyPageObserver.disconnect();
            lazyPageObserver = null;
        }
        renderedPdfPages.clear();
        pdfPagesFeed.innerHTML = "";

        const scale = (currentZoomPercent / 100) * baseScale;
        const firstPage = await pdfDocument.getPage(1);
        const defaultViewport = firstPage.getViewport({ scale: scale });
        const defaultWidth = Math.round(defaultViewport.width);
        const defaultHeight = Math.round(defaultViewport.height);

        // Build lightweight DOM placeholders for all pages
        for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
            const pageWrapper = document.createElement("div");
            pageWrapper.className = "pdf-page-wrapper";
            pageWrapper.id = `pageWrapper_${pageNum}`;
            pageWrapper.dataset.page = pageNum;
            pageWrapper.style.width = defaultWidth + "px";
            pageWrapper.style.height = defaultHeight + "px";
            pdfPagesFeed.appendChild(pageWrapper);
        }

        // Preload visible/approaching pages via IntersectionObserver
        if (window.IntersectionObserver && workspaceCenterStage) {
            lazyPageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const pageNum = parseInt(entry.target.dataset.page, 10);
                        if (pageNum && !renderedPdfPages.has(pageNum)) {
                            renderSinglePdfPage(pageNum);
                        }
                    }
                });
            }, {
                root: workspaceCenterStage,
                rootMargin: "300px 0px 300px 0px",
                threshold: 0.01
            });

            document.querySelectorAll(".pdf-page-wrapper").forEach(pageEl => {
                lazyPageObserver.observe(pageEl);
            });
        }

        // Render immediate initial page for instantaneous display
        await renderSinglePdfPage(currentVisiblePage || 1);

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
    let drawRafId = null;

    function schedulePageAnnotationRender(pageNum) {
        if (drawRafId) return;
        drawRafId = requestAnimationFrame(() => {
            drawRafId = null;
            renderPageAnnotations(pageNum);
        });
    }

    // Attach global stop-drawing handlers once
    window.addEventListener("mouseup", handleStopDrawing);
    window.addEventListener("touchend", handleStopDrawing);

    function attachDrawingListeners(canvas, pageNum) {
        canvas.addEventListener("mousedown", (e) => handleStartDrawing(e, canvas, pageNum));
        canvas.addEventListener("mousemove", (e) => handleDrawMove(e, canvas, pageNum));
        canvas.addEventListener("touchstart", (e) => handleStartDrawing(e, canvas, pageNum), { passive: true });
        canvas.addEventListener("touchmove", (e) => handleDrawMove(e, canvas, pageNum), { passive: true });
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
        const selfLayer = getActiveSelfLayer();
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
        if (isAnnotationLocked && activeTool !== "pan") {
            if (typeof window.showBottomToast === "function") {
                window.showBottomToast(getAnnotationLockMessage(), "warning");
            }
            return;
        }

        const selfLayer = getActiveSelfLayer();
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
            layerId: selfLayer.layerId,
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

                schedulePageAnnotationRender(pageNum);
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
        schedulePageAnnotationRender(pageNum);
    }

    function handleStopDrawing() {
        if (drawRafId) {
            cancelAnimationFrame(drawRafId);
            drawRafId = null;
        }

        if (activeTool === "pan") {
            if (isDraggingObject) {
                isDraggingObject = false;
                const canvas = document.getElementById(`annoCanvas_${activeDrawingPage}`);
                if (canvas) canvas.style.cursor = "grab";

                if (hasDragged && selectedAnnotation) {
                    renderPageAnnotations(activeDrawingPage);
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
            const selfLayer = getActiveSelfLayer();
            if (!selfLayer) return;

            const idx = selfLayer.strokes.indexOf(selectedAnnotation);
            if (idx !== -1) {
                const deleted = selfLayer.strokes.splice(idx, 1)[0];
                updateStrokeStats();
                if (deleted && deleted.page) {
                    renderPageAnnotations(deleted.page);
                }
                if (deleted && deleted.id) {
                    fetch(`view.php?action=delete_stroke&id=${docId}${versionQueryParam}`, {
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

        const selfLayer = getActiveSelfLayer();
        const textAnnotation = {
            id: null,
            layerId: selfLayer ? selfLayer.layerId : null,
            page: pageNum,
            tool: "text",
            content: text,
            color: color,
            xRel: xRel,
            yRel: yRel
        };

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
        fetch(`view.php?action=save_stroke&id=${docId}${versionQueryParam}`, {
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
                if (data.layer_id && strokeObj.layerId !== data.layer_id) {
                    const oldLid = strokeObj.layerId;
                    if (annotationLayers[oldLid]) {
                        annotationLayers[data.layer_id] = annotationLayers[oldLid];
                        annotationLayers[data.layer_id].layerId = data.layer_id;
                        delete annotationLayers[oldLid];
                    }
                    if (activeLayerId === oldLid) {
                        activeLayerId = data.layer_id;
                    }
                    strokeObj.layerId = data.layer_id;
                    updateCollaboratorLayersUI();
                }
                updateStrokeStats();
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

    // Track collapsed user cards in the sidebar (key: userId, value: boolean)
    const collapsedUserGroups = {};

    // --- 7. Collaborators & Annotation Groups UI in Right Drawer ---
    function updateCollaboratorLayersUI() {
        if (!layersList) return;
        layersList.innerHTML = "";

        const layerKeys = Object.keys(annotationLayers);
        if (layerKeys.length === 0) {
            layersList.innerHTML = `
                <div style="font-size:12px; color:var(--muted); padding:10px 4px; text-align:center;">
                    No annotation groups found.
                </div>
            `;
            return;
        }

        // Group layers by userId
        const groupsByUser = {};
        layerKeys.forEach(lid => {
            const layer = annotationLayers[lid];
            const uid = parseInt(layer.userId, 10) || 0;
            if (!groupsByUser[uid]) {
                groupsByUser[uid] = {
                    userId: uid,
                    userName: layer.userName || (layer.isSelf ? (config.currentUserName || 'You') : 'Collaborator'),
                    userAvatar: layer.userAvatar || (layer.isSelf ? (config.currentUserAvatar || null) : null),
                    isSelf: !!layer.isSelf,
                    layers: []
                };
            }
            if (!groupsByUser[uid].userAvatar && layer.userAvatar) {
                groupsByUser[uid].userAvatar = layer.userAvatar;
            }
            groupsByUser[uid].layers.push(layer);
        });

        // Sort users so current user is always pinned on top, then alphabetical
        const userGroupsList = Object.values(groupsByUser).sort((a, b) => {
            if (a.isSelf && !b.isSelf) return -1;
            if (!a.isSelf && b.isSelf) return 1;
            return (a.userName || "").localeCompare(b.userName || "");
        });

        userGroupsList.forEach(uGroup => {
            const isSelf = uGroup.isSelf;
            const uid = uGroup.userId;

            // Default behavior: self is expanded (false), other collaborators are collapsed (true) unless explicitly toggled
            const isCollapsed = (collapsedUserGroups[uid] !== undefined)
                ? !!collapsedUserGroups[uid]
                : !isSelf;

            const groupCard = document.createElement("div");
            groupCard.className = "user-layers-group" + 
                (isSelf ? " is-self-group" : "") + 
                (isCollapsed ? " is-collapsed" : "");

            // Calculate overall visibility across this user's layers
            const anyVisible = uGroup.layers.some(l => l.visible);
            const totalLayersCount = uGroup.layers.length;

            const eyeIconSvg = (visible) => visible
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

            // Header for this user's group
            const headerDiv = document.createElement("div");
            headerDiv.className = "user-layers-group-header";

            // User avatar or initials
            const userAvatarSrc = uGroup.userAvatar ? `../${uGroup.userAvatar}` : null;
            const userInitials = (uGroup.userName || "U")
                .split(/\s+/)
                .filter(Boolean)
                .map(p => p[0].toUpperCase())
                .slice(0, 2)
                .join("") || "U";

            const avatarMarkup = userAvatarSrc
                ? `<div class="user-avatar-badge" title="${escapeHtml(uGroup.userName)}"><img src="${escapeHtml(userAvatarSrc)}" alt="${escapeHtml(uGroup.userName)}" onerror="this.onerror=null; this.parentNode.innerHTML='${escapeHtml(userInitials)}';"></div>`
                : `<div class="user-avatar-badge" title="${escapeHtml(uGroup.userName)}">${escapeHtml(userInitials)}</div>`;

            const isReviewer = Array.isArray(config.reviewerUserIds) && config.reviewerUserIds.includes(parseInt(uid, 10));
            const reviewerPillMarkup = isReviewer ? `<span class="workspace-badge badge-type" style="margin-left: 6px; font-size: 9.5px;">Reviewer</span>` : '';

            headerDiv.innerHTML = `
                <div class="user-group-info">
                    <span class="user-group-chevron">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                    ${avatarMarkup}
                    <span class="user-group-name">${escapeHtml(uGroup.userName)}${isSelf ? ' (You)' : ''}${reviewerPillMarkup}</span>
                </div>
                <div class="user-group-actions">
                    <button type="button" class="btn-user-group-toggle ${anyVisible ? 'is-visible' : ''}" title="Toggle All">
                        ${eyeIconSvg(anyVisible)}
                    </button>
                </div>
            `;

            // Clicking header (excluding action button) expands/collapses the dropdown
            headerDiv.addEventListener("click", (e) => {
                if (e.target.closest(".btn-user-group-toggle")) return;
                const nextState = !groupCard.classList.contains("is-collapsed");
                collapsedUserGroups[uid] = nextState;
                groupCard.classList.toggle("is-collapsed", nextState);
            });

            // Toggle visibility for all layers belonging to this user
            const groupEyeBtn = headerDiv.querySelector(".btn-user-group-toggle");
            groupEyeBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                const willBeVisible = !uGroup.layers.some(l => l.visible);
                uGroup.layers.forEach(l => {
                    l.visible = willBeVisible;
                });
                updateCollaboratorLayersUI();
                renderAllPagesAnnotations();
            });

            // Content container holding all layer items for this user
            const contentDiv = document.createElement("div");
            contentDiv.className = "user-layers-content";

            uGroup.layers.forEach(layer => {
                const lid = layer.layerId;
                const canEditSelf = isSelf && canAnnotate && !isAnnotationLocked;
                const isSelected = canEditSelf && (parseInt(lid, 10) === parseInt(activeLayerId, 10));
                const strokeCount = layer.strokes ? layer.strokes.length : 0;

                const row = document.createElement("div");
                row.className = "layer-item" + 
                    (isSelf ? " is-self" : "") + 
                    (isSelected ? " is-selected-target" : "");
                row.id = `layerRow_${lid}`;

                row.innerHTML = `
                    <div class="layer-meta">
                        <span class="layer-color-indicator" style="background-color: ${layer.color || '#16a34a'};"></span>
                        <div class="layer-name-info">
                            <div class="layer-group-title-row">
                                <span class="layer-author" title="${canEditSelf ? 'Click or double-click to rename' : ''}">${escapeHtml(layer.layerName || 'Layer')}</span>
                                ${canEditSelf ? `
                                <button type="button" class="layer-rename-btn" title="Rename group">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 20h9"></path>
                                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                    </svg>
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="layer-actions">
                        <button type="button" class="btn-layer-toggle ${layer.visible ? 'is-visible' : ''}" data-lid="${lid}" title="Toggle Group Visibility">
                            ${eyeIconSvg(layer.visible)}
                        </button>
                        ${canEditSelf ? `
                        <button type="button" class="btn-layer-delete" data-lid="${lid}" title="Delete Group">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                        ` : ''}
                    </div>
                `;

                // Clicking personal row switches active drawing target to this group
                if (canEditSelf) {
                    const authorSpan = row.querySelector(".layer-author");
                    const renameBtn = row.querySelector(".layer-rename-btn");
                    const titleRow = row.querySelector(".layer-group-title-row");

                    const startInlineRename = (e) => {
                        if (e) e.stopPropagation();
                        if (titleRow.querySelector(".layer-title-input")) return;

                        const currentName = layer.layerName || "Layer";
                        const input = document.createElement("input");
                        input.type = "text";
                        input.className = "layer-title-input";
                        input.value = currentName;
                        input.maxLength = 50;

                        authorSpan.style.display = "none";
                        if (renameBtn) renameBtn.style.display = "none";
                        titleRow.appendChild(input);
                        input.focus();
                        input.select();

                        let isCommitting = false;
                        const commitRename = () => {
                            if (isCommitting) return;
                            isCommitting = true;

                            const val = input.value.trim() || currentName;
                            if (val !== currentName) {
                                layer.layerName = val;
                                authorSpan.textContent = val;
                                fetch(`view.php?action=rename_layer&id=${docId}${versionQueryParam}`, {
                                    method: "POST",
                                    headers: {
                                        "Content-Type": "application/json",
                                        "X-Requested-With": "XMLHttpRequest"
                                    },
                                    body: JSON.stringify({ layer_id: parseInt(lid, 10), name: val })
                                }).catch(err => console.error("Error renaming layer:", err));
                            }

                            if (input.parentNode) {
                                input.parentNode.removeChild(input);
                            }
                            authorSpan.style.display = "";
                            if (renameBtn) renameBtn.style.display = "";
                        };

                        input.addEventListener("keydown", (evt) => {
                            if (evt.key === "Enter") {
                                evt.preventDefault();
                                commitRename();
                            } else if (evt.key === "Escape") {
                                evt.preventDefault();
                                isCommitting = true;
                                if (input.parentNode) input.parentNode.removeChild(input);
                                authorSpan.style.display = "";
                                if (renameBtn) renameBtn.style.display = "";
                            }
                        });

                        input.addEventListener("blur", commitRename);
                    };

                    if (renameBtn) {
                        renameBtn.addEventListener("click", startInlineRename);
                    }
                    authorSpan.addEventListener("dblclick", startInlineRename);

                    // Trash button click on this specific layer (instant delete)
                    const deleteBtn = row.querySelector(".btn-layer-delete");
                    if (deleteBtn) {
                        deleteBtn.addEventListener("click", (e) => {
                            e.stopPropagation();
                            executeDeleteLayerGroup(layer.layerId);
                        });
                    }

                    row.querySelector(".layer-meta").addEventListener("click", (e) => {
                        if (e.target.closest(".layer-title-input") || e.target.closest(".layer-rename-btn")) return;
                        activeLayerId = parseInt(lid, 10);
                        if (layer.color) {
                            activeColor = layer.color;
                        }
                        updateCollaboratorLayersUI();
                    });
                }

                // Eye visibility toggle for individual layer
                const toggleBtn = row.querySelector(".btn-layer-toggle");
                toggleBtn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    layer.visible = !layer.visible;
                    updateCollaboratorLayersUI();
                    renderAllPagesAnnotations();
                });

                contentDiv.appendChild(row);
            });

            groupCard.appendChild(headerDiv);
            groupCard.appendChild(contentDiv);
            layersList.appendChild(groupCard);
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
        currentZoomPercent = Math.max(30, Math.min(300, newZoom));
        if (zoomLevelDisplay) zoomLevelDisplay.textContent = Math.round(currentZoomPercent) + "%";
        if (renderMode === "image") {
            renderImagePage();
        } else if (renderMode === "pdf") {
            renderAllPdfPages();
        }
    }

    if (btnZoomIn) btnZoomIn.addEventListener("click", () => setZoom(currentZoomPercent + 15));
    if (btnZoomOut) btnZoomOut.addEventListener("click", () => setZoom(currentZoomPercent - 15));
    if (btnZoomReset) btnZoomReset.addEventListener("click", () => setZoom(100));
    if (btnZoomFit) {
        btnZoomFit.addEventListener("click", () => {
            if (!workspaceCenterStage) return;
            const containerWidth = workspaceCenterStage.clientWidth - 80;
            if (renderMode === "image" && loadedImageObj) {
                const natW = loadedImageObj.naturalWidth || 800;
                const baseDisplayWidth = Math.min(natW, 900);
                const fitPercent = Math.round((containerWidth / baseDisplayWidth) * 100);
                setZoom(fitPercent);
            } else if (renderMode === "pdf") {
                const standardPageWidth = 595 * baseScale; // Default PDF standard width
                const fitPercent = Math.round((containerWidth / standardPageWidth) * 100);
                setZoom(fitPercent);
            }
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
    if (isAnnotationLocked) {
        // Enforce Pan mode and disable drawing tools
        dockButtons.forEach(btn => {
            if (btn.dataset.tool !== "pan") {
                btn.disabled = true;
                btn.style.opacity = "0.35";
                btn.style.cursor = "not-allowed";
                btn.title = getAnnotationLockMessage();
                btn.classList.remove("is-active");
            } else {
                btn.classList.add("is-active");
            }
        });

        colorSwatches.forEach(swatch => {
            swatch.style.opacity = "0.35";
            swatch.style.pointerEvents = "none";
        });

        strokeButtons.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = "0.35";
            btn.style.cursor = "not-allowed";
        });

        if (btnUndoStroke) {
            btnUndoStroke.disabled = true;
            btnUndoStroke.style.opacity = "0.35";
            btnUndoStroke.style.cursor = "not-allowed";
        }
        if (btnClearMyLayer) {
            btnClearMyLayer.disabled = true;
            btnClearMyLayer.style.opacity = "0.35";
            btnClearMyLayer.style.cursor = "not-allowed";
        }
        if (btnAddLayerGroup) {
            btnAddLayerGroup.disabled = true;
            btnAddLayerGroup.style.opacity = "0.35";
            btnAddLayerGroup.style.cursor = "not-allowed";
        }
    }

    dockButtons.forEach(btn => {
        btn.addEventListener("click", function () {
            if (isAnnotationLocked && this.dataset.tool !== "pan") {
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast(getAnnotationLockMessage(), "warning");
                }
                return;
            }

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
            if (isAnnotationLocked) return;
            colorSwatches.forEach(s => s.classList.remove("is-selected"));
            this.classList.add("is-selected");
            activeColor = this.dataset.color || "#16a34a";
            const selfLayer = getActiveSelfLayer();
            if (selfLayer) {
                selfLayer.color = activeColor;
            }
        });
    });

    // Stroke Widths
    strokeButtons.forEach(btn => {
        btn.addEventListener("click", function () {
            if (isAnnotationLocked) return;
            strokeButtons.forEach(b => b.classList.remove("is-active"));
            this.classList.add("is-active");
            activeStrokeWidth = parseInt(this.dataset.stroke, 10) || 2;
        });
    });

    // --- 11. Undo & Clear Actions ---
    if (btnUndoStroke) {
        btnUndoStroke.addEventListener("click", function () {
            const selfLayer = getActiveSelfLayer();
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
                    fetch(`view.php?action=delete_stroke&id=${docId}${versionQueryParam}`, {
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

    // Instant Deletion of Annotation Group (No confirmation modal required)
    function executeDeleteLayerGroup(targetLid) {
        if (!targetLid) return;

        fetch(`view.php?action=delete_layer_group&id=${docId}${versionQueryParam}`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ layer_id: targetLid })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (data.reset_layer) {
                    // Reset to clean Layer 1
                    annotationLayers[data.reset_layer.layerId] = data.reset_layer;
                    activeLayerId = data.reset_layer.layerId;
                } else {
                    // Removed this layer; choose another personal layer if available
                    delete annotationLayers[targetLid];
                    const remainingSelf = Object.values(annotationLayers).filter(l => l.isSelf);
                    if (remainingSelf.length > 0) {
                        activeLayerId = remainingSelf[remainingSelf.length - 1].layerId;
                    } else {
                        activeLayerId = null;
                    }
                }

                updateCollaboratorLayersUI();
                renderAllPagesAnnotations();

                if (data.history_event) {
                    prependHistoryEvent(data.history_event);
                }
            } else {
                console.error("Failed to delete annotation group:", data.message);
            }
        })
        .catch(err => {
            console.error("Error deleting layer group:", err);
        });
    }

    if (btnClearMyLayer) {
        btnClearMyLayer.addEventListener("click", function () {
            const selfLayer = getActiveSelfLayer();
            if (!selfLayer) return;
            executeDeleteLayerGroup(selfLayer.layerId);
        });
    }

    function updateStrokeStats() {
        Object.keys(annotationLayers).forEach(lid => {
            const layer = annotationLayers[lid];
            const count = layer && layer.strokes ? layer.strokes.length : 0;
            const countSpan = document.getElementById(`statCount_${lid}`);
            if (countSpan) {
                countSpan.textContent = `${layer.userName || 'User'} \u2022 ${count} ${count === 1 ? 'annotation' : 'annotations'}`;
            }
        });
    }

    // --- 11b. '+ Add Layer Group' Button Handler (Instant auto-iteration Layer X without native prompt) ---
    if (btnAddLayerGroup) {
        btnAddLayerGroup.addEventListener("click", function () {
            if (isAnnotationLocked || !canAnnotate) {
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast(getAnnotationLockMessage(), "warning");
                }
                return;
            }

            // Compute highest existing Layer N to iterate sequentially
            let highestNum = 0;
            Object.values(annotationLayers).forEach(l => {
                if (l.isSelf) {
                    const match = (l.layerName || "").match(/^Layer\s+(\d+)$/i);
                    if (match) {
                        const n = parseInt(match[1], 10);
                        if (n > highestNum) highestNum = n;
                    }
                }
            });

            const selfCount = Object.values(annotationLayers).filter(l => l.isSelf).length;
            const nextLayerNum = Math.max(highestNum + 1, selfCount + 1);
            const groupName = `Layer ${nextLayerNum}`;

            fetch(`view.php?action=create_layer&id=${docId}${versionQueryParam}`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: JSON.stringify({
                    name: groupName,
                    color: activeColor
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.layer) {
                    const newLayer = data.layer;
                    annotationLayers[newLayer.layerId] = {
                        layerId: newLayer.layerId,
                        userId: newLayer.userId,
                        userName: newLayer.userName,
                        layerName: newLayer.layerName,
                        color: newLayer.color,
                        visible: true,
                        isSelf: true,
                        strokes: []
                    };
                    activeLayerId = newLayer.layerId;
                    updateCollaboratorLayersUI();
                    renderAllPagesAnnotations();
                } else {
                    console.error("Failed to create annotation group:", data.message);
                }
            })
            .catch(err => {
                console.error("Error creating layer group:", err);
            });
        });
    }

    // --- 12. Right Side Panel & Tab Switching ---
    const btnToggleRightDrawer = document.getElementById("btnToggleRightDrawer") || document.getElementById("btnToggleLayerDrawer");
    const tabBtnAnnotations = document.getElementById("tabBtnAnnotations");
    const tabBtnVersions = document.getElementById("tabBtnVersions");
    const drawerTabAnnotations = document.getElementById("drawerTabAnnotations");
    const drawerTabVersions = document.getElementById("drawerTabVersions");

    function switchSidePanelTab(tabName, persist = true) {
        if (tabName === "versions") {
            if (tabBtnVersions) tabBtnVersions.classList.add("active");
            if (tabBtnAnnotations) tabBtnAnnotations.classList.remove("active");
            if (drawerTabVersions) drawerTabVersions.style.display = "block";
            if (drawerTabAnnotations) drawerTabAnnotations.style.display = "none";
            if (workspaceRightDrawer) workspaceRightDrawer.classList.add("is-open");
            loadVersionHistory();
            if (typeof fetchAndRenderAuditHistory === "function") {
                fetchAndRenderAuditHistory(false);
            }
        } else {
            if (tabBtnAnnotations) tabBtnAnnotations.classList.add("active");
            if (tabBtnVersions) tabBtnVersions.classList.remove("active");
            if (drawerTabAnnotations) drawerTabAnnotations.style.display = "block";
            if (drawerTabVersions) drawerTabVersions.style.display = "none";
            if (workspaceRightDrawer) workspaceRightDrawer.classList.add("is-open");
        }

        if (persist && docId) {
            try {
                sessionStorage.setItem(`edms_drawer_tab_${docId}`, tabName);
                sessionStorage.setItem(`edms_drawer_open_${docId}`, "true");
            } catch (e) {}
        }
    }

    if (tabBtnAnnotations) tabBtnAnnotations.addEventListener("click", () => switchSidePanelTab("annotations"));
    if (tabBtnVersions) tabBtnVersions.addEventListener("click", () => switchSidePanelTab("versions"));

    const btnToggleRightDrawerEdge = document.getElementById("btnToggleRightDrawerEdge");

    function toggleRightDrawer() {
        if (workspaceRightDrawer) {
            const willBeOpen = !workspaceRightDrawer.classList.contains("is-open");
            workspaceRightDrawer.classList.toggle("is-open", willBeOpen);
            if (btnToggleRightDrawer) {
                btnToggleRightDrawer.classList.toggle("is-active", willBeOpen);
            }
            if (docId) {
                try {
                    sessionStorage.setItem(`edms_drawer_open_${docId}`, willBeOpen ? "true" : "false");
                } catch (e) {}
            }
        }
    }

    if (btnToggleRightDrawerEdge) {
        btnToggleRightDrawerEdge.addEventListener("click", toggleRightDrawer);
    }

    if (btnToggleRightDrawer) {
        btnToggleRightDrawer.addEventListener("click", toggleRightDrawer);
    }

    if (btnCloseRightDrawer) {
        btnCloseRightDrawer.addEventListener("click", function () {
            if (workspaceRightDrawer) workspaceRightDrawer.classList.remove("is-open");
            if (btnToggleRightDrawer) btnToggleRightDrawer.classList.remove("is-active");
            if (docId) {
                try {
                    sessionStorage.setItem(`edms_drawer_open_${docId}`, "false");
                } catch (e) {}
            }
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

    // Export with Annotations (Images & PDFs)

    if (btnDownloadWithAnnotations) {
        btnDownloadWithAnnotations.addEventListener("click", async function (e) {
            e.preventDefault();
            if (workspaceDownloadDropdown) {
                workspaceDownloadDropdown.classList.remove("is-open");
            }

            // Visual loading state
            const origButtonHtml = btnDownloadDropdownToggle.innerHTML;
            btnDownloadDropdownToggle.disabled = true;
            btnDownloadDropdownToggle.innerHTML = `
                <div class="viewer-spinner" style="width:14px; height:14px; border-width:2px; margin-right:4px;"></div>
                <span>Exporting...</span>
            `;

            try {
                if (isImage) {
                    // Export Image with annotations in its native format (.png, .jpg, .webp, etc.)
                    if (!loadedImageObj) throw new Error("Image specimen is not loaded.");

                    const natW = loadedImageObj.naturalWidth || 800;
                    const natH = loadedImageObj.naturalHeight || 600;

                    const tempCanvas = document.createElement("canvas");
                    tempCanvas.width = natW;
                    tempCanvas.height = natH;
                    const tCtx = tempCanvas.getContext("2d");

                    // 1. Draw original base image
                    tCtx.drawImage(loadedImageObj, 0, 0, natW, natH);

                    // 2. Composite active annotation layer
                    const annoCanvas = generateCleanPageAnnotationsCanvas(1, natW, natH);
                    tCtx.drawImage(annoCanvas, 0, 0);

                    // 3. Determine MIME type and file extension
                    let mimeType = "image/png";
                    let outExt = fileExt;
                    if (fileExt === "jpg" || fileExt === "jpeg") {
                        mimeType = "image/jpeg";
                        outExt = "jpg";
                    } else if (fileExt === "webp") {
                        mimeType = "image/webp";
                        outExt = "webp";
                    } else {
                        mimeType = "image/png";
                        outExt = "png";
                    }

                    let baseDocName = (config.docName || "image").replace(/\.(png|jpe?g|webp|gif|svg|bmp|ico)$/i, "");
                    const downloadFileName = `${baseDocName}_annotated.${outExt}`;

                    tempCanvas.toBlob(blob => {
                        if (!blob) {
                            alert("Failed to export annotated image.");
                            return;
                        }
                        const blobUrl = URL.createObjectURL(blob);
                        const downloadLink = document.createElement("a");
                        downloadLink.href = blobUrl;
                        downloadLink.download = downloadFileName;
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);

                        setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);
                    }, mimeType, 0.95);
                } else {
                    // Export Native PDF with annotations
                    if (typeof PDFLib === "undefined" || !PDFLib.PDFDocument) {
                        alert("PDF export engine is still loading. Please try again shortly.");
                        return;
                    }

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
                console.error("Error generating annotated document:", err);
                alert("Failed to export annotated document: " + (err.message || err));
            } finally {
                btnDownloadDropdownToggle.disabled = false;
                btnDownloadDropdownToggle.innerHTML = origButtonHtml;
            }
        });
    }

    // --- 14. Version History & Revision Lineage Controller ---
    const btnBadgeVersion = document.getElementById("btnBadgeVersion");
    const versionTimelineContainer = document.getElementById("versionTimelineContainer");

    // Upload Revision Modal Elements (Matches explorer.php style)
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

    if (btnBadgeVersion) {
        btnBadgeVersion.addEventListener("click", function () {
            switchSidePanelTab("versions");
        });
    }

    function openUploadRevisionModal() {
        if (!modalUploadRevision) return;
        modalUploadRevision.classList.add("is-open");
    }

    function closeUploadRevisionModal() {
        if (!modalUploadRevision) return;
        modalUploadRevision.classList.remove("is-open");
        if (formUploadRevision) formUploadRevision.reset();
        clearRevisionFileSelection();
    }

    if (btnOpenUploadRevisionModal) btnOpenUploadRevisionModal.addEventListener("click", openUploadRevisionModal);
    if (btnCloseUploadRevisionModal) btnCloseUploadRevisionModal.addEventListener("click", closeUploadRevisionModal);
    if (btnCancelRevisionUpload) btnCancelRevisionUpload.addEventListener("click", closeUploadRevisionModal);

    if (modalUploadRevision) {
        modalUploadRevision.addEventListener("click", function (e) {
            if (e.target === modalUploadRevision) closeUploadRevisionModal();
        });
    }

    // Dropzone File Handling (Full clickable / drag-and-drop area via .upload-file-input)
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

    // Render Compact Minimalist Version Cards with Icon-Based Action Controls
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
            
            // Determine if THIS version is the currently viewed/opened document page
            let isCurrentlyViewed = false;
            if (activeVersionId > 0) {
                isCurrentlyViewed = (vId === activeVersionId);
            } else {
                // If viewing main/latest revision
                isCurrentlyViewed = isHeadCurrent || (v.version_number === activeVersionName);
            }

            const targetUrl = isHeadCurrent && activeVersionId === 0 
                ? `view.php?id=${docId}` 
                : `view.php?id=${docId}&version_id=${v.id}`;

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

            // Card click handler: Navigate to the preview snapshot (ignoring clicks on buttons, links, or inputs)
            card.addEventListener("click", function (e) {
                if (e.target.closest("a") || e.target.closest("button") || e.target.closest("input") || e.target.closest(".version-details-panel")) {
                    return;
                }
                if (!isCurrentlyViewed) {
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
            const revStatus = (v.review_status || "").toLowerCase();
            if (revStatus === "rejected") {
                statusPill = `<span style="background: var(--red-soft); color: var(--red-ink); font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; border: 1px solid var(--red-line); letter-spacing: 0.02em;">REJECTED</span>`;
            } else if (revStatus === "approved") {
                statusPill = `<span style="background: var(--success-badge-bg); color: var(--success-ink); font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; border: 1px solid var(--success-line); letter-spacing: 0.02em;">APPROVED</span>`;
            } else if (revStatus === "pending") {
                statusPill = `<span style="background: var(--amber-soft); color: var(--amber-ink); font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; border: 1px solid var(--amber-line); letter-spacing: 0.02em;">PENDING</span>`;
            }

            card.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-width: 0;">
                        <span style="font-family: monospace; font-size: 12.5px; font-weight: 700; color: var(--ink);">${escapeHtml(v.version_number)}</span>
                        ${statusPill}
                        <span style="font-size: 11.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(v.author_name || 'System')} • ${escapeHtml(v.created_at || '')}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 2px; flex-shrink: 0;">
                        <a href="${targetUrl}" class="btn-action" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: ${isCurrentlyViewed ? 'var(--green)' : 'var(--ink)'}; text-decoration: none;" title="${isCurrentlyViewed ? 'Currently viewing' : 'Preview snapshot'}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                        <a href="download.php?id=${docId}&version_id=${v.id}" class="btn-action" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: var(--ink); text-decoration: none;" title="Download file" download>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                        <button type="button" class="btn-action btn-toggle-details" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: var(--muted); border: none; background: transparent; cursor: pointer;" title="Details">
                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="transition: transform 0.2s ease;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                    </div>
                </div>
                <div class="version-details-panel" style="display: none; margin-top: 8px; padding-top: 6px; border-top: 1px dashed var(--border); font-size: 11.5px; color: var(--muted);">
                    <div><strong>File:</strong> ${escapeHtml(v.file_original_name || '')} ${fileSizeFormatted ? `(${fileSizeFormatted})` : ''}</div>
                    ${v.change_summary ? `<div style="margin-top: 2px; color: var(--ink);"><strong>Note:</strong> ${escapeHtml(v.change_summary)}</div>` : ''}
                </div>
            `;

            // Toggle Details Dropdown Handler
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

    // --- 15. Document Review & Approval Workflow Controller ---
    const modalSubmitReview = document.getElementById("modalSubmitReview");
    const btnOpenSubmitReviewModal = document.getElementById("btnOpenSubmitReviewModal");
    const btnCloseSubmitReviewModal = document.getElementById("btnCloseSubmitReviewModal");
    const btnCancelSubmitReview = document.getElementById("btnCancelSubmitReview");
    const formSubmitReview = document.getElementById("formSubmitReview");
    const btnSubmitReviewAction = document.getElementById("btnSubmitReviewAction");

    function openSubmitReviewModal() {
        if (!modalSubmitReview) return;
        modalSubmitReview.classList.add("is-open");
    }

    function closeSubmitReviewModal() {
        if (!modalSubmitReview) return;
        modalSubmitReview.classList.remove("is-open");
    }

    if (btnOpenSubmitReviewModal) btnOpenSubmitReviewModal.addEventListener("click", openSubmitReviewModal);
    if (btnCloseSubmitReviewModal) btnCloseSubmitReviewModal.addEventListener("click", closeSubmitReviewModal);
    if (btnCancelSubmitReview) btnCancelSubmitReview.addEventListener("click", closeSubmitReviewModal);

    if (modalSubmitReview) {
        modalSubmitReview.addEventListener("click", function (e) {
            if (e.target === modalSubmitReview) closeSubmitReviewModal();
        });
    }

    const searchReviewerInput = document.getElementById("searchReviewerInput");
    if (searchReviewerInput) {
        searchReviewerInput.addEventListener("input", function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll("#reviewersListContainer .reviewer-item-row").forEach(row => {
                const name = row.getAttribute("data-name") || "";
                const username = row.getAttribute("data-username") || "";
                const match = name.includes(q) || username.includes(q);
                row.style.display = match ? "flex" : "none";
            });
        });
    }

    if (formSubmitReview) {
        formSubmitReview.addEventListener("submit", function (e) {
            e.preventDefault();

            const checkedReviewers = formSubmitReview.querySelectorAll('input[name="reviewer_ids[]"]:checked');
            if (checkedReviewers.length === 0) {
                alert("Please select at least one reviewer.");
                return;
            }

            const reviewerIds = Array.from(checkedReviewers).map(cb => parseInt(cb.value, 10));
            const notes = formSubmitReview.querySelector('textarea[name="notes"]')?.value || "";

            if (btnSubmitReviewAction) {
                btnSubmitReviewAction.disabled = true;
                btnSubmitReviewAction.innerHTML = `<span>Submitting...</span>`;
            }

            fetch("../actions/review-handler.php?action=submit_review", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: JSON.stringify({
                    doc_id: docId,
                    reviewer_ids: reviewerIds,
                    notes: notes
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `view.php?id=${docId}`;
                } else {
                    alert(data.message || "Failed to submit for review.");
                    if (btnSubmitReviewAction) {
                        btnSubmitReviewAction.disabled = false;
                        btnSubmitReviewAction.innerHTML = `<span>Submit</span>`;
                    }
                }
            })
            .catch(err => {
                console.error("Error submitting review:", err);
                alert("Network error while submitting review.");
                if (btnSubmitReviewAction) {
                    btnSubmitReviewAction.disabled = false;
                    btnSubmitReviewAction.innerHTML = `<span>Submit</span>`;
                }
            });
        });
    }

    // --- 17. Finish Review & Verdict Handling ---
    const modalReviewVerdict = document.getElementById("modalReviewVerdict");
    const btnOpenFinishReviewModal = document.getElementById("btnOpenFinishReviewModal");
    const btnCloseVerdictModal = document.getElementById("btnCloseVerdictModal");
    const btnCancelVerdict = document.getElementById("btnCancelVerdict");
    const btnRejectVerdict = document.getElementById("btnRejectVerdict");
    const btnApproveVerdict = document.getElementById("btnApproveVerdict");
    const verdictComments = document.getElementById("verdictComments");

    function openVerdictModal() {
        if (!modalReviewVerdict) return;
        if (verdictComments) verdictComments.value = "";
        modalReviewVerdict.classList.add("is-open");
    }

    function closeVerdictModal() {
        if (!modalReviewVerdict) return;
        modalReviewVerdict.classList.remove("is-open");
    }

    if (btnOpenFinishReviewModal) {
        btnOpenFinishReviewModal.addEventListener("click", openVerdictModal);
    }
    if (btnCloseVerdictModal) btnCloseVerdictModal.addEventListener("click", closeVerdictModal);
    if (btnCancelVerdict) btnCancelVerdict.addEventListener("click", closeVerdictModal);

    if (modalReviewVerdict) {
        modalReviewVerdict.addEventListener("click", function (e) {
            if (e.target === modalReviewVerdict) closeVerdictModal();
        });
    }

    function executeReviewVerdict(verdict) {
        const comments = verdictComments?.value.trim() || "";

        if (btnApproveVerdict) btnApproveVerdict.disabled = true;
        if (btnRejectVerdict) btnRejectVerdict.disabled = true;
        if (btnCancelVerdict) btnCancelVerdict.disabled = true;

        const activeBtn = (verdict === "approved") ? btnApproveVerdict : btnRejectVerdict;
        const origText = activeBtn ? activeBtn.innerHTML : "";
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
                    btnApproveVerdict.innerHTML = "✔ Approve";
                }
                if (btnRejectVerdict) {
                    btnRejectVerdict.disabled = false;
                    btnRejectVerdict.innerHTML = "✖ Reject";
                }
                if (btnCancelVerdict) btnCancelVerdict.disabled = false;
            }
        })
        .catch(err => {
            console.error("Error submitting verdict:", err);
            alert("Network error while recording verdict.");
            if (btnApproveVerdict) {
                btnApproveVerdict.disabled = false;
                btnApproveVerdict.innerHTML = "✔ Approve";
            }
            if (btnRejectVerdict) {
                btnRejectVerdict.disabled = false;
                btnRejectVerdict.innerHTML = "✖ Reject";
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

    // Retract Review Submission Handler (Revert Hold -> Active)
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
                    btnRetractReviewSubmission.textContent = "↺ Retract Submission";
                }
            })
            .catch(err => {
                console.error("Error retracting review submission:", err);
                alert("Network error while retracting review submission.");
                btnRetractReviewSubmission.disabled = false;
                btnRetractReviewSubmission.textContent = "↺ Retract Submission";
            });
        });
    }

    // --- 18. Document Access & Contributors Management ---
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

    function renderContributorsList(searchQuery = "") {
        if (!contributorsListContainer) return;

        const q = searchQuery.trim().toLowerCase();
        let html = "";

        // 1. Render Document Owner
        if (accessData.owner) {
            const o = accessData.owner;
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; background: var(--surface-light);">
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                        <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;">
                            ${escapeHtml(o.name ? o.name.charAt(0).toUpperCase() : 'O')}
                        </div>
                        <div style="min-width: 0;">
                            <div style="font-size: 12.5px; font-weight: 600; color: var(--ink);">${escapeHtml(o.name)}</div>
                            <div style="font-size: 10.5px; color: var(--muted);">@${escapeHtml(o.username || '')}</div>
                        </div>
                    </div>
                    <span class="workspace-badge" style="font-size: 9.5px; background: var(--slate-soft); color: var(--slate-ink);">Owner</span>
                </div>
            `;
        }

        // 2. Render Existing Contributors
        const currentContribs = (accessData.eligible_users || []).filter(u => (accessData.contributor_ids || []).includes(parseInt(u.id, 10)));
        
        currentContribs.forEach(u => {
            const uId = parseInt(u.id, 10);
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 6px; background: var(--surface);">
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                        <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--success-badge-bg); color: var(--green-ink); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;">
                            ${escapeHtml(u.full_name ? u.full_name.charAt(0).toUpperCase() : 'U')}
                        </div>
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

        // 3. Search suggestions if user typed something
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
                                <div style="width: 26px; height: 26px; border-radius: 50%; background: var(--slate-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;">
                                    ${escapeHtml(u.full_name ? u.full_name.charAt(0).toUpperCase() : '+')}
                                </div>
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

        // Bind remove buttons
        contributorsListContainer.querySelectorAll(".btn-remove-contributor").forEach(btn => {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                const uid = parseInt(this.dataset.uid, 10);
                accessData.contributor_ids = accessData.contributor_ids.filter(id => id !== uid);
                renderContributorsList(searchContributorInput ? searchContributorInput.value : "");
            });
        });

        // Bind add buttons
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

    function openDocumentAccessModal() {
        if (!modalDocumentAccess) return;
        modalDocumentAccess.classList.add("is-open");

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
                } else {
                    if (contributorsListContainer) {
                        contributorsListContainer.innerHTML = `<div style="color:var(--red); padding:10px; text-align:center;">Failed to load access settings.</div>`;
                    }
                }
            })
            .catch(err => {
                console.error("Error fetching access data:", err);
            });
    }

    function closeDocumentAccessModal() {
        if (!modalDocumentAccess) return;
        modalDocumentAccess.classList.remove("is-open");
    }

    if (btnOpenDocAccessModal) btnOpenDocAccessModal.addEventListener("click", openDocumentAccessModal);
    if (btnCloseAccessModal) btnCloseAccessModal.addEventListener("click", closeDocumentAccessModal);
    if (btnCancelAccessModal) btnCancelAccessModal.addEventListener("click", closeDocumentAccessModal);

    if (modalDocumentAccess) {
        modalDocumentAccess.addEventListener("click", function (e) {
            if (e.target === modalDocumentAccess) closeDocumentAccessModal();
        });
    }

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

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }

    // --- 17. Document Audit Trail Controller (Sidebar Versions Tab Dropdown) ---
    let auditHistoryLoaded = false;

    function fetchAndRenderAuditHistory(force = false) {
        if (!auditHistoryFeed || (auditHistoryLoaded && !force) || !docId) return;

        fetch(`view.php?action=get_audit_history&id=${docId}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && auditHistoryFeed) {
                if (auditHistoryCountBadge) {
                    auditHistoryCountBadge.textContent = `${data.count || 0} ${data.count === 1 ? 'event' : 'events'}`;
                }
                auditHistoryFeed.innerHTML = data.html || '';
                auditHistoryLoaded = true;
            }
        })
        .catch(err => console.error("Error loading audit history:", err));
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

    // --- 16. Initialize Persistent Drawer & Tab State for this Document ---
    (function restoreDrawerState() {
        if (!docId) return;
        try {
            const savedTab = sessionStorage.getItem(`edms_drawer_tab_${docId}`);
            const savedOpen = sessionStorage.getItem(`edms_drawer_open_${docId}`);

            // If we are viewing a historical snapshot, or if the user previously had the versions tab open
            const isHistorical = Boolean(config.isHistoricalSnapshot);
            if (savedTab === "versions" || isHistorical) {
                switchSidePanelTab("versions", false);
            }

            if (savedOpen === "false") {
                if (workspaceRightDrawer) workspaceRightDrawer.classList.remove("is-open");
                if (btnToggleRightDrawer) btnToggleRightDrawer.classList.remove("is-active");
            } else if (savedOpen === "true" || isHistorical) {
                if (workspaceRightDrawer) workspaceRightDrawer.classList.add("is-open");
                if (btnToggleRightDrawer) btnToggleRightDrawer.classList.add("is-active");
            }
        } catch (e) {
            console.error("Error restoring drawer state:", e);
        }
    })();
});

