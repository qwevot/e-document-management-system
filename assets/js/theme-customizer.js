/**
 * EDMS Wallpaper & Canvas Customizer Engine
 * Self-contained module: manages continuous wallpapers across sidebar and topbar,
 * including a client-side selectable gallery of custom uploads.
 */
(function() {
    "use strict";

    const STORAGE_KEY_WALLPAPER = "edms_sidebar_wallpaper";
    const STORAGE_KEY_CUSTOM_LIST = "edms_custom_wallpapers_list";
    const STORAGE_KEY_LEGACY_DATA = "edms_custom_wallpaper_data";
    const STORAGE_KEY_BLUR = "edms_wallpaper_blur";
    const MAX_CUSTOM_WALLPAPERS = 6;

    const WALLPAPER_PRESETS = {
        "default": null,
        "preset-forest": "https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=800&q=75",
        "preset-tech": "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=75",
        "preset-aurora": "https://images.unsplash.com/photo-1531366936337-7c912a4589a7?auto=format&fit=crop&w=800&q=75",
        "preset-peaks": "https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=75",
        "preset-carbon": "https://images.unsplash.com/photo-1550684848-fac1c5b4e853?auto=format&fit=crop&w=800&q=75"
    };

    function getCustomWallpapersList() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY_CUSTOM_LIST);
            let list = raw ? JSON.parse(raw) : [];

            // Backward compatibility: migrate legacy single wallpaper once, then delete legacy key
            const legacyData = localStorage.getItem(STORAGE_KEY_LEGACY_DATA);
            if (legacyData) {
                if (!list.some(item => item.id === "custom_legacy")) {
                    list.unshift({
                        id: "custom_legacy",
                        name: "Upload 1",
                        dataUrl: legacyData,
                        createdAt: Date.now()
                    });
                    localStorage.setItem(STORAGE_KEY_CUSTOM_LIST, JSON.stringify(list));
                }
                // Critical: Purge the legacy key so it cannot regenerate after deletion
                try { localStorage.removeItem(STORAGE_KEY_LEGACY_DATA); } catch (e) {}
            }
            return Array.isArray(list) ? list : [];
        } catch (e) {
            console.error("[Theme] Failed to parse custom wallpapers list:", e);
            return [];
        }
    }

    function saveCustomWallpapersList(list) {
        try {
            localStorage.setItem(STORAGE_KEY_CUSTOM_LIST, JSON.stringify(list));
        } catch (e) {
            console.warn("[Theme] LocalStorage write error:", e);
        }
    }

    function isGlassBlurEnabled() {
        return localStorage.getItem(STORAGE_KEY_BLUR) !== "off";
    }

    function applyGlassBlur(enabled) {
        if (enabled) {
            document.documentElement.classList.remove("no-glass-blur");
        } else {
            document.documentElement.classList.add("no-glass-blur");
        }
    }

    function toggleWallpaperBlur(enabled) {
        localStorage.setItem(STORAGE_KEY_BLUR, enabled ? "on" : "off");
        applyGlassBlur(enabled);
    }

    function initThemeCustomizer() {
        // Enforce light mode at all times
        document.documentElement.removeAttribute("data-theme");
        try { localStorage.removeItem("edms_theme_mode"); } catch (e) {}

        // Apply glass blur preference
        applyGlassBlur(isGlassBlurEnabled());

        const savedWallpaper = localStorage.getItem(STORAGE_KEY_WALLPAPER) || "default";
        applySidebarWallpaper(savedWallpaper);
    }

    function applySidebarWallpaper(key) {
        const sidebar = document.querySelector(".sidebar");
        const topbar = document.querySelector(".topbar");

        let bgUrl = null;

        if (WALLPAPER_PRESETS[key]) {
            bgUrl = WALLPAPER_PRESETS[key];
        } else if (key && key.startsWith("custom")) {
            const list = getCustomWallpapersList();
            const item = list.find(w => w.id === key) || list[0];
            if (item && item.dataUrl) {
                bgUrl = item.dataUrl;
            }
        }

        if (bgUrl) {
            document.documentElement.style.setProperty("--custom-wallpaper", `url("${bgUrl}")`);
            if (sidebar) sidebar.classList.add("has-custom-wallpaper");
            if (topbar) topbar.classList.add("has-custom-wallpaper");
        } else {
            document.documentElement.style.removeProperty("--custom-wallpaper");
            if (sidebar) sidebar.classList.remove("has-custom-wallpaper");
            if (topbar) topbar.classList.remove("has-custom-wallpaper");
        }

        // Update active highlight in modal
        document.querySelectorAll(".wallpaper-card").forEach(card => {
            if (card.dataset.wallpaper === key) {
                card.classList.add("active");
            } else {
                card.classList.remove("active");
            }
        });
    }

    function setSidebarWallpaper(key) {
        localStorage.setItem(STORAGE_KEY_WALLPAPER, key);
        applySidebarWallpaper(key);
    }

    function deleteCustomWallpaper(id, e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        // Guarantee legacy key eradication if the legacy upload is being removed
        if (id === "custom_legacy") {
            try { localStorage.removeItem(STORAGE_KEY_LEGACY_DATA); } catch (err) {}
        }

        let list = getCustomWallpapersList();
        list = list.filter(w => w.id !== id);
        saveCustomWallpapersList(list);

        const currentActive = localStorage.getItem(STORAGE_KEY_WALLPAPER);
        if (currentActive === id) {
            // Revert to default if active image was deleted
            setSidebarWallpaper("default");
        }

        renderCustomWallpaperGallery();
    }

    function renderCustomWallpaperGallery() {
        const section = document.getElementById("myUploadsSection");
        const grid = document.getElementById("myUploadsGrid");
        if (!section || !grid) return;

        const list = getCustomWallpapersList();
        const activeKey = localStorage.getItem(STORAGE_KEY_WALLPAPER) || "default";

        if (list.length === 0) {
            section.style.display = "none";
            grid.innerHTML = "";
            return;
        }

        section.style.display = "block";
        grid.innerHTML = list.map((item, index) => {
            const isActive = (item.id === activeKey);
            const safeName = escapeHtml(item.name || ("Upload " + (index + 1)));
            return `
                <div class="wallpaper-card ${isActive ? 'active' : ''}" data-wallpaper="${item.id}" onclick="setSidebarWallpaper('${item.id}')" style="background-image: url('${item.dataUrl}');">
                    <button type="button" class="wallpaper-delete-btn" onclick="event.stopPropagation(); deleteCustomWallpaper('${item.id}', event);" title="Hapus wallpaper ini" aria-label="Delete">
                        &times;
                    </button>
                    <span class="wallpaper-card-label">${safeName}</span>
                </div>
            `;
        }).join("");
    }

    function handleCustomWallpaperUpload(e) {
        const file = e.target.files && e.target.files[0];
        if (!file) return;

        if (!file.type.match(/^image\/(png|jpeg|jpg|webp)$/i)) {
            alert("Harap pilih berkas gambar yang valid (JPG, PNG, atau WEBP).");
            return;
        }

        if (file.size > 8 * 1024 * 1024) {
            alert("Ukuran gambar maksimal adalah 8MB.");
            return;
        }

        const rawFileName = file.name ? file.name.replace(/\.[^/.]+$/, "").substring(0, 16) : "Upload";

        const reader = new FileReader();
        reader.onload = function(evt) {
            const img = new Image();
            img.onload = function() {
                // Downscale to 1280px max dimension with high visual fidelity and lightweight storage
                const canvas = document.createElement("canvas");
                let width = img.width;
                let height = img.height;
                const maxDim = 1280;

                if (width > maxDim || height > maxDim) {
                    if (width > height) {
                        height = Math.round((height * maxDim) / width);
                        width = maxDim;
                    } else {
                        width = Math.round((width * maxDim) / height);
                        height = maxDim;
                    }
                }

                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, width, height);

                const optimizedDataUrl = canvas.toDataURL("image/jpeg", 0.78);

                let list = getCustomWallpapersList();
                const newId = "custom_" + Date.now();
                const newItem = {
                    id: newId,
                    name: rawFileName,
                    dataUrl: optimizedDataUrl,
                    createdAt: Date.now()
                };

                // Keep up to MAX_CUSTOM_WALLPAPERS items
                list.unshift(newItem);
                if (list.length > MAX_CUSTOM_WALLPAPERS) {
                    list = list.slice(0, MAX_CUSTOM_WALLPAPERS);
                }

                saveCustomWallpapersList(list);
                setSidebarWallpaper(newId);
                renderCustomWallpaperGallery();

                // Clear input value so same file can be re-selected if desired
                e.target.value = "";
            };
            img.src = evt.target.result;
        };
        reader.readAsDataURL(file);
    }

    function resetThemeDefaults() {
        localStorage.removeItem(STORAGE_KEY_WALLPAPER);
        localStorage.removeItem(STORAGE_KEY_BLUR);
        try { localStorage.removeItem(STORAGE_KEY_LEGACY_DATA); } catch (e) {}
        applyGlassBlur(true);
        applySidebarWallpaper("default");
        renderCustomWallpaperGallery();

        const toggle = document.getElementById("themeBlurToggle");
        if (toggle) toggle.checked = true;
    }

    function openThemeModal() {
        const modal = document.getElementById("themeCustomizerModal");
        if (!modal) return;

        renderCustomWallpaperGallery();

        const currentWallpaper = localStorage.getItem(STORAGE_KEY_WALLPAPER) || "default";
        document.querySelectorAll(".wallpaper-card").forEach(c => {
            c.classList.toggle("active", c.dataset.wallpaper === currentWallpaper);
        });

        const toggle = document.getElementById("themeBlurToggle");
        if (toggle) {
            toggle.checked = isGlassBlurEnabled();
        }

        modal.classList.add("open");
    }

    function closeThemeModal() {
        const modal = document.getElementById("themeCustomizerModal");
        if (modal) modal.classList.remove("open");
    }

    function handleThemeBackdropClick(e) {
        if (e.target.id === "themeCustomizerModal") {
            closeThemeModal();
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Expose methods to global window
    window.setSidebarWallpaper = setSidebarWallpaper;
    window.deleteCustomWallpaper = deleteCustomWallpaper;
    window.handleCustomWallpaperUpload = handleCustomWallpaperUpload;
    window.toggleWallpaperBlur = toggleWallpaperBlur;
    window.resetThemeDefaults = resetThemeDefaults;
    window.openThemeModal = openThemeModal;
    window.closeThemeModal = closeThemeModal;
    window.handleThemeBackdropClick = handleThemeBackdropClick;

    // Run automatically on DOM ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initThemeCustomizer);
    } else {
        initThemeCustomizer();
    }
})();
