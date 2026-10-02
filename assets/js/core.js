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
})();
