<?php
// Isolated Markdown-Driven User Guide Modal Component (Auto-splits ## headings into tabs)
$guidePath = __DIR__ . '/../USER_GUIDE.md';
$guideMarkdown = is_file($guidePath) ? (string)file_get_contents($guidePath) : "## Guide Unavailable\nCould not load `USER_GUIDE.md`.";
?>
<style>
.help-modal-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: var(--surface-light);
    border-bottom: 1px solid var(--border);
    overflow-x: auto;
}
.help-tab-btn {
    border: 1px solid transparent;
    background: transparent;
    color: var(--muted);
    font-size: 12.5px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
    font-family: inherit;
}
.help-tab-btn:hover {
    color: var(--ink);
    background: var(--surface);
}
.help-tab-btn.active {
    color: var(--green-ink);
    background: var(--green-soft);
    border-color: var(--green-line);
}
.help-md-body {
    font-size: 13px;
    line-height: 1.6;
    color: var(--ink);
}
.help-md-body h3 {
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
    margin: 16px 0 6px;
}
.help-md-body h3:first-child {
    margin-top: 0;
}
.help-md-body p {
    margin: 0 0 10px;
}
.help-md-body ul, .help-md-body ol {
    margin: 6px 0 12px 20px;
    padding: 0;
}
.help-md-body li {
    margin-bottom: 5px;
}
.help-md-body table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0 14px;
    font-size: 12.5px;
}
.help-md-body th, .help-md-body td {
    border: 1px solid var(--border);
    padding: 8px 10px;
    text-align: left;
    vertical-align: middle;
}
.help-md-body th {
    background: var(--surface-light);
    font-weight: 700;
    color: var(--ink);
}
.help-md-body tr:nth-child(even) td {
    background: #f8fafc;
}
.help-md-body blockquote {
    border-left: 3px solid var(--green);
    background: var(--green-soft);
    color: var(--green-ink);
    margin: 10px 0;
    padding: 8px 12px;
    border-radius: 0 6px 6px 0;
    font-size: 12.5px;
}
.help-md-body blockquote p {
    margin: 0;
}
.help-md-body code {
    background: var(--surface-light);
    border: 1px solid var(--border);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 11.5px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.help-md-body hr {
    border: none;
    border-top: 1px solid var(--border);
    margin: 14px 0;
}
</style>

<div id="helpGuideModal" class="theme-modal-backdrop" onclick="if(event.target===this)closeHelpModal()">
    <div class="theme-modal-box" style="max-width: 700px;" role="dialog" aria-modal="true" aria-labelledby="helpModalTitle">
        <div class="theme-modal-header">
            <h3 id="helpModalTitle" class="theme-modal-title">
                <?php echo render_icon('help'); ?>
                EDMS User Guide
            </h3>
            <button type="button" class="theme-modal-close" onclick="closeHelpModal()" aria-label="Close guide">&times;</button>
        </div>

        <div id="helpModalTabs" class="help-modal-tabs" role="tablist"></div>

        <div class="theme-modal-body" style="max-height: 62vh; overflow-y: auto; padding: 18px 22px;">
            <div id="helpModalContent" class="help-md-body"></div>
        </div>

        <div class="theme-modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeHelpModal()" style="padding: 6px 16px; font-size: 12px;">
                Got it
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const rawMd = <?php echo json_encode($guideMarkdown, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    let sections = [];

    function parseSections() {
        if (sections.length) return;
        const parts = rawMd.split(/^##\s+(.+)$/m);
        for (let i = 1; i < parts.length; i += 2) {
            sections.push({
                title: parts[i].trim(),
                body: (parts[i + 1] || '').trim().replace(/\n---\s*$/, '')
            });
        }
        if (!sections.length) {
            sections.push({ title: 'Guide', body: rawMd });
        }
    }

    window.switchHelpTab = function(idx) {
        parseSections();
        const tabsEl = document.getElementById('helpModalTabs');
        const bodyEl = document.getElementById('helpModalContent');
        if (!tabsEl || !bodyEl || !sections[idx]) return;

        tabsEl.innerHTML = sections.map((sec, i) =>
            `<button type="button" role="tab" class="help-tab-btn ${i === idx ? 'active' : ''}" onclick="switchHelpTab(${i})">${sec.title}</button>`
        ).join('');

        const mdText = sections[idx].body;
        bodyEl.innerHTML = (window.marked && typeof window.marked.parse === 'function')
            ? window.marked.parse(mdText)
            : mdText.replace(/\n/g, '<br>');
        bodyEl.parentElement.scrollTop = 0;
    };

    window.openHelpModal = function() {
        const modal = document.getElementById('helpGuideModal');
        if (!modal) return;
        if (!sections.length) switchHelpTab(0);
        modal.classList.add('open');
    };

    window.closeHelpModal = function() {
        const modal = document.getElementById('helpGuideModal');
        if (modal) modal.classList.remove('open');
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeHelpModal();
    });
})();
</script>
