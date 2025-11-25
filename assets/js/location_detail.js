/**
 * Location Detail Page JavaScript
 * Handles editing, auto-save, and tabs
 */

let autoSaveTimer;
let hasUnsavedChanges = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeAutoSave();
});

// Tab Switching
function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.location-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    event.target.classList.add('active');

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById('tab-' + tabName).classList.add('active');
}

// Initialize Auto-Save
function initializeAutoSave() {
    const fields = [
        'description', 'atmosphere', 'significance', 'notes'
    ];

    fields.forEach(fieldId => {
        const element = document.getElementById(fieldId);
        if (element) {
            element.addEventListener('input', function() {
                hasUnsavedChanges = true;
                showSavingIndicator();
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(saveLocation, 2000); // Auto-save after 2 seconds
            });
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

// Save Location
async function saveLocation() {
    const data = {
        book_id: bookId,
        location_id: locationId,
        description: document.getElementById('description').value,
        atmosphere: document.getElementById('atmosphere').value,
        significance: document.getElementById('significance').value,
        notes: document.getElementById('notes').value
    };

    try {
        const response = await fetch('api/update_location.php', {
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

// Delete Location
async function deleteLocation() {
    if (!confirm(`Are you sure you want to delete ${locationName}? This action cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch('api/delete_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                location_id: locationId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to locations page
            window.location.href = `locations.php?id=${bookId}`;
        } else {
            alert('Failed to delete location: ' + result.message);
        }
    } catch (error) {
        console.error('Delete failed:', error);
        alert('Failed to delete location');
    }
}

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
