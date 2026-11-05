/**
 * EDMS Fullscreen Continuous Document Viewer & Multi-Layer Annotation Engine
 * Synchronizes and persists multi-user annotations to MySQL database
 */

document.addEventListener("DOMContentLoaded", function () {
    const config = window.EDMS_WORKSPACE_CONFIG || {};
    const docId = config.docId;
    const currentUserId = config.currentUserId || 1;
    const currentUserName = config.currentUserName || "You";

    // Synchronize browser address bar with the active version_id without reloading
    if (docId && config.activeVersionId && !window.location.search.includes("version_id=")) {
        try {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set("version_id", config.activeVersionId);
            window.history.replaceState(null, "", currentUrl.toString());
        } catch (e) {
            // Fallback for older browsers
            window.history.replaceState(null, "", `view.php?id=${docId}&version_id=${config.activeVersionId}`);
        }
    }
    
    // Configure PDF.js Worker
    if (window.pdfjsLib) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = "../assets/vendor/pdfjs/pdf.worker.min.js";
    }

    // DOM Elements
    const workspaceCenterStage = document.getElementById("workspaceCenterStage");
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
    const btnRedoStroke = document.getElementById("btnRedoStroke");
    const btnClearMyLayer = document.getElementById("btnClearMyLayer");
    const btnAddLayerGroup = document.getElementById("btnAddLayerGroup");
    const dockShapesMenu = document.getElementById("dockShapesMenu");
    const toolShapesBtn = document.getElementById("toolShapes");
    let redoStack = [];
    let hasLoggedAnnotationSession = false;
    
    // Right Drawer Elements
    const workspaceRightDrawer = document.getElementById("workspaceRightDrawer");
    const layerOpacitySlider = document.getElementById("layerOpacitySlider");
    const opacityDisplayVal = document.getElementById("opacityDisplayVal");
    const layersList = document.getElementById("layersList");
    const annotationHistoryFeed = document.getElementById("annotationHistoryFeed");
    const btnToggleVersionHistoryDropdown = document.getElementById("btnToggleVersionHistoryDropdown");
    const btnToggleAuditHistoryDropdown = document.getElementById("btnToggleAuditHistoryDropdown");
    const auditHistoryFeed = document.getElementById("auditHistoryFeed");
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
    let lastHoveredPage = 1;
    let currentStroke = null;

    // Multi-Layer Store: Keyed by layerId
    // { [layerId]: { layerId, userId, userName, layerName, color, visible: true, isSelf: bool, strokes: [] } }
    let annotationLayers = {};
    let activeLayerId = null;
    const versionQueryParam = config.activeVersionId ? `&version_id=${config.activeVersionId}` : '';

    // Cache for Stamp / Signature Image Annotations
    const stampImageCache = new Map();
    function getStampImage(src) {
        if (!src) return null;
        if (stampImageCache.has(src)) return stampImageCache.get(src);
        const img = new Image();
        img.crossOrigin = "anonymous";
        img.src = src;
        img.onload = () => { renderAllPagesAnnotations(); };
        stampImageCache.set(src, img);
        return img;
    }

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
                updateStrokeStats();
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
    } else if (renderMode === "pdf" && window.pdfjsLib) {
        const loadingTask = pdfjsLib.getDocument({
            url: streamUrl,
            cMapUrl: "https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/",
            cMapPacked: true,
            disableAutoFetch: true,
            disableStream: false,
            rangeChunkSize: 131072
        });
        loadingTask.onProgress = ({ loaded, total }) => {
            if (loadingStatusText && total > 0) {
                loadingStatusText.textContent = `Streaming document: ${Math.round((loaded / total) * 100)}%`;
            }
        };
        loadingTask.promise
        .then(pdf => {
            pdfDocument = pdf;
            totalPages = pdf.numPages;
            updatePageIndicator(1, totalPages);
            renderAllPdfPages().then(() => {
                fetchDatabaseAnnotations();
                if (window.initDocumentComments) window.initDocumentComments();
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
            if (window.initDocumentComments) window.initDocumentComments();
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
        if (window.renderPageGutter) window.renderPageGutter(1, pageWrapper);

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
                if (window.renderPageGutter) window.renderPageGutter(pageNum, sec);
            });

            if (workspaceDocLoading) workspaceDocLoading.classList.add("is-hidden");
            setupScrollObserver();
            fetchDatabaseAnnotations();
            if (window.initDocumentComments) window.initDocumentComments();
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
            if (window.renderPageGutter) window.renderPageGutter(pageNum, pageWrapper);
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
    let activeResizeHandle = -1;
    let isPanningStage = false;
    let panStartClient = null;
    let panStartScroll = null;
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

    // Global drag move for continuous cross-page object transformation & stage panning
    const onGlobalDrag = (e) => {
        if (isPanningStage && workspaceCenterStage && panStartClient) {
            const cx = e.clientX || (e.touches ? e.touches[0].clientX : 0);
            const cy = e.clientY || (e.touches ? e.touches[0].clientY : 0);
            workspaceCenterStage.scrollLeft = panStartScroll.left - (cx - panStartClient.x);
            workspaceCenterStage.scrollTop = panStartScroll.top - (cy - panStartClient.y);
            return;
        }
        if (activeTool !== "select" || !isDraggingObject || !selectedAnnotation) return;
        const p = e.touches ? e.touches[0] : e;
        const wrapper = document.elementFromPoint(p.clientX, p.clientY)?.closest(".pdf-page-wrapper, section.docx");
        const page = wrapper ? parseInt(wrapper.dataset.page, 10) : activeDrawingPage;
        const canvas = document.getElementById(`annoCanvas_${page}`);
        if (canvas) handleDrawMove(e, canvas, page);
    };
    window.addEventListener("mousemove", onGlobalDrag);
    window.addEventListener("touchmove", onGlobalDrag, { passive: false });

    function attachDrawingListeners(canvas, pageNum) {
        canvas.style.touchAction = "none";
        canvas.style.cursor = (activeTool === "pan") ? "grab" : ((activeTool === "select") ? "default" : (activeTool === "eraser" ? "cell" : "crosshair"));
        canvas.addEventListener("mouseenter", () => { lastHoveredPage = pageNum; });
        canvas.addEventListener("mousedown", (e) => handleStartDrawing(e, canvas, pageNum));
        canvas.addEventListener("mousemove", (e) => handleDrawMove(e, canvas, pageNum));
        canvas.addEventListener("touchstart", (e) => handleStartDrawing(e, canvas, pageNum), { passive: false });
        canvas.addEventListener("touchmove", (e) => handleDrawMove(e, canvas, pageNum), { passive: false });
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

    // Helper: Compute bounding box and corner handles for any annotation
    function getAnnotationBounds(item, canvasWidth, canvasHeight, extraTolerance = 0) {
        if (!item) return null;
        if (item.tool === "stamp" || item.tool === "image") {
            const x = item.xRel * canvasWidth, y = item.yRel * canvasHeight;
            const w = (item.wRel || 0.22) * canvasWidth, h = (item.hRel || 0.1) * canvasHeight;
            const pad = 4 + extraTolerance;
            return {
                x, y, w, h,
                minX: x - pad, minY: y - pad, maxX: x + w + pad, maxY: y + h + pad,
                corners: [[x, y], [x + w, y], [x, y + h], [x + w, y + h]]
            };
        }
        if (item.tool === "text") {
            const x = item.xRel * canvasWidth, y = item.yRel * canvasHeight;
            const lines = (item.content || "").split("\n");
            let maxW = 40;
            lines.forEach(l => { maxW = Math.max(maxW, l.length * 8.5 + 16); });
            const w = maxW, h = lines.length * 18 + 12;
            const pad = 4 + extraTolerance;
            return {
                x, y, w, h,
                minX: x - pad, minY: y - pad, maxX: x + w + pad, maxY: y + h + pad,
                corners: [[x, y], [x + w, y], [x, y + h], [x + w, y + h]]
            };
        }
        if (item.points && item.points.length >= 2) {
            let minPx = Infinity, minPy = Infinity, maxPx = -Infinity, maxPy = -Infinity;
            item.points.forEach(pt => {
                const px = pt.xRel * canvasWidth, py = pt.yRel * canvasHeight;
                if (px < minPx) minPx = px;
                if (px > maxPx) maxPx = px;
                if (py < minPy) minPy = py;
                if (py > maxPy) maxPy = py;
            });
            const pad = (["rect", "circle"].includes(item.tool) ? 6 : Math.max(6, item.width || 2)) + extraTolerance;
            return {
                x: minPx, y: minPy, w: maxPx - minPx, h: maxPy - minPy,
                minX: minPx - pad, minY: minPy - pad, maxX: maxPx + pad, maxY: maxPy + pad,
                corners: [[minPx, minPy], [maxPx, minPy], [minPx, maxPy], [maxPx, maxPy]]
            };
        }
        return null;
    }

    // Hit-Testing: Check if an annotation on pageNum is under cursor
    function hitTestAnnotation(pageNum, xRel, yRel, canvasWidth, canvasHeight, extraTolerance = 0) {
        const selfLayer = getActiveSelfLayer();
        if (!selfLayer || !selfLayer.visible || !Array.isArray(selfLayer.strokes)) return null;

        const pixelX = xRel * canvasWidth, pixelY = yRel * canvasHeight;

        for (let i = selfLayer.strokes.length - 1; i >= 0; i--) {
            const item = selfLayer.strokes[i];
            if (item.page !== pageNum) continue;

            const b = getAnnotationBounds(item, canvasWidth, canvasHeight, extraTolerance);
            if (!b) continue;

            if (["stamp", "image", "text", "rect", "circle"].includes(item.tool)) {
                if (pixelX >= b.minX && pixelX <= b.maxX && pixelY >= b.minY && pixelY <= b.maxY) {
                    return item;
                }
            } else if (item.points && item.points.length >= 2) {
                const tol = Math.max(10, (item.width || 2) * 2.0) + extraTolerance;
                for (let j = 0; j < item.points.length - 1; j++) {
                    const p1 = item.points[j], p2 = item.points[j + 1];
                    const d = distToSegment(pixelX, pixelY, p1.xRel * canvasWidth, p1.yRel * canvasHeight, p2.xRel * canvasWidth, p2.yRel * canvasHeight);
                    if (d <= tol) return item;
                }
            }
        }
        return null;
    }

    function eraseAnnotation(item, pageNum) {
        if (!item) return;
        const selfLayer = getActiveSelfLayer();
        if (!selfLayer || !Array.isArray(selfLayer.strokes)) return;

        const idx = selfLayer.strokes.indexOf(item);
        if (idx !== -1) {
            const deleted = selfLayer.strokes.splice(idx, 1)[0];
            if (selectedAnnotation === deleted) selectedAnnotation = null;
            updateStrokeStats();
            renderPageAnnotations(pageNum || deleted.page);
            if (deleted.id) {
                const delPayload = { id: deleted.id };
                if (!hasLoggedAnnotationSession) {
                    delPayload.is_first_session_stroke = true;
                    hasLoggedAnnotationSession = true;
                }
                fetch(`view.php?action=delete_stroke&id=${docId}${versionQueryParam}`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify(delPayload)
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.history_event) {
                        prependHistoryEvent(data.history_event);
                    } else {
                        fetchAnnotationHistory();
                    }
                })
                .catch(err => console.error("Error erasing stroke:", err));
            }
        }
    }

    function handleStartDrawing(e, canvas, pageNum) {
        if (dockShapesMenu) dockShapesMenu.style.display = "none";
        if (activeTool !== "pan" && e.cancelable) {
            e.preventDefault();
        }
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

        // --- VIEWPORT PANNING (HAND TOOL) ---
        if (activeTool === "pan") {
            isPanningStage = true;
            panStartClient = { x: e.clientX || (e.touches ? e.touches[0].clientX : 0), y: e.clientY || (e.touches ? e.touches[0].clientY : 0) };
            panStartScroll = { left: workspaceCenterStage ? workspaceCenterStage.scrollLeft : 0, top: workspaceCenterStage ? workspaceCenterStage.scrollTop : 0 };
            canvas.style.cursor = "grabbing";
            return;
        }

        // --- SELECT & TRANSFORM (CURSOR TOOL) ---
        if (activeTool === "select") {
            if (selectedAnnotation && selectedAnnotation.page === pageNum) {
                const b = getAnnotationBounds(selectedAnnotation, rect.width, rect.height);
                if (b && (selectedAnnotation.tool === "stamp" || selectedAnnotation.tool === "image" || selectedAnnotation.points)) {
                    const px = coords.xRel * rect.width, py = coords.yRel * rect.height;
                    const hIdx = b.corners.findIndex(([cx, cy]) => Math.hypot(px - cx, py - cy) < 14);
                    if (hIdx !== -1) {
                        activeResizeHandle = hIdx;
                        isDraggingObject = true;
                        hasDragged = false;
                        activeDrawingPage = pageNum;
                        return;
                    }
                }
            }
            activeResizeHandle = -1;

            const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height);
            if (hit) {
                selectedAnnotation = hit;
                isDraggingObject = true;
                hasDragged = false;
                activeDrawingPage = pageNum;
                dragStartCoords = coords;

                if (hit.tool === "text" || hit.tool === "stamp" || hit.tool === "image") {
                    dragInitialTextPos = { xRel: hit.xRel, yRel: hit.yRel };
                } else if (hit.points) {
                    dragInitialPoints = hit.points.map(pt => ({ xRel: pt.xRel, yRel: pt.yRel }));
                }

                canvas.style.cursor = "move";
                renderPageAnnotations(pageNum);
            } else {
                selectedAnnotation = null;
                renderAllPagesAnnotations();
            }
            return;
        }

        // --- ERASER TOOL ---
        if (activeTool === "eraser") {
            isDrawing = true;
            activeDrawingPage = pageNum;
            const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height, 8);
            if (hit) {
                eraseAnnotation(hit, pageNum);
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

        const isShape = ["rect", "circle", "arrow", "line"].includes(activeTool);
        currentStroke = {
            id: null,
            layerId: selfLayer.layerId,
            page: pageNum,
            tool: activeTool,
            color: activeColor,
            width: activeTool === "highlighter" ? activeStrokeWidth * 3.5 : activeStrokeWidth,
            alpha: activeTool === "highlighter" ? 0.35 : 1.0,
            points: isShape ? [coords, { ...coords }] : [coords]
        };

        selfLayer.strokes.push(currentStroke);
        redoStack = [];
        updateStrokeStats();
    }

    function handleDrawMove(e, canvas, pageNum) {
        if (isDrawing && e.cancelable) {
            e.preventDefault();
        }
        if (activeTool === "pan" && isDraggingObject && e.cancelable) {
            e.preventDefault();
        }

        const rect = canvas.getBoundingClientRect();
        const coords = getRelativePageCoords(e, canvas);

        // --- VIEWPORT PANNING (HAND TOOL) ---
        if (activeTool === "pan") {
            if (isPanningStage && workspaceCenterStage && panStartClient) {
                const cx = e.clientX || (e.touches ? e.touches[0].clientX : 0);
                const cy = e.clientY || (e.touches ? e.touches[0].clientY : 0);
                workspaceCenterStage.scrollLeft = panStartScroll.left - (cx - panStartClient.x);
                workspaceCenterStage.scrollTop = panStartScroll.top - (cy - panStartClient.y);
            }
            return;
        }

        // --- SELECT, TRANSLATE & STRETCH (CURSOR TOOL) ---
        if (activeTool === "select") {
            if (isDraggingObject && selectedAnnotation) {
                hasDragged = true;
                if (activeResizeHandle !== -1 && (selectedAnnotation.tool === "stamp" || selectedAnnotation.tool === "image")) {
                    const canvas = document.getElementById(`annoCanvas_${pageNum}`);
                    const cWidth = (canvas && canvas.width) ? canvas.width : rect.width;
                    const cHeight = (canvas && canvas.height) ? canvas.height : rect.height;
                    const pageRatio = (cHeight > 0) ? (cWidth / cHeight) : 0.707;
                    const img = getStampImage(selectedAnnotation.content || selectedAnnotation.dataUrl);
                    const naturalAspect = (img && img.naturalWidth && img.naturalHeight)
                        ? (img.naturalHeight / img.naturalWidth)
                        : (selectedAnnotation.aspectRatio || (((selectedAnnotation.hRel || 0.1) / (selectedAnnotation.wRel || 0.22)) / pageRatio));
                    const ratioFactor = naturalAspect * pageRatio;

                    const curR = selectedAnnotation.xRel + (selectedAnnotation.wRel || 0.22);
                    const curB = selectedAnnotation.yRel + (selectedAnnotation.hRel || 0.1);

                    if (activeResizeHandle === 3) {
                        // Bottom-Right handle
                        const dx = coords.xRel - selectedAnnotation.xRel;
                        const dy = (coords.yRel - selectedAnnotation.yRel) / ratioFactor;
                        let candW = Math.abs(dx) > Math.abs(dy) ? dx : dy;
                        let newW = Math.max(0.03, Math.min(1.0 - selectedAnnotation.xRel, candW));
                        let newH = newW * ratioFactor;
                        if (selectedAnnotation.yRel + newH > 1.0) {
                            newW = Math.max(0.03, (1.0 - selectedAnnotation.yRel) / ratioFactor);
                            newH = newW * ratioFactor;
                        }
                        selectedAnnotation.wRel = newW;
                        selectedAnnotation.hRel = newH;
                    } else if (activeResizeHandle === 0) {
                        // Top-Left handle
                        const dx = curR - coords.xRel;
                        const dy = (curB - coords.yRel) / ratioFactor;
                        let candW = Math.abs(dx) > Math.abs(dy) ? dx : dy;
                        let newW = Math.max(0.03, Math.min(curR, candW));
                        let newH = newW * ratioFactor;
                        if (curB - newH < 0) {
                            newW = Math.max(0.03, curB / ratioFactor);
                            newH = newW * ratioFactor;
                        }
                        selectedAnnotation.xRel = curR - newW;
                        selectedAnnotation.yRel = curB - newH;
                        selectedAnnotation.wRel = newW;
                        selectedAnnotation.hRel = newH;
                    } else if (activeResizeHandle === 1) {
                        // Top-Right handle
                        const dx = coords.xRel - selectedAnnotation.xRel;
                        const dy = (curB - coords.yRel) / ratioFactor;
                        let candW = Math.abs(dx) > Math.abs(dy) ? dx : dy;
                        let newW = Math.max(0.03, Math.min(1.0 - selectedAnnotation.xRel, candW));
                        let newH = newW * ratioFactor;
                        if (curB - newH < 0) {
                            newW = Math.max(0.03, curB / ratioFactor);
                            newH = newW * ratioFactor;
                        }
                        selectedAnnotation.yRel = curB - newH;
                        selectedAnnotation.wRel = newW;
                        selectedAnnotation.hRel = newH;
                    } else if (activeResizeHandle === 2) {
                        // Bottom-Left handle
                        const dx = curR - coords.xRel;
                        const dy = (coords.yRel - selectedAnnotation.yRel) / ratioFactor;
                        let candW = Math.abs(dx) > Math.abs(dy) ? dx : dy;
                        let newW = Math.max(0.03, Math.min(curR, candW));
                        let newH = newW * ratioFactor;
                        if (selectedAnnotation.yRel + newH > 1.0) {
                            newW = Math.max(0.03, (1.0 - selectedAnnotation.yRel) / ratioFactor);
                            newH = newW * ratioFactor;
                        }
                        selectedAnnotation.xRel = curR - newW;
                        selectedAnnotation.wRel = newW;
                        selectedAnnotation.hRel = newH;
                    }
                    schedulePageAnnotationRender(pageNum);
                    return;
                }
                if (activeResizeHandle !== -1 && selectedAnnotation.points) {
                    const pts = selectedAnnotation.points;
                    const p0 = pts[0], p1 = pts[pts.length - 1];
                    if (activeResizeHandle === 0) { p0.xRel = coords.xRel; p0.yRel = coords.yRel; }
                    else if (activeResizeHandle === 1) { p1.xRel = coords.xRel; p0.yRel = coords.yRel; }
                    else if (activeResizeHandle === 2) { p0.xRel = coords.xRel; p1.yRel = coords.yRel; }
                    else if (activeResizeHandle === 3) { p1.xRel = coords.xRel; p1.yRel = coords.yRel; }
                    schedulePageAnnotationRender(pageNum);
                    return;
                }

                // Check if cursor moved across page boundary into another page during dragging
                const clientX = e.clientX || (e.touches ? e.touches[0].clientX : 0);
                const clientY = e.clientY || (e.touches ? e.touches[0].clientY : 0);
                const hoveredWrapper = document.elementFromPoint(clientX, clientY)?.closest(".pdf-page-wrapper, section.docx");
                if (hoveredWrapper) {
                    const newPage = parseInt(hoveredWrapper.dataset.page, 10);
                    if (newPage && newPage !== selectedAnnotation.page) {
                        const oldPage = selectedAnnotation.page;
                        const newCanvas = document.getElementById(`annoCanvas_${newPage}`);
                        if (newCanvas) {
                            const newRect = newCanvas.getBoundingClientRect();
                            const newXRel = Math.max(0, Math.min(1, (clientX - newRect.left) / newRect.width));
                            const newYRel = Math.max(0, Math.min(1, (clientY - newRect.top) / newRect.height));

                            selectedAnnotation.page = newPage;
                            activeDrawingPage = newPage;
                            dragStartCoords = { xRel: newXRel, yRel: newYRel };

                            if (selectedAnnotation.tool === "stamp" || selectedAnnotation.tool === "image") {
                                dragInitialTextPos = {
                                    xRel: Math.max(0, Math.min(0.95, newXRel - (selectedAnnotation.wRel || 0.22) / 2)),
                                    yRel: Math.max(0, Math.min(0.95, newYRel - (selectedAnnotation.hRel || 0.1) / 2))
                                };
                                selectedAnnotation.xRel = dragInitialTextPos.xRel;
                                selectedAnnotation.yRel = dragInitialTextPos.yRel;
                            } else if (selectedAnnotation.tool === "text") {
                                dragInitialTextPos = {
                                    xRel: Math.max(0, Math.min(0.95, newXRel)),
                                    yRel: Math.max(0, Math.min(0.95, newYRel))
                                };
                                selectedAnnotation.xRel = dragInitialTextPos.xRel;
                                selectedAnnotation.yRel = dragInitialTextPos.yRel;
                            }

                            renderPageAnnotations(oldPage);
                            renderPageAnnotations(newPage);
                            return;
                        }
                    }
                }

                const dx = coords.xRel - dragStartCoords.xRel;
                const dy = coords.yRel - dragStartCoords.yRel;

                if ((selectedAnnotation.tool === "text" || selectedAnnotation.tool === "stamp" || selectedAnnotation.tool === "image") && dragInitialTextPos) {
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
                if (selectedAnnotation && selectedAnnotation.page === pageNum) {
                    const b = getAnnotationBounds(selectedAnnotation, rect.width, rect.height);
                    if (b && (selectedAnnotation.tool === "stamp" || selectedAnnotation.tool === "image" || selectedAnnotation.points)) {
                        const px = coords.xRel * rect.width, py = coords.yRel * rect.height;
                        const hIdx = b.corners.findIndex(([cx, cy]) => Math.hypot(px - cx, py - cy) < 14);
                        if (hIdx !== -1) {
                            canvas.style.cursor = (hIdx === 0 || hIdx === 3) ? "nwse-resize" : "nesw-resize";
                            return;
                        }
                    }
                }
                const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height);
                canvas.style.cursor = hit ? "move" : "default";
            }
            return;
        }

        // --- ERASER BRUSH SWEEP ---
        if (activeTool === "eraser") {
            canvas.style.cursor = "cell";
            if (isDrawing) {
                const hit = hitTestAnnotation(pageNum, coords.xRel, coords.yRel, rect.width, rect.height, 8);
                if (hit) {
                    eraseAnnotation(hit, pageNum);
                }
            }
            return;
        }

        // --- DRAWING FREEHAND / HIGHLIGHTER / SHAPES ---
        if (!isDrawing || !currentStroke || activeDrawingPage !== pageNum) return;
        if (["rect", "circle", "arrow", "line"].includes(currentStroke.tool)) {
            currentStroke.points[1] = coords;
        } else {
            currentStroke.points.push(coords);
        }
        schedulePageAnnotationRender(pageNum);
    }

    function handleStopDrawing() {
        if (drawRafId) {
            cancelAnimationFrame(drawRafId);
            drawRafId = null;
        }

        if (activeTool === "eraser") {
            isDrawing = false;
            return;
        }

        if (activeTool === "pan" || isPanningStage) {
            if (isPanningStage) {
                isPanningStage = false;
                if (workspaceCenterStage) {
                    workspaceCenterStage.style.cursor = (activeTool === "pan") ? "grab" : "";
                }
                const canvas = document.getElementById(`annoCanvas_${activeDrawingPage}`);
                if (canvas) canvas.style.cursor = (activeTool === "pan") ? "grab" : ((activeTool === "select") ? "default" : (activeTool === "eraser" ? "cell" : "crosshair"));
            }
            return;
        }

        if (activeTool === "select") {
            if (isDraggingObject) {
                isDraggingObject = false;
                activeResizeHandle = -1;
                const canvas = document.getElementById(`annoCanvas_${activeDrawingPage}`);
                if (canvas) canvas.style.cursor = "default";

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
            if (["rect", "circle", "arrow", "line"].includes(strokeToSave.tool)) {
                const p0 = strokeToSave.points[0];
                const p1 = strokeToSave.points[1];
                if (!p1 || Math.hypot(p1.xRel - p0.xRel, p1.yRel - p0.yRel) < 0.005) {
                    const selfLayer = getActiveSelfLayer();
                    if (selfLayer && Array.isArray(selfLayer.strokes)) {
                        const idx = selfLayer.strokes.indexOf(strokeToSave);
                        if (idx !== -1) selfLayer.strokes.splice(idx, 1);
                    }
                    updateStrokeStats();
                    renderPageAnnotations(finishedPage);
                    return;
                }
            }
            renderPageAnnotations(finishedPage);
            persistStrokeToDatabase(strokeToSave);
        }
    }

    // Keyboard Handler: Delete selected object on Backspace / Delete & Undo / Redo
    window.addEventListener("keydown", function (e) {
        if (e.target && (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA")) return;

        if ((e.key === "Delete" || e.key === "Backspace") && selectedAnnotation) {
            e.preventDefault();
            eraseAnnotation(selectedAnnotation, selectedAnnotation.page);
        } else if ((e.ctrlKey || e.metaKey) && (e.key === "z" || e.key === "Z")) {
            e.preventDefault();
            if (e.shiftKey) {
                if (btnRedoStroke) btnRedoStroke.click();
            } else {
                if (btnUndoStroke) btnUndoStroke.click();
            }
        } else if ((e.ctrlKey || e.metaKey) && (e.key === "y" || e.key === "Y")) {
            e.preventDefault();
            if (btnRedoStroke) btnRedoStroke.click();
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
            redoStack = [];
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
        const payload = { ...strokeObj };
        if (!hasLoggedAnnotationSession) {
            payload.is_first_session_stroke = true;
            hasLoggedAnnotationSession = true;
        }

        fetch(`view.php?action=save_stroke&id=${docId}${versionQueryParam}`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: JSON.stringify(payload)
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

    function drawStrokeGeometry(ctx, stroke, width, height, alphaMult = 1.0) {
        const pts = stroke.points;
        if (!pts || pts.length < 2) return;
        ctx.save();
        ctx.strokeStyle = stroke.color;
        ctx.lineWidth = stroke.width;
        ctx.lineCap = ctx.lineJoin = "round";
        ctx.globalAlpha = (stroke.alpha || 1.0) * alphaMult;
        const x0 = pts[0].xRel * width, y0 = pts[0].yRel * height;
        const x1 = pts[pts.length - 1].xRel * width, y1 = pts[pts.length - 1].yRel * height;
        if (stroke.tool === "rect") {
            ctx.strokeRect(x0, y0, x1 - x0, y1 - y0);
        } else if (stroke.tool === "circle") {
            ctx.beginPath();
            ctx.ellipse((x0 + x1) / 2, (y0 + y1) / 2, Math.max(1, Math.abs(x1 - x0) / 2), Math.max(1, Math.abs(y1 - y0) / 2), 0, 0, Math.PI * 2);
            ctx.stroke();
        } else if (stroke.tool === "arrow" || stroke.tool === "line") {
            ctx.beginPath();
            ctx.moveTo(x0, y0); ctx.lineTo(x1, y1);
            if (stroke.tool === "arrow") {
                const angle = Math.atan2(y1 - y0, x1 - x0), head = Math.max(9, stroke.width * 3.5);
                ctx.moveTo(x1, y1); ctx.lineTo(x1 - head * Math.cos(angle - 0.52), y1 - head * Math.sin(angle - 0.52));
                ctx.moveTo(x1, y1); ctx.lineTo(x1 - head * Math.cos(angle + 0.52), y1 - head * Math.sin(angle + 0.52));
            }
            ctx.stroke();
        } else {
            ctx.beginPath();
            ctx.moveTo(x0, y0);
            for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i].xRel * width, pts[i].yRel * height);
            ctx.stroke();
        }
        ctx.restore();
    }

    // Helper: Unified Annotation Renderer (Shared between on-screen view and clean PDF/Image export)
    function drawAnnotationItem(ctx, stroke, canvasW, canvasH, layerOpacity = 1.0) {
        if (!stroke) return;
        if (stroke.tool === "stamp" || stroke.tool === "image") {
            const posX = stroke.xRel * canvasW;
            const posY = stroke.yRel * canvasH;
            const stampW = (stroke.wRel || 0.22) * canvasW;
            const stampH = (stroke.hRel || 0.1) * canvasH;

            const img = getStampImage(stroke.content || stroke.dataUrl);
            if (img && img.complete && img.naturalWidth > 0) {
                ctx.save();
                ctx.globalAlpha = layerOpacity;
                ctx.drawImage(img, posX, posY, stampW, stampH);
                ctx.restore();
            }
            return;
        }

        if (stroke.tool === "text") {
            const posX = stroke.xRel * canvasW;
            const posY = stroke.yRel * canvasH;
            const lines = (stroke.content || "").split("\n");

            ctx.save();
            ctx.globalAlpha = layerOpacity;
            ctx.font = "bold 13px 'Plus Jakarta Sans', sans-serif";
            const lineHeight = 18, padding = 8;

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

        // Brush / Highlighter / Shapes
        drawStrokeGeometry(ctx, stroke, canvasW, canvasH, layerOpacity);
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
                if (stroke.page === pageNum) {
                    drawAnnotationItem(ctx, stroke, width, height);
                }
            });
        });

        // Render Selection Bounding Box & Handles if an annotation is selected on this page
        if (selectedAnnotation && selectedAnnotation.page === pageNum) {
            const b = getAnnotationBounds(selectedAnnotation, width, height);
            if (b && b.maxX > b.minX && b.maxY > b.minY) {
                ctx.save();
                ctx.strokeStyle = "#2563eb";
                ctx.lineWidth = 1.5;
                ctx.setLineDash([4, 4]);
                ctx.strokeRect(b.minX, b.minY, b.maxX - b.minX, b.maxY - b.minY);

                // Corner anchor points
                ctx.setLineDash([]);
                ctx.fillStyle = "#ffffff";
                ctx.strokeStyle = "#2563eb";
                ctx.lineWidth = 2;
                b.corners.forEach(([cx, cy]) => {
                    ctx.beginPath();
                    ctx.arc(cx, cy, 4, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.stroke();
                });
                ctx.restore();
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
                if (stroke.page === pageNum) {
                    drawAnnotationItem(ctx, stroke, targetWidth, targetHeight, layer.opacity ?? 1.0);
                }
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
                updateStrokeStats();
            });

            // Content container holding all layer items for this user
            const contentDiv = document.createElement("div");
            contentDiv.className = "user-layers-content";

            uGroup.layers.forEach(layer => {
                const lid = layer.layerId;
                const canEditSelf = isSelf && canAnnotate && !isAnnotationLocked;
                const isSelected = canEditSelf && (parseInt(lid, 10) === parseInt(activeLayerId, 10));

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
    const btnToggleHistoryAccordion = document.getElementById("btnToggleHistoryAccordion");
    if (btnToggleHistoryAccordion && annotationHistoryFeed) {
        btnToggleHistoryAccordion.addEventListener("click", function () {
            const isCurrentlyCollapsed = annotationHistoryFeed.classList.contains("is-collapsed");
            annotationHistoryFeed.classList.toggle("is-collapsed", !isCurrentlyCollapsed);
            this.classList.toggle("is-open", isCurrentlyCollapsed);
        });
    }

    // --- 8. Continuous Scroll & Stage Centering Observer ---
    function centerStageHorizontally(smooth = false) {
        if (!workspaceCenterStage) return;
        if (window.innerWidth <= 768) {
            // On mobile, keep stage anchored at 0 so document left margin is never cut off
            workspaceCenterStage.scrollLeft = 0;
            return;
        }
        const maxScroll = workspaceCenterStage.scrollWidth - workspaceCenterStage.clientWidth;
        if (maxScroll > 0) {
            const targetX = Math.round(maxScroll / 2);
            if (smooth) {
                workspaceCenterStage.scrollTo({ left: targetX, behavior: "smooth" });
            } else {
                workspaceCenterStage.scrollLeft = targetX;
            }
        }
    }

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
        setTimeout(() => centerStageHorizontally(false), 60);
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
            const cs = window.getComputedStyle ? window.getComputedStyle(workspaceCenterStage) : null;
            const padL = cs ? (parseFloat(cs.paddingLeft) || 32) : 32;
            const padR = cs ? (parseFloat(cs.paddingRight) || 32) : 32;
            const isMobile = window.innerWidth <= 768;
            const horizontalBuffer = isMobile ? 8 : 40;
            const containerWidth = Math.max(isMobile ? 240 : 300, workspaceCenterStage.clientWidth - padL - padR - horizontalBuffer);
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

    // Intercept Mouse Wheel Zoom (Ctrl + Wheel) & Sideways Scroll (Shift + Wheel)
    window.addEventListener("wheel", function (e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            if (e.deltaY < 0) {
                setZoom(currentZoomPercent + 10);
            } else if (e.deltaY > 0) {
                setZoom(currentZoomPercent - 10);
            }
        } else if (e.shiftKey && workspaceCenterStage) {
            e.preventDefault();
            workspaceCenterStage.scrollLeft += (e.deltaY || e.deltaX);
        }
    }, { passive: false });

    // Allow panning directly by dragging the stage background in Pan mode or with middle-click
    if (workspaceCenterStage) {
        workspaceCenterStage.addEventListener("mousedown", (e) => {
            if (activeTool === "pan" || e.button === 1) {
                isPanningStage = true;
                panStartClient = { x: e.clientX, y: e.clientY };
                panStartScroll = { left: workspaceCenterStage.scrollLeft, top: workspaceCenterStage.scrollTop };
                workspaceCenterStage.style.cursor = "grabbing";
                e.preventDefault();
            }
        });
    }

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
        if (btnRedoStroke) {
            btnRedoStroke.disabled = true;
            btnRedoStroke.style.opacity = "0.35";
            btnRedoStroke.style.cursor = "not-allowed";
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
        document.querySelectorAll(".shape-opt").forEach(b => { b.disabled = true; b.style.opacity = "0.35"; });
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
            if (dockShapesMenu) dockShapesMenu.style.display = (this.id === "toolShapes" && dockShapesMenu.style.display !== "flex") ? "flex" : "none";

            const annoCanvases = document.querySelectorAll(".pdf-annotation-canvas");
            const cur = (activeTool === "pan") ? "grab" : ((activeTool === "select") ? "default" : (activeTool === "eraser" ? "cell" : "crosshair"));
            annoCanvases.forEach(c => {
                c.style.pointerEvents = "auto";
                c.style.cursor = cur;
            });
            if (workspaceCenterStage) workspaceCenterStage.style.cursor = cur;
        });
    });

    document.querySelectorAll(".shape-opt").forEach(btn => {
        btn.onclick = function (e) {
            e.stopPropagation();
            if (toolShapesBtn) {
                toolShapesBtn.dataset.tool = this.dataset.shape;
                const icon = document.getElementById("toolShapesIcon");
                if (icon) icon.innerHTML = this.innerHTML;
                toolShapesBtn.click();
            }
            if (dockShapesMenu) dockShapesMenu.style.display = "none";
        };
    });

    document.addEventListener("click", e => {
        const grp = document.getElementById("dockShapesGroup");
        if (grp && !grp.contains(e.target) && dockShapesMenu) dockShapesMenu.style.display = "none";
    });

    // Image / Signature Tool & Clipboard Paste
    const toolStamp = document.getElementById("toolStamp");
    const stampFileInput = document.getElementById("stampFileInput");

    function getCurrentViewportPage() {
        if (lastHoveredPage && lastHoveredPage > 0) return lastHoveredPage;
        if (!workspaceCenterStage) return currentVisiblePage || 1;
        const mid = workspaceCenterStage.getBoundingClientRect().top + workspaceCenterStage.clientHeight / 2;
        let best = currentVisiblePage || 1, min = Infinity;
        document.querySelectorAll(".pdf-page-wrapper, section.docx").forEach(el => {
            const p = parseInt(el.dataset.page, 10), r = el.getBoundingClientRect();
            const d = Math.abs(r.top + r.height / 2 - mid);
            if (d < min) { min = d; best = p; }
        });
        return best;
    }

    function placeImageStampFromFile(file) {
        if (!file || !file.type.startsWith("image/")) return;
        const reader = new FileReader();
        reader.onload = (evt) => {
            const dataUrl = evt.target.result;
            const img = new Image();
            img.onload = () => {
                const selfLayer = getActiveSelfLayer();
                if (!selfLayer) return;
                const page = getCurrentViewportPage();
                const canvas = document.getElementById(`annoCanvas_${page}`);
                const cWidth = (canvas && canvas.width) ? canvas.width : 800;
                const cHeight = (canvas && canvas.height) ? canvas.height : 1131;
                const pageRatio = (cHeight > 0) ? (cWidth / cHeight) : 0.707;
                const aspect = (img.naturalWidth > 0) ? (img.naturalHeight / img.naturalWidth) : 1;

                let wRel = 0.24;
                let hRel = wRel * aspect * pageRatio;
                if (hRel > 0.35) {
                    hRel = 0.35;
                    wRel = Math.max(0.04, hRel / (aspect * pageRatio));
                }

                const stamp = {
                    tool: "stamp", page,
                    xRel: Math.max(0.05, 0.5 - wRel / 2),
                    yRel: Math.max(0.05, 0.45 - hRel / 2),
                    wRel, hRel, content: dataUrl,
                    aspectRatio: aspect,
                    color: activeColor, layerId: selfLayer.layerId
                };
                (selfLayer.strokes = selfLayer.strokes || []).push(stamp);
                selectedAnnotation = stamp;
                activeTool = "select";
                dockButtons.forEach(b => b.classList.remove("is-active"));
                document.getElementById("toolSelect")?.classList.add("is-active");
                stampImageCache.set(dataUrl, img);
                renderPageAnnotations(page);
                persistStrokeToDatabase(stamp);
                updateStrokeStats();
            };
            img.src = dataUrl;
        };
        reader.readAsDataURL(file);
    }

    if (toolStamp && stampFileInput) {
        toolStamp.addEventListener("click", () => {
            if (!isAnnotationLocked) stampFileInput.click();
        });
        stampFileInput.addEventListener("change", (e) => {
            const file = e.target.files && e.target.files[0];
            if (file) placeImageStampFromFile(file);
            stampFileInput.value = "";
        });
    }

    // Intercept Clipboard Image Paste (Ctrl + V)
    window.addEventListener("paste", (e) => {
        if (!canAnnotate || isAnnotationLocked) return;
        if (["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName)) return;
        const items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf("image") !== -1) {
                const file = items[i].getAsFile();
                if (file) { e.preventDefault(); placeImageStampFromFile(file); break; }
            }
        }
    });

    // Color Swatches
    colorSwatches.forEach(swatch => {
        swatch.addEventListener("click", function () {
            if (isAnnotationLocked) return;
            colorSwatches.forEach(s => s.classList.remove("is-selected"));
            this.classList.add("is-selected");
            activeColor = this.dataset.color || "#16a34a";
            const selfLayer = getActiveSelfLayer();
            if (selfLayer && (!selfLayer.strokes || selfLayer.strokes.length === 0)) {
                selfLayer.color = activeColor;
                const dot = document.querySelector(`#layerRow_${selfLayer.layerId} .layer-color-indicator`);
                if (dot) dot.style.backgroundColor = activeColor;
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

    // --- 11. Undo, Redo & Clear Actions ---
    if (btnUndoStroke) {
        btnUndoStroke.addEventListener("click", function () {
            const selfLayer = getActiveSelfLayer();
            if (selfLayer && selfLayer.strokes.length > 0) {
                const popped = selfLayer.strokes.pop();
                redoStack.push(popped);
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

    if (btnRedoStroke) {
        btnRedoStroke.addEventListener("click", function () {
            if (isAnnotationLocked || redoStack.length === 0) return;
            const selfLayer = getActiveSelfLayer();
            if (!selfLayer) return;

            const restored = redoStack.pop();
            if (!restored) return;

            restored.id = null;
            selfLayer.strokes.push(restored);
            updateStrokeStats();
            if (restored.page) renderPageAnnotations(restored.page);
            persistStrokeToDatabase(restored);
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
                updateStrokeStats();

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
        if (!isAnnotationLocked) {
            const hasRedo = redoStack && redoStack.length > 0;
            if (btnRedoStroke) {
                btnRedoStroke.disabled = !hasRedo;
                btnRedoStroke.style.opacity = hasRedo ? "1" : "0.35";
                btnRedoStroke.style.cursor = hasRedo ? "pointer" : "default";
            }
            const selfLayer = getActiveSelfLayer();
            const hasUndo = selfLayer && selfLayer.strokes && selfLayer.strokes.length > 0;
            if (btnUndoStroke) {
                btnUndoStroke.disabled = !hasUndo;
                btnUndoStroke.style.opacity = hasUndo ? "1" : "0.35";
                btnUndoStroke.style.cursor = hasUndo ? "pointer" : "default";
            }
        }
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
            if (typeof window.loadVersionHistory === "function") {
                window.loadVersionHistory();
            }
            if (typeof window.fetchAndRenderAuditHistory === "function") {
                window.fetchAndRenderAuditHistory(false);
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

    window.switchViewerSidePanelTab = switchSidePanelTab;
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
                        fetch(`view.php?id=${docId}&action=log_export_annotated`, {
                            method: "POST",
                            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                            body: JSON.stringify({ filename: downloadFileName, format: outExt, version_id: versionId })
                        }).catch(() => {});
                    }, mimeType, 0.95);
                } else {
                    // Export Native PDF with annotations (Load PDF-Lib on-demand)
                    if (typeof PDFLib === "undefined" || !PDFLib.PDFDocument) {
                        await new Promise((resolve, reject) => {
                            const script = document.createElement("script");
                            script.src = "../assets/vendor/pdf-lib.min.js";
                            script.onload = () => resolve();
                            script.onerror = () => reject(new Error("Failed to load PDF export engine."));
                            document.head.appendChild(script);
                        });
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
                    fetch(`view.php?id=${docId}&action=log_export_annotated`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                        body: JSON.stringify({ filename: downloadFileName, format: "pdf", version_id: versionId })
                    }).catch(() => {});
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

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
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

            if (savedOpen === "false" || (window.innerWidth <= 768 && savedOpen === null)) {
                if (workspaceRightDrawer) workspaceRightDrawer.classList.remove("is-open");
                if (btnToggleRightDrawer) btnToggleRightDrawer.classList.remove("is-active");
            } else if (savedOpen === "true" || isHistorical) {
                if (workspaceRightDrawer) workspaceRightDrawer.classList.add("is-open");
                if (btnToggleRightDrawer) btnToggleRightDrawer.classList.add("is-active");
            }
        } catch (e) {
            console.error("Error restoring drawer state:", e);
        }

        // Close off-canvas right drawer when tapping on workspaceCenterStage on mobile
        if (workspaceCenterStage) {
            workspaceCenterStage.addEventListener("click", function (e) {
                if (window.innerWidth <= 768 && workspaceRightDrawer && workspaceRightDrawer.classList.contains("is-open")) {
                    if (!workspaceRightDrawer.contains(e.target) && !e.target.closest("#btnToggleRightDrawerEdge")) {
                        workspaceRightDrawer.classList.remove("is-open");
                        if (btnToggleRightDrawer) btnToggleRightDrawer.classList.remove("is-active");
                    }
                }
            });
        }

        // Automatic responsive fit-to-width on orientation change or viewport resize
        let viewerResizeTimer = null;
        window.addEventListener("resize", function () {
            clearTimeout(viewerResizeTimer);
            viewerResizeTimer = setTimeout(function () {
                if (window.innerWidth <= 768 && btnZoomFit) {
                    btnZoomFit.click();
                }
            }, 250);
        });

        window.addEventListener("orientationchange", function () {
            setTimeout(function () {
                if (btnZoomFit) btnZoomFit.click();
            }, 250);
        });

        // One-time initial fit to width on mobile viewport
        if (window.innerWidth <= 768 && btnZoomFit) {
            setTimeout(function () {
                btnZoomFit.click();
            }, 300);
        }
    })();
});

