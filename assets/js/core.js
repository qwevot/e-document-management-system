// ============================================================
// EDMS CORE SYSTEM: Sidebar & UI Controls
// ============================================================

(function () {
    "use strict";

    // Global Floating Toast Notification at bottom of screen
    window.showBottomToast = function (message, type = "error") {
        let container = document.getElementById("edmsBottomToastContainer");
        if (!container) {
            container = document.createElement("div");
            container.id = "edmsBottomToastContainer";
            container.style.cssText = "position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;align-items:center;max-width:90vw;";
            document.body.appendChild(container);
        }

        const toast = document.createElement("div");
        toast.className = "edms-bottom-toast";

        let bg = "var(--ink)";
        let border = "var(--border)";
        let text = "var(--surface-light)";
        let iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" width="18" height="18" style="flex-shrink:0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

        if (type === "success") {
            iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2" width="18" height="18" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        } else if (type === "warning") {
            iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2" width="18" height="18" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        }

        toast.style.cssText = `background:${bg};border:1px solid ${border};color:${text};padding:11px 18px;border-radius:8px;font-size:0.875rem;font-weight:500;box-shadow:0 12px 30px rgba(0,0,0,0.35);display:flex;align-items:center;gap:10px;pointer-events:auto;cursor:pointer;opacity:0;transform:translateY(16px);transition:all 0.25s cubic-bezier(0.16, 1, 0.3, 1);user-select:none;line-height:1.4;`;
        toast.innerHTML = `${iconSvg}<span>${message}</span>`;
        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = "1";
            toast.style.transform = "translateY(0)";
        });

        const removeTimer = setTimeout(() => {
            dismissToast();
        }, 4500);

        function dismissToast() {
            clearTimeout(removeTimer);
            toast.style.opacity = "0";
            toast.style.transform = "translateY(16px)";
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }

        toast.addEventListener("click", dismissToast);
    };

    // Global Clipboard Copy with Toast Feedback
    window.copyTextToClipboard = function (text, successMessage = "Link copied to clipboard!") {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast(successMessage, "success");
                }
            }).catch(() => {
                fallbackCopy();
            });
        } else {
            fallbackCopy();
        }

        function fallbackCopy() {
            try {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-9999px";
                textArea.style.top = "0";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                const successful = document.execCommand("copy");
                document.body.removeChild(textArea);
                if (successful && typeof window.showBottomToast === "function") {
                    window.showBottomToast(successMessage, "success");
                } else if (!successful && typeof window.showBottomToast === "function") {
                    window.showBottomToast("Unable to copy link to clipboard.", "error");
                }
            } catch (err) {
                if (typeof window.showBottomToast === "function") {
                    window.showBottomToast("Unable to copy link to clipboard.", "error");
                }
            }
        }
    };

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

    // Toggle sidebar collapse (Desktop) or off-canvas drawer (Mobile)
    window.toggleSidebar = function () {
        if (window.innerWidth <= 768) {
            document.body.classList.toggle("mobile-nav-open");
        } else {
            document.body.classList.toggle("sidebar-collapsed");
            try {
                localStorage.setItem("edms-sidebar-collapsed", document.body.classList.contains("sidebar-collapsed") ? "1" : "0");
            } catch (_) {}
        }
    };

    window.closeMobileNav = function () {
        document.body.classList.remove("mobile-nav-open");
    };

    // Mobile Navigation Drawer Lifecycle Handlers
    document.addEventListener("DOMContentLoaded", function () {
        const backdrop = document.getElementById("mobileNavBackdrop");
        if (backdrop) {
            backdrop.addEventListener("click", window.closeMobileNav);
        }

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && document.body.classList.contains("mobile-nav-open")) {
                window.closeMobileNav();
            }
        });

        // Close mobile drawer when clicking any sidebar navigation link
        document.querySelectorAll(".sidebar a.nav-item").forEach((link) => {
            link.addEventListener("click", function () {
                if (window.innerWidth <= 768) {
                    window.closeMobileNav();
                }
            });
        });

        // Auto-close mobile drawer when resizing back to desktop width
        window.addEventListener("resize", function () {
            if (window.innerWidth > 768 && document.body.classList.contains("mobile-nav-open")) {
                window.closeMobileNav();
            }
        });
    });

    // Restore sidebar state from localStorage
    (function () {
        let savedCollapsed = null;
        let savedGroups = null;
        try {
            savedCollapsed = localStorage.getItem("edms-sidebar-collapsed");
            savedGroups = JSON.parse(localStorage.getItem("edms-sidebar-open-groups"));
        } catch (_) {}

        if (savedCollapsed === "1" && window.innerWidth > 768) {
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

})();

