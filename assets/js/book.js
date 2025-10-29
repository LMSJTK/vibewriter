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

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeEditor();
    initializeTreeToggles();
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

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Placeholder functions for features to be implemented
function showCharactersPanel() {
    alert('Characters panel coming soon!');
}

function showItemMetadata(itemId) {
    alert('Metadata editor coming soon!');
}

function showExportModal() {
    alert('Export functionality coming soon!');
}
