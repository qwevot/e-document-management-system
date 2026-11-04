<?php
// Isolated Wallpaper Customizer Modal Component
?>
<div id="themeCustomizerModal" class="theme-modal-backdrop" onclick="handleThemeBackdropClick(event)">
    <div class="theme-modal-box" role="dialog" aria-modal="true" aria-labelledby="themeModalTitle">
        <!-- Header -->
        <div class="theme-modal-header">
            <h3 id="themeModalTitle" class="theme-modal-title">
                <?php echo render_icon('settings'); ?>
                Customize Wallpaper
            </h3>
            <button type="button" class="theme-modal-close" onclick="closeThemeModal()" aria-label="Close dialog">&times;</button>
        </div>

        <!-- Body -->
        <div class="theme-modal-body">
            <!-- Dynamic Client-Side Uploaded Wallpapers Gallery -->
            <div id="myUploadsSection" style="display: none;">
                <div class="theme-section-title">My Uploads</div>
                <div class="wallpaper-grid" id="myUploadsGrid"></div>
            </div>

            <div>
                <div class="theme-section-title">Preset Wallpapers</div>
                <div class="wallpaper-grid">
                    <!-- Default Classic Emerald -->
                    <div class="wallpaper-card active" data-wallpaper="default" onclick="setSidebarWallpaper('default')" style="background: linear-gradient(200deg, #16a34a 0%, #064e3b 150%);">
                        <span class="wallpaper-card-label">Default Emerald</span>
                    </div>

                    <!-- Preset 1: Deep Pine Forest -->
                    <div class="wallpaper-card" data-wallpaper="preset-forest" onclick="setSidebarWallpaper('preset-forest')" style="background-image: url('https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=500&q=70');">
                        <span class="wallpaper-card-label">Deep Pine</span>
                    </div>

                    <!-- Preset 2: Tech Grid -->
                    <div class="wallpaper-card" data-wallpaper="preset-tech" onclick="setSidebarWallpaper('preset-tech')" style="background-image: url('https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=500&q=70');">
                        <span class="wallpaper-card-label">Dark Mesh</span>
                    </div>

                    <!-- Preset 3: Aurora Borealis -->
                    <div class="wallpaper-card" data-wallpaper="preset-aurora" onclick="setSidebarWallpaper('preset-aurora')" style="background-image: url('https://images.unsplash.com/photo-1531366936337-7c912a4589a7?auto=format&fit=crop&w=500&q=70');">
                        <span class="wallpaper-card-label">Aurora Glow</span>
                    </div>

                    <!-- Preset 4: Moody Peaks -->
                    <div class="wallpaper-card" data-wallpaper="preset-peaks" onclick="setSidebarWallpaper('preset-peaks')" style="background-image: url('https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=500&q=70');">
                        <span class="wallpaper-card-label">Misty Peak</span>
                    </div>

                    <!-- Preset 5: Carbon Minimal -->
                    <div class="wallpaper-card" data-wallpaper="preset-carbon" onclick="setSidebarWallpaper('preset-carbon')" style="background-image: url('https://images.unsplash.com/photo-1550684848-fac1c5b4e853?auto=format&fit=crop&w=500&q=70');">
                        <span class="wallpaper-card-label">Carbon Fiber</span>
                    </div>
                </div>

                <!-- Custom Image Upload (Gmail style) -->
                <div style="margin-top: 12px;">
                    <input type="file" id="themeWallpaperFileInput" accept="image/png, image/jpeg, image/webp" style="display: none;" onchange="handleCustomWallpaperUpload(event)">
                    <div class="theme-upload-zone" onclick="document.getElementById('themeWallpaperFileInput').click()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-bottom: 4px; color: var(--green);">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        <div style="font-size: 12px; font-weight: 600; color: var(--ink);">Upload your own wallpaper image</div>
                        <div style="font-size: 11px; color: var(--muted); margin-top: 2px;">JPG, PNG, or WEBP (automatically optimized)</div>
                    </div>
                </div>

                <!-- Glass Blur Toggle -->
                <div class="theme-setting-card">
                    <div>
                        <div style="font-size: 12.5px; font-weight: 600; color: var(--ink);">Blur</div>
                    </div>
                    <label class="theme-switch" title="Toggle glass blur">
                        <input type="checkbox" id="themeBlurToggle" onchange="toggleWallpaperBlur(this.checked)">
                        <span class="theme-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="theme-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="resetThemeDefaults()" style="padding: 6px 12px; font-size: 11.5px;">
                Reset to Default
            </button>
            <button type="button" class="btn btn-primary" onclick="closeThemeModal()" style="padding: 6px 16px; font-size: 12px;">
                Done
            </button>
        </div>
    </div>
</div>
