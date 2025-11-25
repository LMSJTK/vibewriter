/**
 * Plot Thread Detail Page JavaScript
 * Handles editing, auto-save
 */

let autoSaveTimer;
let hasUnsavedChanges = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeAutoSave();
});

// Initialize Auto-Save
function initializeAutoSave() {
    const fields = [
        'thread_type', 'status', 'color', 'description'
    ];

    fields.forEach(fieldId => {
        const element = document.getElementById(fieldId);
        if (element) {
            element.addEventListener('input', function() {
                hasUnsavedChanges = true;
                showSavingIndicator();
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(saveThread, 2000); // Auto-save after 2 seconds
            });

            // For select dropdowns, also listen to change event
            if (element.tagName === 'SELECT') {
                element.addEventListener('change', function() {
                    hasUnsavedChanges = true;
                    showSavingIndicator();
                    clearTimeout(autoSaveTimer);
                    autoSaveTimer = setTimeout(saveThread, 2000);
                });
            }
        }
    });

    // Warn before leaving if unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

// Save Thread
async function saveThread() {
    const data = {
        book_id: bookId,
        thread_id: threadId,
        thread_type: document.getElementById('thread_type').value,
        status: document.getElementById('status').value,
        color: document.getElementById('color').value,
        description: document.getElementById('description').value
    };

    try {
        const response = await fetch('api/update_plot_thread.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            hasUnsavedChanges = false;
            showSavedIndicator();
        } else {
            showErrorIndicator();
            console.error('Save failed:', result.message);
        }
    } catch (error) {
        console.error('Save error:', error);
        showErrorIndicator();
    }
}

// Save Indicators
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

// Delete Thread
async function deleteThread() {
    if (!confirm(`Are you sure you want to delete "${threadTitle}"? This action cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch('api/delete_plot_thread.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                thread_id: threadId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to plot threads page
            window.location.href = `plot_threads.php?id=${bookId}`;
        } else {
            alert('Failed to delete plot thread: ' + result.message);
        }
    } catch (error) {
        console.error('Delete failed:', error);
        alert('Failed to delete plot thread');
    }
}

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
