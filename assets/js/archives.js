/**
 * EDMS Archives Interactive Controller
 * Decoupled from pages/archives.php for clean separation and browser caching.
 */
let archivesSearchTimer = null;
let currentArchiveActiveItem = null;

const archivePopover = document.getElementById('archiveActionPopover');
const archivePopoverHeaderLabel = document.getElementById('archivePopoverHeaderLabel');
const popoverArchiveRestore = document.getElementById('popoverArchiveRestore');
const popoverArchiveRestoreLabel = document.getElementById('popoverArchiveRestoreLabel');
const popoverArchivePurge = document.getElementById('popoverArchivePurge');
const popoverArchivePurgeLabel = document.getElementById('popoverArchivePurgeLabel');
const archivesCardList = document.getElementById('archivesCardList');

// Selection State Map
const selectedArchiveMap = new Map();
let isArchiveMarqueeDragging = false;
let archiveMarqueeStartX = 0;
let archiveMarqueeStartY = 0;
let archiveMarqueeEl = null;
let isArchiveContextMenuActive = false;

function hideArchivePopover() {
    if (archivePopover) archivePopover.style.display = 'none';
    isArchiveContextMenuActive = false;
}

function updateArchiveSelectionUI() {
    if (!archivesCardList) return;
    archivesCardList.querySelectorAll('.explorer-item-card').forEach(card => {
        const id = card.dataset.id;
        const isSel = selectedArchiveMap.has(id);
        card.classList.toggle('is-selected', isSel);
        const cb = card.querySelector('.item-checkbox');
        if (cb) cb.checked = isSel;
    });
}

function clearArchiveSelection() {
    selectedArchiveMap.clear();
    updateArchiveSelectionUI();
}

function getArchiveItemData(card) {
    if (!card) return null;
    return {
        id: card.dataset.id,
        name: card.dataset.name,
        type: card.dataset.type || 'document',
        location: card.dataset.location || 'Projects'
    };
}

if (archivesCardList) {
    // Delegated Click for 3-Dots Action Popover (Always Single Selection)
    archivesCardList.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-archive-actions');
        if (!btn || !archivePopover) return;
        e.preventDefault();
        e.stopPropagation();

        isArchiveContextMenuActive = false;

        currentArchiveActiveItem = {
            id: btn.dataset.id,
            name: btn.dataset.name,
            type: btn.dataset.type || 'document'
        };

        if (archivePopoverHeaderLabel) archivePopoverHeaderLabel.style.display = 'none';
        if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = 'Restore to Workspace';
        if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = 'Permanently Delete';

        const rect = btn.getBoundingClientRect();
        archivePopover.style.display = 'flex';
        const popoverWidth = 175;
        let left = rect.right - popoverWidth;
        if (left < 10) left = 10;
        let top = rect.bottom + 4;
        if (top + 120 > window.innerHeight) {
            top = rect.top - 120;
        }

        archivePopover.style.left = left + 'px';
        archivePopover.style.top = top + 'px';
    });

    // Checkbox Direct Toggle
    archivesCardList.addEventListener('change', function(e) {
        const cb = e.target.closest('.item-checkbox');
        if (!cb) return;
        const card = cb.closest('.explorer-item-card');
        if (!card) return;
        const itemData = getArchiveItemData(card);
        if (!itemData) return;

        if (cb.checked) {
            selectedArchiveMap.set(itemData.id, itemData);
        } else {
            selectedArchiveMap.delete(itemData.id);
        }
        updateArchiveSelectionUI();
    });

    // Double-click row card to open Workspace (Documents) or navigate inside (Folders)
    archivesCardList.addEventListener('dblclick', function(e) {
        if (e.target.closest('input, button, a, .item-checkbox, .btn-action, .btn-archive-actions, .edms-action-popover, .edms-modal-backdrop')) {
            return;
        }
        const card = e.target.closest('.explorer-item-card');
        if (!card) return;

        const id = card.dataset.id;
        const type = card.dataset.type || 'document';

        if (type === 'folder') {
            const folderHidden = document.getElementById('archivesCurrentFolderId') || document.getElementById('archivesFilterFolder');
            if (folderHidden) folderHidden.value = id;
            submitArchivesAjax(1, null, 'project', id);
        } else {
            window.location.href = 'view.php?id=' + encodeURIComponent(id);
        }
    });

    // Marquee Drag Selection Engine
    archivesCardList.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        if (e.target.closest('button, a, input, select, textarea, .btn-action, .edms-action-popover, .edms-modal-backdrop')) {
            return;
        }

        const clickedCard = e.target.closest('.explorer-item-card');
        const isModifier = e.ctrlKey || e.metaKey;

        if (clickedCard) {
            const itemData = getArchiveItemData(clickedCard);
            if (isModifier) {
                if (selectedArchiveMap.has(itemData.id)) {
                    selectedArchiveMap.delete(itemData.id);
                } else {
                    selectedArchiveMap.set(itemData.id, itemData);
                }
                updateArchiveSelectionUI();
                return;
            } else if (!selectedArchiveMap.has(itemData.id)) {
                selectedArchiveMap.clear();
                selectedArchiveMap.set(itemData.id, itemData);
                updateArchiveSelectionUI();
            }
        } else if (!isModifier) {
            clearArchiveSelection();
        }

        isArchiveMarqueeDragging = true;
        archiveMarqueeStartX = e.clientX;
        archiveMarqueeStartY = e.clientY;

        if (!archiveMarqueeEl) {
            archiveMarqueeEl = document.createElement('div');
            archiveMarqueeEl.className = 'selection-marquee';
            archiveMarqueeEl.style.display = 'none';
            document.body.appendChild(archiveMarqueeEl);
        }
    });

    // Right-Click Context Menu (Single & Bulk)
    archivesCardList.addEventListener('contextmenu', function(e) {
        if (e.target.closest('.edms-modal-dialog')) return;

        e.preventDefault();
        isArchiveContextMenuActive = true;
        const clickedCard = e.target.closest('.explorer-item-card');

        if (clickedCard) {
            const itemData = getArchiveItemData(clickedCard);
            if (!selectedArchiveMap.has(itemData.id)) {
                selectedArchiveMap.clear();
                selectedArchiveMap.set(itemData.id, itemData);
                updateArchiveSelectionUI();
            }
        }

        const count = selectedArchiveMap.size;
        if (count === 0) {
            hideArchivePopover();
            return;
        }

        if (count > 1) {
            if (archivePopoverHeaderLabel) {
                archivePopoverHeaderLabel.style.display = 'block';
                archivePopoverHeaderLabel.textContent = `${count} items selected`;
            }
            if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = `Restore ${count} items`;
            if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = `Permanently Delete (${count})`;
        } else {
            const singleItem = Array.from(selectedArchiveMap.values())[0];
            currentArchiveActiveItem = singleItem;

            if (archivePopoverHeaderLabel) archivePopoverHeaderLabel.style.display = 'none';
            if (popoverArchiveRestoreLabel) popoverArchiveRestoreLabel.textContent = 'Restore to Workspace';
            if (popoverArchivePurgeLabel) popoverArchivePurgeLabel.textContent = 'Permanently Delete';
        }

        archivePopover.style.display = 'flex';
        const popoverWidth = 185;
        let left = e.clientX;
        let top = e.clientY;

        if (left + popoverWidth > window.innerWidth) {
            left = window.innerWidth - popoverWidth - 10;
        }
        if (top + 140 > window.innerHeight) {
            top = window.innerHeight - 140;
        }

        archivePopover.style.left = Math.max(10, left) + 'px';
        archivePopover.style.top = Math.max(10, top) + 'px';
    });
}

window.addEventListener('mousemove', function(e) {
    if (!isArchiveMarqueeDragging || !archiveMarqueeEl) return;

    const currentX = e.clientX;
    const currentY = e.clientY;
    const x = Math.min(archiveMarqueeStartX, currentX);
    const y = Math.min(archiveMarqueeStartY, currentY);
    const w = Math.abs(currentX - archiveMarqueeStartX);
    const h = Math.abs(currentY - archiveMarqueeStartY);

    if (w > 4 || h > 4) {
        archiveMarqueeEl.style.display = 'block';
        archiveMarqueeEl.style.left = x + 'px';
        archiveMarqueeEl.style.top = y + 'px';
        archiveMarqueeEl.style.width = w + 'px';
        archiveMarqueeEl.style.height = h + 'px';

        const marqueeRect = archiveMarqueeEl.getBoundingClientRect();
        const cards = archivesCardList.querySelectorAll('.explorer-item-card');

        cards.forEach(card => {
            const cardRect = card.getBoundingClientRect();
            const intersects = !(
                marqueeRect.right < cardRect.left ||
                marqueeRect.left > cardRect.right ||
                marqueeRect.bottom < cardRect.top ||
                marqueeRect.top > cardRect.bottom
            );

            const itemData = getArchiveItemData(card);
            if (intersects) {
                selectedArchiveMap.set(itemData.id, itemData);
            } else if (!e.ctrlKey && !e.metaKey) {
                selectedArchiveMap.delete(itemData.id);
            }
        });

        updateArchiveSelectionUI();
    }
});

window.addEventListener('mouseup', function() {
    if (isArchiveMarqueeDragging) {
        isArchiveMarqueeDragging = false;
        if (archiveMarqueeEl) {
            archiveMarqueeEl.style.display = 'none';
        }
    }
});

// Popover item clicks
if (popoverArchiveRestore) {
    popoverArchiveRestore.addEventListener('click', function() {
        const isBulk = isArchiveContextMenuActive && selectedArchiveMap.size > 1;
        const itemsToRestore = isBulk ? Array.from(selectedArchiveMap.values()) : (currentArchiveActiveItem ? [currentArchiveActiveItem] : []);
        hideArchivePopover();
        if (itemsToRestore.length > 0) {
            openRestoreModalForItems(itemsToRestore);
        }
    });
}

if (popoverArchivePurge) {
    popoverArchivePurge.addEventListener('click', function() {
        const isBulk = isArchiveContextMenuActive && selectedArchiveMap.size > 1;
        const itemsToPurge = isBulk ? Array.from(selectedArchiveMap.values()) : (currentArchiveActiveItem ? [currentArchiveActiveItem] : []);
        hideArchivePopover();
        if (itemsToPurge.length > 0) {
            openPurgeModalForItems(itemsToPurge);
        }
    });
}

document.addEventListener('click', function(e) {
    if (archivePopover && !archivePopover.contains(e.target)) {
        hideArchivePopover();
    }
});

// Modal Openers for Single / Bulk Items
function openRestoreModalForItems(items) {
    if (!items || items.length === 0) return;
    const isBatch = items.length > 1;
    document.getElementById('restoreDocAction').value = isBatch ? 'batch_restore' : 'restore_node';
    document.getElementById('restoreDocId').value = isBatch ? '' : items[0].id;
    document.getElementById('restoreDocIds').value = isBatch ? items.map(i => i.id).join(',') : '';
    document.getElementById('restoreDocNameDisplay').textContent = isBatch ? `${items.length} selected items` : items[0].name;
    document.getElementById('btnSubmitRestoreText').textContent = isBatch ? `Restore ${items.length} Items` : 'Confirm Restore';
    document.getElementById('modalRestoreDoc').classList.add('is-open');
}

function closeRestoreModal() {
    document.getElementById('modalRestoreDoc').classList.remove('is-open');
}

function openPurgeModalForItems(items) {
    if (!items || items.length === 0) return;
    const isBatch = items.length > 1;
    document.getElementById('purgeDocAction').value = isBatch ? 'batch_purge' : 'purge_node';
    document.getElementById('purgeDocId').value = isBatch ? '' : items[0].id;
    document.getElementById('purgeDocIds').value = isBatch ? items.map(i => i.id).join(',') : '';
    document.getElementById('purgeDocNameDisplay').textContent = isBatch ? `${items.length} selected items` : items[0].name;
    document.getElementById('btnSubmitPurgeText').textContent = isBatch ? `Delete ${items.length} Items Permanently` : 'Delete Permanently';
    document.getElementById('modalPurgeDoc').classList.add('is-open');
}

function closePurgeModal() {
    document.getElementById('modalPurgeDoc').classList.remove('is-open');
}

// Form Submissions with AJAX
const formRestore = document.getElementById('formRestoreDoc');
if (formRestore) {
    formRestore.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(formRestore);
        fetch('archives.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeRestoreModal();
                clearArchiveSelection();
                submitArchivesAjax();
            } else if (typeof window.showBottomToast === 'function') {
                window.showBottomToast(data.message || 'Failed to restore document(s).', 'error');
            } else {
                alert(data.message || 'Failed to restore document(s).');
            }
        })
        .catch(err => {
            console.error('Restore error:', err);
            if (typeof window.showBottomToast === 'function') {
                window.showBottomToast('An error occurred while restoring.', 'error');
            } else {
                alert('An error occurred while restoring.');
            }
        });
    });
}

const formPurge = document.getElementById('formPurgeDoc');
if (formPurge) {
    formPurge.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(formPurge);
        fetch('archives.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closePurgeModal();
                clearArchiveSelection();
                submitArchivesAjax();
            } else if (typeof window.showBottomToast === 'function') {
                window.showBottomToast(data.message || 'Failed to delete document(s).', 'error');
            } else {
                alert(data.message || 'Failed to delete document(s).');
            }
        })
        .catch(err => {
            console.error('Purge error:', err);
            if (typeof window.showBottomToast === 'function') {
                window.showBottomToast('An error occurred during deletion.', 'error');
            } else {
                alert('An error occurred during deletion.');
            }
        });
    });
}

function debounceArchivesSearch() {
    clearTimeout(archivesSearchTimer);
    archivesSearchTimer = setTimeout(() => {
        submitArchivesAjax();
    }, 280);
    const clearBtn = document.getElementById('archivesSearchClear');
    const inputVal = document.getElementById('archivesSearchInput').value;
    if (clearBtn) clearBtn.style.display = inputVal ? 'block' : 'none';
}

function clearArchivesSearch(e) {
    if (e) e.preventDefault();
    const input = document.getElementById('archivesSearchInput');
    if (input) input.value = '';
    const clearBtn = document.getElementById('archivesSearchClear');
    if (clearBtn) clearBtn.style.display = 'none';
    submitArchivesAjax();
}

function handleArchivesModeClick(e, mode) {
    if (e) e.preventDefault();
    const modeHidden1 = document.getElementById('archivesSearchMode');
    const modeHidden2 = document.getElementById('archivesFilterMode');
    if (modeHidden1) modeHidden1.value = mode;
    if (modeHidden2) modeHidden2.value = mode;

    document.querySelectorAll('#archivesPillTabs .pill-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.mode === mode);
    });

    submitArchivesAjax(1, null, mode);
}

function handleArchivesTypeFilter() {
    submitArchivesAjax(1);
}

function handleArchivesPerPage(val) {
    submitArchivesAjax(1, val);
}

function navigateArchivesPage(page) {
    submitArchivesAjax(page);
}

function submitArchivesAjax(page = 1, perPage = null, overrideMode = null, targetFolder = null) {
    const q = document.getElementById('archivesSearchInput')?.value || '';
    const type = document.getElementById('archivesFilterType')?.value || '';
    const mode = overrideMode || document.getElementById('archivesFilterMode')?.value || document.getElementById('archivesSearchMode')?.value || 'project';
    const folder = (mode === 'project') ? (targetFolder !== null ? targetFolder : (document.getElementById('archivesCurrentFolderId')?.value || '')) : '';
    const selectedPerPage = perPage || document.getElementById('archivesPerPageSelect')?.value || 25;

    const params = new URLSearchParams();
    params.set('ajax', '1');
    params.set('mode', mode);
    if (folder && mode === 'project') params.set('folder', folder);
    if (q) params.set('q', q);
    if (type) params.set('type', type);
    params.set('page', page);
    params.set('per_page', selectedPerPage);

    fetch(`archives.php?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.text())
    .then(html => {
        const list = document.getElementById('archivesCardList');
        if (list) list.innerHTML = html;
        params.delete('ajax');
        window.history.replaceState({}, '', `archives.php?${params.toString()}`);
        clearArchiveSelection();
    })
    .catch(err => console.error('Archives filter error:', err));
}

// Modal closing helpers
document.querySelectorAll('.edms-modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.classList.remove('is-open');
    });
});
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideArchivePopover();
        clearArchiveSelection();
        document.querySelectorAll('.edms-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
});
