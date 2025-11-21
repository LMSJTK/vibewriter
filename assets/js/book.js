/**
 * VibeWriter Book View JavaScript
 * Handles editor interactions, auto-save, AI chat, and tree management
 */

// Current book and item IDs
const urlParams = new URLSearchParams(window.location.search);
const bookId = urlParams.get('id');
const itemId = urlParams.get('item');
const numericItemId = itemId ? Number(itemId) : null;
const initialWorkspaceView = urlParams.get('view') || 'editor';

const PlanningUtils = window.PlanningUtils || {};
const normalizePlanningItems = typeof PlanningUtils.normalizePlanningItems === 'function'
    ? PlanningUtils.normalizePlanningItems
    : () => ({ itemsById: {}, childrenByParent: {} });
const getPlanningCollection = typeof PlanningUtils.getCollection === 'function'
    ? PlanningUtils.getCollection
    : () => [];
const derivePlanningParent = typeof PlanningUtils.derivePlanningParent === 'function'
    ? PlanningUtils.derivePlanningParent
    : () => null;
const planningParentKeyFor = typeof PlanningUtils.parentKeyFor === 'function'
    ? PlanningUtils.parentKeyFor
    : (parentId) => (parentId === null || parentId === undefined ? 'root' : String(parentId));

let activeWorkspaceView = initialWorkspaceView;
let planningStatusTimer = null;

const outlineNotesState = {
    ready: false,
    dirty: false,
    saving: false,
    timer: null,
};

const planningState = {
    ready: false,
    itemsById: {},
    childrenByParent: {},
    selectedItemId: Number.isFinite(numericItemId) ? numericItemId : null,
    activeParentId: null,
    outlinerSort: { column: 'position', direction: 'asc' }
};

const planningDragState = {
    activeId: null
};

// Auto-save timer
let autoSaveTimer;
let hasUnsavedChanges = false;

// Dictation state
let dictationRecognition = null;
let dictationIsListening = false;
let dictationActiveTarget = null;
let dictationActiveButton = null;

const globalAIVoiceConfig = typeof window !== 'undefined' && window.aiVoiceConfig ? window.aiVoiceConfig : {};
const supportsAbortController = typeof AbortController === 'function';
const aiVoiceVoices = Array.isArray(globalAIVoiceConfig.voices) ? globalAIVoiceConfig.voices : [];
const aiVoiceEndpoint = globalAIVoiceConfig.endpoint || 'api/text_to_speech.php';
const aiVoiceDefaultEncoding = (globalAIVoiceConfig.defaultAudioEncoding || 'MP3').toUpperCase();
const networkVoiceModes = ['google', 'elevenlabs'];
const configuredVoiceMode = typeof globalAIVoiceConfig.mode === 'string' ? globalAIVoiceConfig.mode : 'browser';
let aiVoiceMode = networkVoiceModes.includes(configuredVoiceMode) && aiVoiceVoices.length ? configuredVoiceMode : 'browser';
let aiVoiceEnabled = false;
let aiVoiceUtterance = null;
let aiVoiceSelectElement = null;
let aiVoiceAudioElement = null;
let aiVoiceRequestController = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeEditor();
    initializeTreeToggles();
    initializeDictation();
    initializeAIChatVoice();
    initializeWorkspaceTabs();
    initializeBinderSelectionSync();
    initializeOutlinerInteractions();
    initializePlanningViews();
    initializeOutlineNotes();

    if (Number.isFinite(planningState.selectedItemId)) {
        document.dispatchEvent(new CustomEvent('book:itemSelected', {
            detail: {
                itemId: planningState.selectedItemId,
                source: 'bootstrap',
                view: initialWorkspaceView
            }
        }));
    }
});

document.addEventListener('book:itemSelected', (event) => {
    const detail = event?.detail || {};
    const selectedId = Number(detail.itemId);
    if (!Number.isFinite(selectedId)) {
        return;
    }

    planningState.selectedItemId = selectedId;
    updatePlanningSelection(selectedId);
});

// Editor initialization and auto-save
function initializeEditor() {
    const contentEditor = document.getElementById('contentEditor');
    const synopsisInput = document.getElementById('synopsis');

    if (contentEditor) {
        contentEditor.addEventListener('input', function() {
            hasUnsavedChanges = true;
            showSavingIndicator();
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveContent, 2000); // Auto-save after 2 seconds of inactivity
        });
    }

    if (synopsisInput) {
        synopsisInput.addEventListener('input', function() {
            hasUnsavedChanges = true;
            showSavingIndicator();
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveContent, 2000);
        });
    }

    // Warn before leaving if unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

// Save content via AJAX
async function saveContent() {
    if (!itemId) return;

    const content = document.getElementById('contentEditor')?.value || '';
    const synopsis = document.getElementById('synopsis')?.value || '';

    try {
        const response = await fetch('api/save_item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                item_id: itemId,
                book_id: bookId,
                content: content,
                synopsis: synopsis
            })
        });

        const result = await response.json();

        if (result.success) {
            hasUnsavedChanges = false;
            showSavedIndicator();
            // Update word count if provided
            if (result.word_count !== undefined) {
                updateWordCount(result.word_count);
            }
        } else {
            showErrorIndicator();
        }
    } catch (error) {
        console.error('Save failed:', error);
        showErrorIndicator();
    }
}

// Save indicators
function showSavingIndicator() {
    const indicator = document.getElementById('saveIndicator');
    if (indicator) {
        indicator.innerHTML = '<span class="saving">💾 Saving...</span>';
    }
}

function showSavedIndicator() {
    const indicator = document.getElementById('saveIndicator');
    if (indicator) {
        indicator.innerHTML = '<span class="saved">✓ All changes saved</span>';
    }
}

function showErrorIndicator() {
    const indicator = document.getElementById('saveIndicator');
    if (indicator) {
        indicator.innerHTML = '<span class="error">⚠️ Save failed</span>';
    }
}

function updateWordCount(wordCount) {
    const elements = document.querySelectorAll('.word-count');
    elements.forEach(el => {
        if (el.closest('.item-meta')) {
            el.textContent = `${wordCount.toLocaleString()} words`;
        }
    });
}

// Tree management
function initializeTreeToggles() {
    const toggles = document.querySelectorAll('.tree-toggle');
    toggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleTreeItem(this);
        });
    });
}

function toggleTreeItem(toggleElement) {
    toggleElement.classList.toggle('expanded');
    const treeItem = toggleElement.closest('.tree-item');
    const childList = treeItem.querySelector(':scope > .tree-list');
    if (childList) {
        childList.classList.toggle('expanded');
    }
}

function expandAll() {
    document.querySelectorAll('.tree-toggle').forEach(toggle => {
        if (!toggle.classList.contains('expanded')) {
            toggleTreeItem(toggle);
        }
    });
}

function collapseAll() {
    document.querySelectorAll('.tree-toggle.expanded').forEach(toggle => {
        toggleTreeItem(toggle);
    });
}

// Workspace tab management
function initializeWorkspaceTabs() {
    const tabs = document.querySelectorAll('.workspace-tab');
    const hasTabs = tabs.length > 0;
    if (!hasTabs) {
        return;
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const view = tab.getAttribute('data-view');
            activateWorkspaceTab(view);
            tab.focus();
        });

        tab.addEventListener('keydown', handleWorkspaceTabKeydown);
    });

    activateWorkspaceTab(initialWorkspaceView, { updateHistory: false });
}

function activateWorkspaceTab(view, options = {}) {
    const tabs = document.querySelectorAll('.workspace-tab');
    const panels = document.querySelectorAll('.workspace-panel');
    if (!tabs.length || !panels.length) {
        return;
    }

    const validViews = Array.from(panels).map(panel => panel.getAttribute('data-view'));
    const nextView = validViews.includes(view) ? view : 'editor';
    activeWorkspaceView = nextView;

    tabs.forEach(tab => {
        const isActive = tab.getAttribute('data-view') === nextView;
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.classList.toggle('active', isActive);
    });

    panels.forEach(panel => {
        const isActive = panel.getAttribute('data-view') === nextView;
        panel.classList.toggle('active', isActive);
        panel.toggleAttribute('hidden', !isActive);
    });

    if (options.updateHistory !== false) {
        const url = new URL(window.location.href);
        if (nextView === 'editor') {
            url.searchParams.delete('view');
        } else {
            url.searchParams.set('view', nextView);
        }
        window.history.replaceState({}, '', url.toString());
    }
}

function handleWorkspaceTabKeydown(event) {
    const tabs = Array.from(document.querySelectorAll('.workspace-tab'));
    if (!tabs.length) {
        return;
    }

    const currentIndex = tabs.indexOf(event.currentTarget);
    if (currentIndex === -1) {
        return;
    }

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        const nextIndex = (currentIndex + 1) % tabs.length;
        tabs[nextIndex].click();
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        const prevIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        tabs[prevIndex].click();
    } else if (event.key === 'Home') {
        event.preventDefault();
        tabs[0].click();
    } else if (event.key === 'End') {
        event.preventDefault();
        tabs[tabs.length - 1].click();
    }
}

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view') || 'editor';
    activateWorkspaceTab(view, { updateHistory: false });
});

// Traditional outline notes (freeform nested plans)
function initializeOutlineNotes() {
    const editor = document.getElementById('outlineNotesEditor');
    if (!editor || !bookId) {
        return;
    }

    const addBulletBtn = document.getElementById('outlineAddBullet');
    const indentBtn = document.getElementById('outlineIndent');
    const outdentBtn = document.getElementById('outlineOutdent');

    editor.addEventListener('keydown', handleOutlineKeydown);
    editor.addEventListener('input', () => {
        outlineNotesState.dirty = true;
        setOutlineStatus('saving', 'Drafting…');
        scheduleOutlineSave();
    });

    if (addBulletBtn) {
        addBulletBtn.addEventListener('click', insertOutlineBulletAtCursor);
    }

    if (indentBtn) {
        indentBtn.addEventListener('click', () => adjustOutlineIndentation('indent'));
    }

    if (outdentBtn) {
        outdentBtn.addEventListener('click', () => adjustOutlineIndentation('outdent'));
    }

    loadOutlineNotes();
}

function setOutlineStatus(state, message) {
    const status = document.getElementById('outlineNotesStatus');
    if (!status) {
        return;
    }

    status.classList.remove('saving', 'error');
    if (state) {
        status.classList.add(state);
    }
    status.textContent = message;
}

function scheduleOutlineSave() {
    clearTimeout(outlineNotesState.timer);
    outlineNotesState.timer = setTimeout(saveOutlineNotes, 900);
}

async function loadOutlineNotes() {
    outlineNotesState.ready = false;
    setOutlineStatus('saving', 'Loading outline…');

    try {
        const response = await fetch(`api/get_outline_notes.php?book_id=${encodeURIComponent(bookId)}`);
        const payload = await response.json();

        if (!payload.success) {
            throw new Error(payload.message || 'Unable to load outline');
        }

        const editor = document.getElementById('outlineNotesEditor');
        if (editor) {
            editor.value = payload.outline || '';
        }

        outlineNotesState.ready = true;
        outlineNotesState.dirty = false;
        setOutlineStatus(null, 'Outline ready');
    } catch (error) {
        console.error('Failed to load outline notes', error);
        setOutlineStatus('error', error.message || 'Failed to load outline');
    }
}

async function saveOutlineNotes() {
    const editor = document.getElementById('outlineNotesEditor');
    if (!editor || !outlineNotesState.ready || outlineNotesState.saving || !outlineNotesState.dirty) {
        return;
    }

    outlineNotesState.saving = true;
    setOutlineStatus('saving', 'Saving…');

    try {
        const response = await fetch('api/save_outline_notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ book_id: bookId, outline: editor.value })
        });

        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.message || 'Unable to save outline');
        }

        outlineNotesState.dirty = false;
        setOutlineStatus(null, 'Outline saved');
    } catch (error) {
        console.error('Failed to save outline notes', error);
        setOutlineStatus('error', error.message || 'Failed to save outline');
    } finally {
        outlineNotesState.saving = false;
    }
}

function handleOutlineKeydown(event) {
    if (event.key === 'Tab') {
        event.preventDefault();
        const direction = event.shiftKey ? 'outdent' : 'indent';
        adjustOutlineIndentation(direction);
    }
}

function insertOutlineBulletAtCursor() {
    const editor = document.getElementById('outlineNotesEditor');
    if (!editor) {
        return;
    }

    const value = editor.value;
    const start = editor.selectionStart;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const insertion = value.slice(0, lineStart) + '- ' + value.slice(lineStart);

    editor.value = insertion;
    const caretPosition = start + 2;
    editor.setSelectionRange(caretPosition, caretPosition);
    editor.focus();

    outlineNotesState.dirty = true;
    setOutlineStatus('saving', 'Drafting…');
    scheduleOutlineSave();
}

function adjustOutlineIndentation(direction) {
    const editor = document.getElementById('outlineNotesEditor');
    if (!editor) {
        return;
    }

    const value = editor.value;
    const selectionStart = editor.selectionStart;
    const selectionEnd = editor.selectionEnd;

    const lineStart = value.lastIndexOf('\n', selectionStart - 1) + 1;
    const endOfSelectionLineBreak = value.indexOf('\n', selectionEnd);
    const lineEnd = endOfSelectionLineBreak === -1 ? value.length : endOfSelectionLineBreak;

    const segment = value.slice(lineStart, lineEnd);
    const lines = segment.split('\n');

    const updatedLines = lines.map((line) => {
        if (direction === 'indent') {
            return '    ' + line;
        }

        const trimmed = line.replace(/^( {1,4}|\t)/, '');
        return trimmed;
    });

    const updatedSegment = updatedLines.join('\n');
    const newValue = value.slice(0, lineStart) + updatedSegment + value.slice(lineEnd);
    editor.value = newValue;

    const delta = updatedSegment.length - segment.length;
    const newStart = Math.max(0, selectionStart + delta);
    const newEnd = Math.max(newStart, selectionEnd + delta);
    editor.setSelectionRange(newStart, newEnd);
    editor.focus();

    outlineNotesState.dirty = true;
    setOutlineStatus('saving', 'Drafting…');
    scheduleOutlineSave();
}

// Binder <-> planning sync helpers
function initializeBinderSelectionSync() {
    const binderTree = document.getElementById('binderTree');
    if (!binderTree) {
        return;
    }

    binderTree.addEventListener('click', (event) => {
        const link = event.target.closest('a.tree-label');
        if (!link) {
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
            return;
        }

        const targetUrl = new URL(link.href, window.location.origin);
        const nextItemId = Number(targetUrl.searchParams.get('item'));
        if (!Number.isFinite(nextItemId)) {
            return;
        }

        event.preventDefault();
        handleItemSelection(nextItemId, { source: 'binder' });
    });
}

function handleItemSelection(nextItemId, options = {}) {
    if (!Number.isFinite(nextItemId)) {
        return;
    }

    const detail = {
        itemId: nextItemId,
        source: options.source || 'unknown',
        view: options.view || null
    };

    planningState.selectedItemId = nextItemId;
    document.dispatchEvent(new CustomEvent('book:itemSelected', { detail }));

    if (options.navigation === false) {
        return;
    }

    const targetUrl = new URL(window.location.href);
    targetUrl.searchParams.set('item', nextItemId);
    if (options.view) {
        targetUrl.searchParams.set('view', options.view);
    } else {
        targetUrl.searchParams.delete('view');
    }

    window.location.assign(targetUrl.toString());
}

function syncBinderActiveState(selectedId) {
    const nodes = document.querySelectorAll('#binderTree .tree-item');
    nodes.forEach(node => {
        const itemId = Number(node.getAttribute('data-item-id'));
        const isActive = itemId === selectedId;
        node.classList.toggle('active', isActive);
        const link = node.querySelector('.tree-label');
        if (link) {
            link.setAttribute('aria-current', isActive ? 'page' : 'false');
        }
    });
}

function syncBinderOrder(parentId, newOrder) {
    if (!Array.isArray(newOrder) || newOrder.length === 0) {
        return;
    }

    let container;
    if (parentId === null || parentId === undefined) {
        container = document.querySelector('#binderTree > ul.tree-list');
    } else {
        container = document.querySelector(`#binderTree li[data-item-id="${parentId}"] > ul.tree-list`);
    }

    if (!container) {
        return;
    }

    const fragment = document.createDocumentFragment();
    newOrder.forEach(id => {
        const child = container.querySelector(`:scope > li[data-item-id="${id}"]`);
        if (child) {
            fragment.appendChild(child);
        }
    });

    container.appendChild(fragment);
}

function syncBinderLabel(itemId, newTitle) {
    const node = document.querySelector(`#binderTree li[data-item-id="${itemId}"] .tree-label`);
    if (node) {
        node.textContent = newTitle || 'Untitled';
    }
}

// Planning data bootstrap
async function initializePlanningViews() {
    if (!bookId) {
        return;
    }

    const label = document.getElementById('corkboardCollectionLabel');
    if (label) {
        label.textContent = 'Loading corkboard…';
    }

    try {
        const response = await fetch(`api/get_book_items.php?book_id=${encodeURIComponent(bookId)}`);
        const payload = await response.json();

        if (!payload.success) {
            throw new Error(payload.message || 'Unable to load planning data');
        }

        const normalized = normalizePlanningItems(payload.items || []);
        planningState.itemsById = normalized.itemsById;
        planningState.childrenByParent = normalized.childrenByParent;
        planningState.ready = true;
        planningState.activeParentId = Number.isFinite(planningState.selectedItemId)
            ? derivePlanningParent(planningState.itemsById, planningState.selectedItemId)
            : null;

        updateOutlinerSortButtons();
        updatePlanningSelection(planningState.selectedItemId);
        announcePlanningStatus('Planning views ready', 'success');
    } catch (error) {
        console.error('Failed to load planning data', error);
        announcePlanningStatus(error.message || 'Failed to load planning data', 'error');
    }
}

function updatePlanningSelection(itemId) {
    if (!planningState.ready) {
        planningState.selectedItemId = Number.isFinite(itemId) ? itemId : null;
        return;
    }

    const selectedId = Number.isFinite(itemId) ? itemId : null;
    planningState.selectedItemId = selectedId;
    planningState.activeParentId = Number.isFinite(selectedId)
        ? derivePlanningParent(planningState.itemsById, selectedId)
        : null;

    renderCorkboard();
    renderOutliner();
    togglePlanningEmptyStates();
    updateCorkboardCollectionLabel();
    syncBinderActiveState(selectedId);
}

function renderCorkboard() {
    const grid = document.getElementById('corkboardGrid');
    if (!grid) {
        return;
    }

    if (!planningState.ready) {
        grid.setAttribute('aria-busy', 'true');
        grid.innerHTML = '';
        return;
    }

    grid.removeAttribute('aria-busy');
    ensureCorkboardGridListeners(grid);
    const parentId = planningState.activeParentId ?? null;
    grid.dataset.parentId = parentId === null ? '' : parentId;
    const order = getPlanningCollection(planningState.childrenByParent, parentId);
    grid.innerHTML = '';

    if (!order.length) {
        return;
    }

    order.forEach(itemId => {
        const item = planningState.itemsById[itemId];
        if (!item) {
            return;
        }

        const card = document.createElement('article');
        card.className = 'corkboard-card';
        card.dataset.itemId = item.id;
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.draggable = true;

        if (item.id === planningState.selectedItemId) {
            card.classList.add('is-selected');
        }

        card.addEventListener('click', (event) => {
            if (event.target.closest('.card-drag-handle')) {
                return;
            }
            handleItemSelection(item.id, { source: 'corkboard', view: activeWorkspaceView });
        });

        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                handleItemSelection(item.id, { source: 'corkboard', view: activeWorkspaceView });
            }
        });

        card.addEventListener('dragstart', handleCardDragStart);
        card.addEventListener('dragover', handleCardDragOver);
        card.addEventListener('dragleave', handleCardDragLeave);
        card.addEventListener('drop', handleCardDrop);
        card.addEventListener('dragend', handleCardDragEnd);

        const header = document.createElement('div');
        header.className = 'card-header';

        const title = document.createElement('h4');
        title.className = 'card-title';
        title.textContent = item.title || 'Untitled';
        header.appendChild(title);

        const dragHandle = document.createElement('button');
        dragHandle.type = 'button';
        dragHandle.className = 'card-drag-handle';
        dragHandle.setAttribute('aria-label', `Reorder ${item.title || 'card'}`);
        dragHandle.innerHTML = '⋮⋮';
        dragHandle.addEventListener('click', (event) => event.stopPropagation());
        dragHandle.addEventListener('keydown', (event) => {
            handleKeyboardReorder(event, item.id, parentId);
        });
        header.appendChild(dragHandle);

        card.appendChild(header);

        const meta = document.createElement('div');
        meta.className = 'card-meta';

        if (item.status) {
            const statusPill = document.createElement('span');
            statusPill.className = 'card-label';
            statusPill.textContent = formatStatus(item.status);
            meta.appendChild(statusPill);
        }

        if (item.label) {
            const labelPill = document.createElement('span');
            labelPill.className = 'card-label';
            labelPill.textContent = item.label;
            meta.appendChild(labelPill);
        }

        const pov = item.metadata && typeof item.metadata === 'object' ? item.metadata.pov : '';
        if (pov) {
            const povPill = document.createElement('span');
            povPill.className = 'card-label';
            povPill.textContent = `POV: ${pov}`;
            meta.appendChild(povPill);
        }

        if (meta.childNodes.length) {
            card.appendChild(meta);
        }

        const body = document.createElement('div');
        body.className = 'card-body';
        const synopsis = (item.synopsis || '').trim();
        if (synopsis) {
            body.textContent = synopsis;
        } else {
            const placeholder = document.createElement('span');
            placeholder.className = 'empty';
            placeholder.textContent = 'No synopsis yet';
            body.appendChild(placeholder);
        }
        card.appendChild(body);

        const footer = document.createElement('div');
        footer.className = 'card-footer';
        footer.innerHTML = `<span>${formatItemType(item.item_type)}</span><span>${formatWordCount(item.word_count)}</span>`;
        card.appendChild(footer);

        grid.appendChild(card);
    });
}

function ensureCorkboardGridListeners(grid) {
    if (!grid || grid.dataset.listenersBound === 'true') {
        return;
    }

    grid.addEventListener('dragover', handleCorkboardGridDragOver);
    grid.addEventListener('drop', handleCorkboardGridDrop);
    grid.dataset.listenersBound = 'true';
}

function initializeOutlinerInteractions() {
    const sortButtons = document.querySelectorAll('.outliner-sort');
    if (!sortButtons.length) {
        return;
    }

    sortButtons.forEach(button => {
        button.addEventListener('click', () => {
            const column = button.getAttribute('data-sort');
            if (!column) {
                return;
            }

            if (planningState.outlinerSort.column === column) {
                planningState.outlinerSort.direction = planningState.outlinerSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                planningState.outlinerSort.column = column;
                planningState.outlinerSort.direction = column === 'word_count' ? 'desc' : 'asc';
            }

            updateOutlinerSortButtons();
            renderOutliner();
        });
    });

    updateOutlinerSortButtons();
}

function updateOutlinerSortButtons() {
    const sortButtons = document.querySelectorAll('.outliner-sort');
    sortButtons.forEach(button => {
        const column = button.getAttribute('data-sort');
        if (column === planningState.outlinerSort.column) {
            button.dataset.direction = planningState.outlinerSort.direction;
        } else {
            delete button.dataset.direction;
        }
    });
}

function renderOutliner() {
    const tbody = document.getElementById('outlinerTableBody');
    if (!tbody) {
        return;
    }

    tbody.innerHTML = '';
    if (!planningState.ready) {
        return;
    }

    const parentId = planningState.activeParentId ?? null;
    let order = getPlanningCollection(planningState.childrenByParent, parentId);
    if (!order.length) {
        return;
    }

    const sortConfig = planningState.outlinerSort || { column: 'position', direction: 'asc' };
    if (sortConfig.column === 'position') {
        if (sortConfig.direction === 'desc') {
            order = order.slice().reverse();
        }
    } else {
        order = applyOutlinerSort(order, sortConfig);
    }

    order.forEach(itemId => {
        const item = planningState.itemsById[itemId];
        if (!item) {
            return;
        }

        const row = document.createElement('tr');
        row.dataset.itemId = item.id;
        if (item.id === planningState.selectedItemId) {
            row.classList.add('is-selected');
        }

        row.addEventListener('click', (event) => {
            if (event.target.closest('[contenteditable]') || event.target.closest('select')) {
                return;
            }
            handleItemSelection(item.id, { source: 'outliner', view: activeWorkspaceView });
        });

        const titleCell = document.createElement('td');
        titleCell.className = 'outliner-cell--editable';
        titleCell.contentEditable = 'true';
        titleCell.setAttribute('role', 'textbox');
        titleCell.setAttribute('aria-label', `Edit title for ${item.title || 'item'}`);
        titleCell.dataset.originalValue = item.title || '';
        titleCell.textContent = item.title || '';
        titleCell.addEventListener('keydown', handleEditableKeydown);
        titleCell.addEventListener('focus', () => {
            titleCell.dataset.originalValue = item.title || '';
        });
        titleCell.addEventListener('blur', () => {
            handleOutlinerEdit(item.id, 'title', titleCell.textContent || '');
        });
        row.appendChild(titleCell);

        const typeCell = document.createElement('td');
        typeCell.textContent = formatItemType(item.item_type);
        row.appendChild(typeCell);

        const statusCell = document.createElement('td');
        const statusSelect = document.createElement('select');
        statusSelect.className = 'outliner-status';
        statusSelect.setAttribute('aria-label', `Set status for ${item.title || 'item'}`);
        ['to_do', 'in_progress', 'done', 'revised'].forEach(value => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = formatStatus(value);
            if (value === item.status) {
                option.selected = true;
            }
            statusSelect.appendChild(option);
        });
        statusSelect.addEventListener('change', () => {
            handleOutlinerStatusChange(item.id, statusSelect.value, statusSelect);
        });
        statusSelect.addEventListener('click', (event) => event.stopPropagation());
        statusCell.appendChild(statusSelect);
        row.appendChild(statusCell);

        const povCell = document.createElement('td');
        povCell.className = 'outliner-cell--editable';
        povCell.contentEditable = 'true';
        povCell.setAttribute('role', 'textbox');
        povCell.setAttribute('aria-label', `Edit POV for ${item.title || 'item'}`);
        const currentPov = item.metadata && typeof item.metadata === 'object' ? (item.metadata.pov || '') : '';
        povCell.dataset.originalValue = currentPov;
        povCell.textContent = currentPov;
        povCell.addEventListener('keydown', handleEditableKeydown);
        povCell.addEventListener('focus', () => {
            povCell.dataset.originalValue = item.metadata?.pov || '';
        });
        povCell.addEventListener('blur', () => {
            handleOutlinerEdit(item.id, 'pov', povCell.textContent || '');
        });
        row.appendChild(povCell);

        const wordCell = document.createElement('td');
        wordCell.textContent = formatWordCount(item.word_count);
        row.appendChild(wordCell);

        tbody.appendChild(row);
    });
}

function applyOutlinerSort(order, sortConfig) {
    const items = order
        .map(id => planningState.itemsById[id])
        .filter(Boolean);

    const direction = sortConfig.direction === 'desc' ? -1 : 1;
    items.sort((a, b) => {
        const valueA = getOutlinerSortValue(a, sortConfig.column);
        const valueB = getOutlinerSortValue(b, sortConfig.column);

        if (valueA < valueB) return -1 * direction;
        if (valueA > valueB) return 1 * direction;
        return (a.position || 0) - (b.position || 0);
    });

    return items.map(item => item.id);
}

function getOutlinerSortValue(item, column) {
    switch (column) {
        case 'title':
            return (item.title || '').toLowerCase();
        case 'item_type':
            return (item.item_type || '').toLowerCase();
        case 'status':
            return (item.status || '').toLowerCase();
        case 'pov':
            return (item.metadata && typeof item.metadata === 'object' ? (item.metadata.pov || '') : '').toLowerCase();
        case 'word_count':
            return Number(item.word_count) || 0;
        default:
            return Number(item.position) || 0;
    }
}

function togglePlanningEmptyStates() {
    if (!planningState.ready) {
        return;
    }

    const parentId = planningState.activeParentId ?? null;
    const order = getPlanningCollection(planningState.childrenByParent, parentId);
    const hasItems = order.length > 0;

    const corkboardEmpty = document.getElementById('corkboardEmpty');
    if (corkboardEmpty) {
        corkboardEmpty.hidden = hasItems;
    }

    const outlinerEmpty = document.getElementById('outlinerEmpty');
    if (outlinerEmpty) {
        outlinerEmpty.hidden = hasItems;
    }

    const corkboardOnboarding = document.getElementById('corkboardOnboarding');
    if (corkboardOnboarding) {
        corkboardOnboarding.hidden = hasItems;
    }

    const outlinerOnboarding = document.getElementById('outlinerOnboarding');
    if (outlinerOnboarding) {
        outlinerOnboarding.hidden = hasItems;
    }
}

function updateCorkboardCollectionLabel() {
    const label = document.getElementById('corkboardCollectionLabel');
    if (!label) {
        return;
    }

    if (!planningState.ready) {
        label.textContent = 'Loading corkboard…';
        return;
    }

    const parentId = planningState.activeParentId;
    if (parentId === null || parentId === undefined) {
        label.textContent = 'Top-level chapters and scenes';
        return;
    }

    const parentItem = planningState.itemsById[parentId];
    if (parentItem) {
        label.textContent = `Children of ${parentItem.title || 'binder item'}`;
    } else {
        label.textContent = 'Scenes and chapters';
    }
}

function announcePlanningStatus(message, type = 'info') {
    const status = document.getElementById('planningStatus');
    if (!status) {
        return;
    }

    status.textContent = message || '';
    status.classList.remove('success', 'error');
    if (type === 'success') {
        status.classList.add('success');
    } else if (type === 'error') {
        status.classList.add('error');
    }

    if (planningStatusTimer) {
        clearTimeout(planningStatusTimer);
    }

    if (message) {
        planningStatusTimer = setTimeout(() => {
            status.textContent = '';
            status.classList.remove('success', 'error');
        }, 4000);
    }
}

async function persistPlanningOrder(parentId, order, announceMessage) {
    if (!bookId || !Array.isArray(order)) {
        return;
    }

    try {
        const response = await fetch('api/reorder_items.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                book_id: bookId,
                parent_id: parentId,
                item_ids: order
            })
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Unable to save new order');
        }

        if (announceMessage) {
            announcePlanningStatus(announceMessage, 'success');
        }
    } catch (error) {
        console.error('Failed to reorder items', error);
        announcePlanningStatus(error.message || 'Failed to reorder items', 'error');
    }
}

function applyNewOrder(parentId, newOrder, options = {}) {
    if (!Array.isArray(newOrder)) {
        return;
    }

    const key = planningParentKeyFor(parentId);
    planningState.childrenByParent[key] = newOrder.slice();
    newOrder.forEach((itemId, index) => {
        const item = planningState.itemsById[itemId];
        if (item) {
            item.position = index;
            item.parent_id = parentId;
        }
    });

    planningState.activeParentId = parentId ?? null;
    renderCorkboard();
    renderOutliner();
    syncBinderOrder(parentId ?? null, newOrder);
    persistPlanningOrder(parentId ?? null, newOrder, options.announce);
}

function computeReorderedOrder(order, draggedId, targetId) {
    const filtered = order.filter(id => id !== draggedId);
    if (targetId === undefined || targetId === null) {
        filtered.push(draggedId);
        return filtered;
    }

    const index = filtered.indexOf(targetId);
    if (index === -1) {
        filtered.push(draggedId);
        return filtered;
    }

    filtered.splice(index, 0, draggedId);
    return filtered;
}

function handleCardDragStart(event) {
    const card = event.currentTarget;
    const itemId = Number(card.getAttribute('data-item-id'));
    if (!Number.isFinite(itemId)) {
        return;
    }

    planningDragState.activeId = itemId;
    card.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(itemId));
}

function handleCardDragOver(event) {
    event.preventDefault();
    const card = event.currentTarget;
    card.classList.add('drop-target');
}

function handleCardDragLeave(event) {
    const card = event.currentTarget;
    card.classList.remove('drop-target');
}

function handleCardDrop(event) {
    event.preventDefault();
    const targetCard = event.currentTarget;
    targetCard.classList.remove('drop-target');

    const targetId = Number(targetCard.getAttribute('data-item-id'));
    const draggedId = planningDragState.activeId ?? Number(event.dataTransfer.getData('text/plain'));
    if (!Number.isFinite(draggedId) || draggedId === targetId) {
        return;
    }

    const parentId = planningState.activeParentId ?? null;
    const order = getPlanningCollection(planningState.childrenByParent, parentId);
    const nextOrder = computeReorderedOrder(order, draggedId, targetId);
    applyNewOrder(parentId, nextOrder, { announce: 'Corkboard order updated' });
}

function handleCardDragEnd(event) {
    planningDragState.activeId = null;
    event.currentTarget.classList.remove('is-dragging');
    document.querySelectorAll('.corkboard-card.drop-target').forEach(card => card.classList.remove('drop-target'));
}

function handleCorkboardGridDragOver(event) {
    event.preventDefault();
}

function handleCorkboardGridDrop(event) {
    event.preventDefault();
    const draggedId = planningDragState.activeId ?? Number(event.dataTransfer.getData('text/plain'));
    if (!Number.isFinite(draggedId)) {
        return;
    }

    const parentId = planningState.activeParentId ?? null;
    const order = getPlanningCollection(planningState.childrenByParent, parentId);
    const nextOrder = computeReorderedOrder(order, draggedId, null);
    applyNewOrder(parentId, nextOrder, { announce: 'Card moved to end' });
}

function handleKeyboardReorder(event, itemId, parentId) {
    const key = event.key;
    if (!['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'PageUp', 'PageDown'].includes(key)) {
        return;
    }

    event.preventDefault();
    const order = getPlanningCollection(planningState.childrenByParent, parentId);
    const currentIndex = order.indexOf(itemId);
    if (currentIndex === -1) {
        return;
    }

    let delta = 0;
    if (key === 'ArrowLeft' || key === 'ArrowUp') delta = -1;
    if (key === 'ArrowRight' || key === 'ArrowDown') delta = 1;
    if (key === 'PageUp') delta = -3;
    if (key === 'PageDown') delta = 3;

    let nextIndex = currentIndex + delta;
    nextIndex = Math.max(0, Math.min(order.length - 1, nextIndex));
    if (nextIndex === currentIndex) {
        return;
    }

    const updated = order.slice();
    updated.splice(currentIndex, 1);
    updated.splice(nextIndex, 0, itemId);
    applyNewOrder(parentId, updated, { announce: 'Card reordered via keyboard' });

    requestAnimationFrame(() => {
        const handle = document.querySelector(`.corkboard-card[data-item-id="${itemId}"] .card-drag-handle`);
        if (handle) {
            handle.focus();
        }
    });
}

function handleOutlinerEdit(itemId, field, value) {
    const item = planningState.itemsById[itemId];
    if (!item) {
        return;
    }

    if (field === 'title') {
        const nextTitle = (value || '').trim();
        if (!nextTitle || nextTitle === item.title) {
            return;
        }

        const previous = item.title;
        item.title = nextTitle;
        syncBinderLabel(itemId, nextTitle);
        renderCorkboard();

        updateItemFields(itemId, { title: nextTitle }).then(success => {
            if (!success) {
                item.title = previous;
                syncBinderLabel(itemId, previous);
                renderCorkboard();
                renderOutliner();
            }
        });
    } else if (field === 'pov') {
        const nextPov = (value || '').trim();
        const previous = item.metadata && typeof item.metadata === 'object' ? (item.metadata.pov || '') : '';
        if (nextPov === previous) {
            return;
        }

        if (!item.metadata || typeof item.metadata !== 'object') {
            item.metadata = {};
        }
        item.metadata.pov = nextPov;
        renderCorkboard();

        updateItemFields(itemId, { metadata: { pov: nextPov } }).then(success => {
            if (!success) {
                item.metadata.pov = previous;
                renderCorkboard();
                renderOutliner();
            }
        });
    }
}

function handleOutlinerStatusChange(itemId, nextStatus, selectElement) {
    const item = planningState.itemsById[itemId];
    if (!item || nextStatus === item.status) {
        return;
    }

    const previous = item.status;
    item.status = nextStatus;
    renderCorkboard();

    updateItemFields(itemId, { status: nextStatus }).then(success => {
        if (!success) {
            item.status = previous;
            selectElement.value = previous;
            renderCorkboard();
        }
    });
}

function handleEditableKeydown(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        event.currentTarget.blur();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        const original = event.currentTarget.getAttribute('data-original-value') || '';
        event.currentTarget.textContent = original;
        event.currentTarget.blur();
    }
}

async function updateItemFields(itemId, payload) {
    if (!bookId) {
        return false;
    }

    try {
        const response = await fetch('api/update_item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.assign({
                item_id: itemId,
                book_id: bookId
            }, payload))
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Update failed');
        }

        announcePlanningStatus('Changes saved', 'success');
        return true;
    } catch (error) {
        console.error('Failed to update item metadata', error);
        announcePlanningStatus(error.message || 'Failed to save changes', 'error');
        return false;
    }
}

function formatStatus(status) {
    switch (status) {
        case 'in_progress':
            return 'In Progress';
        case 'to_do':
            return 'To Do';
        case 'done':
            return 'Done';
        case 'revised':
            return 'Revised';
        default:
            return status || '';
    }
}

function formatItemType(type) {
    if (!type) {
        return '';
    }
    return type.charAt(0).toUpperCase() + type.slice(1);
}

function formatWordCount(count) {
    const value = Number(count) || 0;
    return `${value.toLocaleString()} words`;
}

// Dictation module
function initializeDictation() {
    const dictationButtons = document.querySelectorAll('.dictation-btn');

    if (!dictationButtons.length) {
        return;
    }

    dictationButtons.forEach(button => {
        resetDictationStatus(button);
    });

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
        dictationButtons.forEach(button => {
            button.disabled = true;
            button.classList.add('disabled');
            button.setAttribute('title', 'Dictation is not supported in this browser.');
            updateDictationStatus('Dictation is not available in this browser.', button);
        });
        return;
    }

    dictationRecognition = new SpeechRecognition();
    dictationRecognition.continuous = true;
    dictationRecognition.interimResults = true;
    dictationRecognition.lang = document.documentElement.lang || 'en-US';

    dictationRecognition.addEventListener('result', handleDictationResult);
    dictationRecognition.addEventListener('start', () => {
        updateDictationStatus('Listening…');
    });

    dictationRecognition.addEventListener('error', event => {
        console.error('Dictation error:', event.error);
        updateDictationStatus('Voice error. Please try again.');
        stopDictation(true);
    });

    dictationRecognition.addEventListener('end', () => {
        if (dictationIsListening) {
            // Recognition can end automatically; restart if we're still active
            try {
                dictationRecognition.start();
            } catch (err) {
                console.error('Failed to restart dictation:', err);
                updateDictationStatus('Voice ready');
                stopDictation(true, true);
            }
        } else {
            toggleActiveDictationButton(null);
        }
    });

    dictationButtons.forEach(button => {
        button.addEventListener('click', () => toggleDictation(button));
    });
}

function toggleDictation(button) {
    if (!dictationRecognition) {
        return;
    }

    const targetId = button.getAttribute('data-target');
    if (!targetId) {
        return;
    }

    if (dictationIsListening && dictationActiveButton === button) {
        dictationIsListening = false;
        dictationActiveTarget = null;
        const previousButton = dictationActiveButton;
        dictationActiveButton = null;
        resetDictationStatus(previousButton);
        toggleActiveDictationButton(null);
        try {
            dictationRecognition.stop();
        } catch (err) {
            console.error('Failed to stop dictation:', err);
        }
        return;
    }

    if (dictationActiveButton && dictationActiveButton !== button) {
        resetDictationStatus(dictationActiveButton);
    }

    dictationActiveTarget = targetId;
    dictationActiveButton = button;
    dictationIsListening = true;
    toggleActiveDictationButton(button);
    updateDictationStatus('Listening…', button);

    try {
        dictationRecognition.start();
    } catch (err) {
        // Calling start twice throws an error; ignore if we're already listening
        if (err.name !== 'InvalidStateError') {
            console.error('Failed to start dictation:', err);
            updateDictationStatus('Unable to start dictation.', button);
        }
    }
}

function stopDictation(force = false, resetStatus = false) {
    const previousButton = dictationActiveButton;
    dictationIsListening = false;
    if (force) {
        dictationActiveTarget = null;
        dictationActiveButton = null;
        toggleActiveDictationButton(null);
    }

    if (dictationRecognition) {
        try {
            dictationRecognition.stop();
        } catch (err) {
            console.error('Failed to stop dictation:', err);
        }
    }

    if (resetStatus && previousButton) {
        resetDictationStatus(previousButton);
    }
}

function handleDictationResult(event) {
    if (!dictationActiveTarget) {
        return;
    }

    const field = document.getElementById(dictationActiveTarget);
    if (!field) {
        return;
    }

    let finalTranscript = '';
    let interimTranscript = '';

    for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i];
        if (result.isFinal) {
            finalTranscript += result[0].transcript;
        } else {
            interimTranscript += result[0].transcript;
        }
    }

    if (interimTranscript && dictationIsListening) {
        const trimmed = interimTranscript.trim();
        const snippet = trimmed.length > 60 ? `${trimmed.slice(0, 60)}…` : trimmed;
        updateDictationStatus(`Listening… ${snippet}`);
    } else if (dictationIsListening) {
        updateDictationStatus('Listening…');
    }

    if (finalTranscript) {
        insertDictationText(field, finalTranscript);
    }
}

function insertDictationText(field, transcript) {
    const cleanTranscript = transcript.trim();
    if (!cleanTranscript) {
        return;
    }

    const selectionStart = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
    const selectionEnd = typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length;

    const before = field.value.slice(0, selectionStart);
    const after = field.value.slice(selectionEnd);

    const needsLeadingSpace = before && !/\s$/.test(before);
    const insertion = `${needsLeadingSpace ? ' ' : ''}${cleanTranscript}`;

    const newValue = before + insertion + after;
    const newCursorPosition = before.length + insertion.length;

    field.value = newValue;
    if (typeof field.setSelectionRange === 'function') {
        field.setSelectionRange(newCursorPosition, newCursorPosition);
    }
    field.focus();
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

function updateDictationStatus(message, targetButton = dictationActiveButton) {
    const statusElement = resolveDictationStatusElement(targetButton);
    if (statusElement) {
        statusElement.textContent = message;
    }
}

function resetDictationStatus(button) {
    const statusElement = resolveDictationStatusElement(button);
    if (statusElement) {
        const idleMessage = (button && button.getAttribute('data-status-idle')) || statusElement.getAttribute('data-default-text') || '';
        statusElement.textContent = idleMessage;
    }
}

function resolveDictationStatusElement(button) {
    if (button) {
        const statusTarget = button.getAttribute('data-status-target');
        if (statusTarget) {
            const elementById = document.getElementById(statusTarget);
            if (elementById) {
                return elementById;
            }
        }

        const statusSelector = button.getAttribute('data-status-selector');
        if (statusSelector) {
            const elementBySelector = document.querySelector(statusSelector);
            if (elementBySelector) {
                return elementBySelector;
            }
        }
    }

    return document.getElementById('dictationStatus');
}

function toggleActiveDictationButton(activeButton) {
    document.querySelectorAll('.dictation-btn').forEach(button => {
        button.classList.toggle('recording', button === activeButton);
    });
}

// AI chat voice playback
function supportsSpeechSynthesis() {
    return typeof window !== 'undefined' && 'speechSynthesis' in window;
}

function disableAIVoiceButton(button, message = 'Voice replies unavailable') {
    if (!button) {
        return;
    }

    button.disabled = true;
    button.classList.add('disabled');
    button.setAttribute('title', message);
    button.setAttribute('aria-pressed', 'false');

    const icon = button.querySelector('.icon');
    if (icon) {
        icon.textContent = '🔇';
    }

    const label = button.querySelector('.label');
    if (label) {
        label.textContent = message;
    } else {
        button.textContent = `🔇 ${message}`;
    }
}

function initializeAIChatVoice() {
    const toggleButton = document.getElementById('aiVoiceToggle');
    if (!toggleButton) {
        aiVoiceMode = 'none';
        return;
    }

    aiVoiceSelectElement = document.getElementById('aiVoiceSelect');

    const fetchSupported = typeof window !== 'undefined' && typeof window.fetch === 'function';
    if (isNetworkVoiceMode() && (!fetchSupported || !aiVoiceVoices.length)) {
        aiVoiceMode = supportsSpeechSynthesis() ? 'browser' : 'none';
    }

    if (!isNetworkVoiceMode() && !supportsSpeechSynthesis()) {
        disableAIVoiceButton(toggleButton);
        aiVoiceMode = 'none';
        return;
    }

    updateAIVoiceToggleButton(toggleButton);

    toggleButton.addEventListener('click', () => {
        aiVoiceEnabled = !aiVoiceEnabled;
        updateAIVoiceToggleButton(toggleButton);
        if (!aiVoiceEnabled) {
            cancelAIVoicePlayback();
        }
    });

    if (isNetworkVoiceMode() && aiVoiceSelectElement) {
        aiVoiceSelectElement.addEventListener('change', () => {
            if (aiVoiceEnabled) {
                cancelAIVoicePlayback();
            }
        });
    }
}

function updateAIVoiceToggleButton(button) {
    if (!button) {
        return;
    }

    button.classList.toggle('active', aiVoiceEnabled);
    button.setAttribute('aria-pressed', aiVoiceEnabled ? 'true' : 'false');

    const icon = button.querySelector('.icon');
    if (icon) {
        icon.textContent = aiVoiceEnabled ? '🔊' : '🔈';
    }

    const label = button.querySelector('.label');
    if (label) {
        label.textContent = aiVoiceEnabled ? 'Voice replies on' : 'Voice replies off';
    } else {
        button.textContent = aiVoiceEnabled ? '🔊 Voice replies on' : '🔈 Voice replies off';
    }
}

function speakAIResponse(message) {
    if (!aiVoiceEnabled) {
        return;
    }

    const plainText = getPlainTextForSpeech(message);
    if (!plainText) {
        return;
    }

    if (isNetworkVoiceMode()) {
        playNetworkVoiceResponse(plainText);
        return;
    }

    if (!supportsSpeechSynthesis()) {
        return;
    }

    speakWithBrowserVoice(plainText);
}

function speakWithBrowserVoice(text) {
    cancelAIVoicePlayback();

    aiVoiceUtterance = new SpeechSynthesisUtterance(text);
    aiVoiceUtterance.rate = 1;
    aiVoiceUtterance.onend = () => {
        aiVoiceUtterance = null;
    };
    aiVoiceUtterance.onerror = () => {
        aiVoiceUtterance = null;
    };

    window.speechSynthesis.speak(aiVoiceUtterance);
}

function playNetworkVoiceResponse(text) {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        console.warn('Fetch API is not available for AI voice playback.');
        return;
    }

    cancelAIVoicePlayback();

    const selectedVoice = getSelectedNetworkVoice();
    const payload = { text, provider: aiVoiceMode };

    if (selectedVoice) {
        if (selectedVoice.name) {
            payload.voice = selectedVoice.name;
        }
        if (selectedVoice.languageCode) {
            payload.languageCode = selectedVoice.languageCode;
        }
        if (selectedVoice.prompt) {
            payload.prompt = selectedVoice.prompt;
        }
        if (selectedVoice.model) {
            payload.model = selectedVoice.model;
        }
        if (selectedVoice.voiceId) {
            payload.voiceId = selectedVoice.voiceId;
        }
        if (selectedVoice.voiceSettings) {
            payload.voice_settings = selectedVoice.voiceSettings;
        }
        if (selectedVoice.outputFormat) {
            payload.output_format = selectedVoice.outputFormat;
        }
    }

    payload.audioEncoding = selectedVoice && selectedVoice.audioEncoding
        ? selectedVoice.audioEncoding
        : aiVoiceDefaultEncoding;

    const controller = supportsAbortController ? new AbortController() : null;
    aiVoiceRequestController = controller;

    const fetchOptions = {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    };

    if (controller) {
        fetchOptions.signal = controller.signal;
    }

    fetch(aiVoiceEndpoint, fetchOptions)
        .then((response) => response.json())
        .then((result) => {
            if (!result || !result.success || !result.audioContent) {
                console.error('AI voice playback failed', result && result.message ? result.message : 'Unknown error');
                return;
            }

            const mimeType = result.mimeType || 'audio/mpeg';
            aiVoiceAudioElement = new Audio(`data:${mimeType};base64,${result.audioContent}`);
            aiVoiceAudioElement.addEventListener('ended', () => {
                aiVoiceAudioElement = null;
            });
            aiVoiceAudioElement.addEventListener('error', () => {
                aiVoiceAudioElement = null;
            });
            aiVoiceAudioElement.play().catch((error) => {
                console.error('AI voice playback failed', error);
                aiVoiceAudioElement = null;
            });
        })
        .catch((error) => {
            if (error && error.name === 'AbortError') {
                return;
            }
            console.error('AI voice request failed', error);
        })
        .finally(() => {
            aiVoiceRequestController = null;
        });
}

function cancelAIVoicePlayback() {
    if (isNetworkVoiceMode()) {
        if (aiVoiceRequestController) {
            aiVoiceRequestController.abort();
            aiVoiceRequestController = null;
        }
        if (aiVoiceAudioElement) {
            aiVoiceAudioElement.pause();
            aiVoiceAudioElement = null;
        }
        return;
    }

    if (!supportsSpeechSynthesis()) {
        return;
    }

    if (aiVoiceUtterance) {
        window.speechSynthesis.cancel();
        aiVoiceUtterance = null;
    }
}

function getSelectedNetworkVoice() {
    let selectedVoice = null;

    if (aiVoiceSelectElement && aiVoiceSelectElement.options.length) {
        const option = aiVoiceSelectElement.options[aiVoiceSelectElement.selectedIndex];
        if (option) {
            selectedVoice = {
                id: option.value,
                name: option.getAttribute('data-name') || option.value,
                languageCode: option.getAttribute('data-language') || null,
                model: option.getAttribute('data-model') || null,
                prompt: option.getAttribute('data-prompt') || null,
                audioEncoding: (option.getAttribute('data-audio') || aiVoiceDefaultEncoding).toUpperCase(),
                voiceId: option.getAttribute('data-voice-id') || null,
                outputFormat: option.getAttribute('data-output-format') || null
            };
        }
    }

    if (!selectedVoice && aiVoiceVoices.length) {
        return aiVoiceVoices[0];
    }

    if (selectedVoice && aiVoiceVoices.length) {
        const match = aiVoiceVoices.find((voice) => {
            if (!voice) {
                return false;
            }
            if (voice.id && voice.id === selectedVoice.id) {
                return true;
            }
            if (voice.voiceId && voice.voiceId === selectedVoice.voiceId) {
                return true;
            }
            return false;
        });

        if (match) {
            return Object.assign({}, match, selectedVoice);
        }

        return selectedVoice;
    }

    return selectedVoice;
}

function getPlainTextForSpeech(message) {
    if (!message) {
        return '';
    }

    return String(message)
        .replace(/\r?\n/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function isNetworkVoiceMode(mode = aiVoiceMode) {
    return networkVoiceModes.includes(mode);
}

// New item modal
function showNewItemModal(parentId) {
    document.getElementById('parentItemId').value = parentId || '';
    document.getElementById('newItemModal').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('itemTitle').focus();
    }, 100);
}

function closeNewItemModal() {
    document.getElementById('newItemModal').style.display = 'none';
    document.getElementById('itemTitle').value = '';
    document.getElementById('itemSynopsis').value = '';
}

async function createNewItem() {
    const parentId = document.getElementById('parentItemId').value || null;
    const itemType = document.getElementById('itemType').value;
    const title = document.getElementById('itemTitle').value;
    const synopsis = document.getElementById('itemSynopsis').value;

    if (!title) {
        alert('Please enter a title');
        return;
    }

    try {
        const response = await fetch('api/create_item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                parent_id: parentId,
                item_type: itemType,
                title: title,
                synopsis: synopsis
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to the new item
            window.location.href = `book.php?id=${bookId}&item=${result.item_id}`;
        } else {
            alert('Failed to create item: ' + result.message);
        }
    } catch (error) {
        console.error('Create failed:', error);
        alert('Failed to create item');
    }
}

// Update item status
async function updateItemStatus(itemId, status) {
    try {
        const response = await fetch('api/update_item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                item_id: itemId,
                book_id: bookId,
                status: status
            })
        });

        const result = await response.json();
        if (result.success) {
            showSavedIndicator();
        }
    } catch (error) {
        console.error('Status update failed:', error);
    }
}

// Delete item
async function deleteItem(itemId) {
    if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
        return;
    }

    try {
        const response = await fetch('api/delete_item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                item_id: itemId,
                book_id: bookId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to book root
            window.location.href = `book.php?id=${bookId}`;
        } else {
            alert('Failed to delete item: ' + result.message);
        }
    } catch (error) {
        console.error('Delete failed:', error);
        alert('Failed to delete item');
    }
}

// Snapshot
async function createSnapshot(itemId) {
    const title = prompt('Enter a name for this snapshot (optional):');
    if (title === null) return; // User cancelled

    try {
        const response = await fetch('api/create_snapshot.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                item_id: itemId,
                title: title
            })
        });

        const result = await response.json();

        if (result.success) {
            alert('Snapshot created successfully!');
        } else {
            alert('Failed to create snapshot: ' + result.message);
        }
    } catch (error) {
        console.error('Snapshot failed:', error);
        alert('Failed to create snapshot');
    }
}

// AI Chat
function toggleAIChat() {
    const sidebar = document.getElementById('aiChatSidebar');
    sidebar.classList.toggle('active');
}

async function sendAIMessage() {
    const input = document.getElementById('aiChatInput');
    const message = input.value.trim();

    if (!message) return;

    // Add user message to chat
    addUserMessage(message);
    input.value = '';

    // Show typing indicator
    const typingIndicator = addAITypingIndicator();

    try {
        const response = await fetch('api/ai_chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                item_id: itemId,
                message: message
            })
        });

        const result = await response.json();

        // Remove typing indicator
        typingIndicator.remove();

        if (result.success) {
            addAIMessage(result.response);

            // Show notification if items were created or updated
            const hasItemsCreated = result.items_created && result.items_created.length > 0;
            const hasItemsUpdated = result.items_updated && result.items_updated.length > 0;

            if (hasItemsCreated || hasItemsUpdated) {
                showBinderUpdateNotification(
                    result.items_created || [],
                    result.items_updated || []
                );
            }

            // Show notification if characters were created or updated
            const hasCharsCreated = result.characters_created && result.characters_created.length > 0;
            const hasCharsUpdated = result.characters_updated && result.characters_updated.length > 0;

            if (hasCharsCreated || hasCharsUpdated) {
                showCharacterUpdateNotification(
                    result.characters_created || [],
                    result.characters_updated || []
                );
            }
        } else {
            let errorMessage = 'Sorry, I encountered an error. Please try again.';
            if (result.message) {
                errorMessage = result.message;
            }
            if (result.debug) {
                console.log('AI Error Debug Info:', result.debug);
                errorMessage += '\n\nDebug Info:\n';
                errorMessage += `- API Configured: ${result.debug.api_configured}\n`;
                errorMessage += `- cURL Available: ${result.debug.curl_available}\n`;
            }
            addAIMessage(errorMessage);
        }
    } catch (error) {
        console.error('AI chat failed:', error);
        typingIndicator.remove();
        addAIMessage('Sorry, I encountered an error. Please try again.\n\nError: ' + error.message);
    }
}

function addUserMessage(message) {
    const messagesContainer = document.getElementById('aiChatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'user-message';
    messageDiv.innerHTML = `
        <div class="user-avatar">👤</div>
        <div class="message-content">${escapeHtml(message)}</div>
    `;
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function addAIMessage(message) {
    const messagesContainer = document.getElementById('aiChatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'ai-message';
    // Preserve line breaks by converting \n to <br>
    const formattedMessage = escapeHtml(message).replace(/\n/g, '<br>');
    messageDiv.innerHTML = `
        <div class="ai-avatar">🤖</div>
        <div class="message-content">${formattedMessage}</div>
    `;
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    speakAIResponse(message);
}

function addAITypingIndicator() {
    const messagesContainer = document.getElementById('aiChatMessages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'ai-message typing-indicator';
    typingDiv.innerHTML = `
        <div class="ai-avatar">🤖</div>
        <div class="message-content">Thinking...</div>
    `;
    messagesContainer.appendChild(typingDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    return typingDiv;
}

// Enter key to send message
document.addEventListener('DOMContentLoaded', function() {
    const aiInput = document.getElementById('aiChatInput');
    if (aiInput) {
        aiInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendAIMessage();
            }
        });
    }
});

// Show notification when AI creates or updates binder items
function showBinderUpdateNotification(createdItems, updatedItems) {
    // Remove existing notification if any
    const existing = document.getElementById('binderUpdateNotification');
    if (existing) {
        existing.remove();
    }

    // Build message based on what changed
    let messages = [];
    if (createdItems.length > 0) {
        const itemsList = createdItems.map(item => `${item.type}: "${item.title}"`).join(', ');
        messages.push(`<strong>Created:</strong> ${itemsList}`);
    }
    if (updatedItems.length > 0) {
        const itemsList = updatedItems.map(item => {
            const fields = item.updated_fields.join(', ');
            return `"${item.title}" (${fields})`;
        }).join(', ');
        messages.push(`<strong>Updated:</strong> ${itemsList}`);
    }

    // Create notification banner
    const notification = document.createElement('div');
    notification.id = 'binderUpdateNotification';
    notification.style.cssText = `
        position: fixed;
        top: 60px;
        right: 20px;
        background: #4CAF50;
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 10000;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;

    notification.innerHTML = `
        <div style="margin-bottom: 10px;">
            <strong>✓ Binder Updated</strong><br>
            <small>${messages.join('<br>')}</small>
        </div>
        <button onclick="location.reload()" style="
            background: white;
            color: #4CAF50;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
        ">Refresh to See</button>
        <button onclick="this.parentElement.remove()" style="
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        ">Dismiss</button>
    `;

    document.body.appendChild(notification);

    // Add animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);

    // Auto-dismiss after 15 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideIn 0.3s ease-out reverse';
            setTimeout(() => notification.remove(), 300);
        }
    }, 15000);
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show character update notification
function showCharacterUpdateNotification(createdCharacters, updatedCharacters) {
    // Remove existing notification if any
    const existing = document.getElementById('characterUpdateNotification');
    if (existing) {
        existing.remove();
    }

    // Build message based on what changed
    let messages = [];
    if (createdCharacters.length > 0) {
        const charList = createdCharacters.map(char => `${char.name} (${char.role})`).join(', ');
        messages.push(`<strong>Created:</strong> ${charList}`);
    }
    if (updatedCharacters.length > 0) {
        const charList = updatedCharacters.map(char => {
            const fields = char.updated_fields.join(', ');
            return `${char.name} (${fields})`;
        }).join(', ');
        messages.push(`<strong>Updated:</strong> ${charList}`);
    }

    // Create notification banner
    const notification = document.createElement('div');
    notification.id = 'characterUpdateNotification';
    notification.style.cssText = `
        position: fixed;
        top: 120px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 10000;
        max-width: 400px;
        animation: slideIn 0.3s ease-out;
    `;

    notification.innerHTML = `
        <div style="margin-bottom: 10px;">
            <strong>👥 Characters Updated</strong><br>
            <small>${messages.join('<br>')}</small>
        </div>
        <button onclick="window.location.href='characters.php?id=${bookId}'" style="
            background: white;
            color: #10b981;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
        ">View Characters</button>
        <button onclick="this.parentElement.remove()" style="
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        ">Dismiss</button>
    `;

    document.body.appendChild(notification);

    // Auto-dismiss after 15 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideIn 0.3s ease-out reverse';
            setTimeout(() => notification.remove(), 300);
        }
    }, 15000);
}

// Placeholder functions for features to be implemented
function showCharactersPanel() {
    window.location.href = 'characters.php?id=' + bookId;
}

function showItemMetadata(itemId) {
    alert('Metadata editor coming soon!');
}

function showExportModal() {
    alert('Export functionality coming soon!');
}
