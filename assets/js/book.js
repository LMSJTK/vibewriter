/**
 * VibeWriter Book View JavaScript
 * Handles editor interactions, auto-save, AI chat, and tree management
 */

// Current book and item IDs
const bookId = new URLSearchParams(window.location.search).get('id');
const itemId = new URLSearchParams(window.location.search).get('item');

// Auto-save timer
let autoSaveTimer;
let hasUnsavedChanges = false;

// Dictation state
let dictationRecognition = null;
let dictationIsListening = false;
let dictationActiveTarget = null;
let dictationActiveButton = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeEditor();
    initializeTreeToggles();
    initializeDictation();
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

// Dictation module
function initializeDictation() {
    const dictationButtons = document.querySelectorAll('.dictation-btn');
    const statusElement = document.getElementById('dictationStatus');

    if (!dictationButtons.length) {
        return;
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
        dictationButtons.forEach(button => {
            button.disabled = true;
            button.classList.add('disabled');
            button.setAttribute('title', 'Dictation is not supported in this browser.');
        });

        if (statusElement) {
            statusElement.textContent = 'Dictation is not available in this browser.';
        }
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
                stopDictation(true);
            }
        } else {
            updateDictationStatus('Voice ready');
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
        dictationActiveButton = null;
        updateDictationStatus('Voice ready');
        toggleActiveDictationButton(null);
        try {
            dictationRecognition.stop();
        } catch (err) {
            console.error('Failed to stop dictation:', err);
        }
        return;
    }

    dictationActiveTarget = targetId;
    dictationActiveButton = button;
    dictationIsListening = true;
    toggleActiveDictationButton(button);
    updateDictationStatus('Listening…');

    try {
        dictationRecognition.start();
    } catch (err) {
        // Calling start twice throws an error; ignore if we're already listening
        if (err.name !== 'InvalidStateError') {
            console.error('Failed to start dictation:', err);
            updateDictationStatus('Unable to start dictation.');
        }
    }
}

function stopDictation(force = false) {
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

function updateDictationStatus(message) {
    const statusElement = document.getElementById('dictationStatus');
    if (statusElement) {
        statusElement.textContent = message;
    }
}

function toggleActiveDictationButton(activeButton) {
    document.querySelectorAll('.dictation-btn').forEach(button => {
        button.classList.toggle('recording', button === activeButton);
    });
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
