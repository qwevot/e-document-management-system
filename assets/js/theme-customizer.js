/**
 * EDMS Wallpaper & Canvas Customizer Engine
 * Self-contained module: manages continuous wallpapers across sidebar and topbar.
 */
(function() {
    "use strict";

    const STORAGE_KEY_WALLPAPER = "edms_sidebar_wallpaper";
    const STORAGE_KEY_CUSTOM_DATA = "edms_custom_wallpaper_data";

    const WALLPAPER_PRESETS = {
        "default": null,
        "preset-forest": "https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=800&q=75",
        "preset-tech": "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=75",
        "preset-aurora": "https://images.unsplash.com/photo-1531366936337-7c912a4589a7?auto=format&fit=crop&w=800&q=75",
        "preset-peaks": "https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=75",
        "preset-carbon": "https://images.unsplash.com/photo-1550684848-fac1c5b4e853?auto=format&fit=crop&w=800&q=75"
    };

    function initThemeCustomizer() {
        // Enforce light mode at all times
        document.documentElement.removeAttribute("data-theme");
        try { localStorage.removeItem("edms_theme_mode"); } catch (e) {}

        const savedWallpaper = localStorage.getItem(STORAGE_KEY_WALLPAPER) || "default";
        const customData = localStorage.getItem(STORAGE_KEY_CUSTOM_DATA);
        applySidebarWallpaper(savedWallpaper, customData);
    }

    function applySidebarWallpaper(key, customUrl) {
        const sidebar = document.querySelector(".sidebar");
        const topbar = document.querySelector(".topbar");

        let bgUrl = null;
        if (key === "custom") {
            bgUrl = customUrl || localStorage.getItem(STORAGE_KEY_CUSTOM_DATA);
        } else if (WALLPAPER_PRESETS[key]) {
            bgUrl = WALLPAPER_PRESETS[key];
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

        // Update active card in modal
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
        applySidebarWallpaper(key, null);
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

        const reader = new FileReader();
        reader.onload = function(evt) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement("canvas");
                let width = img.width;
                let height = img.height;
                const maxDim = 1600;

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

                const optimizedDataUrl = canvas.toDataURL("image/jpeg", 0.82);
                try {
                    localStorage.setItem(STORAGE_KEY_WALLPAPER, "custom");
                    localStorage.setItem(STORAGE_KEY_CUSTOM_DATA, optimizedDataUrl);
                    applySidebarWallpaper("custom", optimizedDataUrl);
                } catch (storageErr) {
                    console.warn("[Theme] LocalStorage quota limit:", storageErr);
                    applySidebarWallpaper("custom", optimizedDataUrl);
                }
            };
            img.src = evt.target.result;
        };
        reader.readAsDataURL(file);
    }

    function resetThemeDefaults() {
        localStorage.removeItem(STORAGE_KEY_WALLPAPER);
        localStorage.removeItem(STORAGE_KEY_CUSTOM_DATA);
        applySidebarWallpaper("default", null);
    }

    function openThemeModal() {
        const modal = document.getElementById("themeCustomizerModal");
        if (!modal) return;

        const currentWallpaper = localStorage.getItem(STORAGE_KEY_WALLPAPER) || "default";

        document.querySelectorAll(".wallpaper-card").forEach(c => {
            c.classList.toggle("active", c.dataset.wallpaper === currentWallpaper);
        });

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

    // Expose methods to global window
    window.setSidebarWallpaper = setSidebarWallpaper;
    window.handleCustomWallpaperUpload = handleCustomWallpaperUpload;
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
