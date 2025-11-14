/**
 * Character Detail Page JavaScript
 * Handles editing, auto-save, tabs, and interactions
 */

let autoSaveTimer;
let hasUnsavedChanges = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeAutoSave();
    loadDialogueHistory();
});

// Tab Switching
function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.character-tab').forEach(tab => {
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
        'age', 'gender', 'role',
        'physical_description', 'personality', 'speech_patterns',
        'voice_description', 'dialogue_examples', 'background',
        'motivation', 'arc', 'relationships', 'notes'
    ];

    fields.forEach(fieldId => {
        const element = document.getElementById(fieldId);
        if (element) {
            element.addEventListener('input', function() {
                hasUnsavedChanges = true;
                showSavingIndicator();
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(saveCharacter, 2000); // Auto-save after 2 seconds
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

// Save Character
async function saveCharacter() {
    const data = {
        book_id: bookId,
        character_id: characterId,
        age: document.getElementById('age').value || null,
        gender: document.getElementById('gender').value,
        role: document.getElementById('role').value,
        physical_description: document.getElementById('physical_description').value,
        personality: document.getElementById('personality').value,
        speech_patterns: document.getElementById('speech_patterns').value,
        voice_description: document.getElementById('voice_description').value,
        dialogue_examples: document.getElementById('dialogue_examples').value,
        background: document.getElementById('background').value,
        motivation: document.getElementById('motivation').value,
        arc: document.getElementById('arc').value,
        relationships: document.getElementById('relationships').value,
        notes: document.getElementById('notes').value
    };

    try {
        const response = await fetch('api/update_character.php', {
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

// Delete Character
async function deleteCharacter() {
    if (!confirm(`Are you sure you want to delete ${characterName}? This action cannot be undone and will remove all associated images and dialogue history.`)) {
        return;
    }

    try {
        const response = await fetch('api/delete_character.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                character_id: characterId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to characters page
            window.location.href = `characters.php?id=${bookId}`;
        } else {
            alert('Failed to delete character: ' + result.message);
        }
    } catch (error) {
        console.error('Delete failed:', error);
        alert('Failed to delete character');
    }
}

// Chat with Character
function chatWithCharacter() {
    // Store character ID for chat mode
    sessionStorage.setItem('chatCharacterId', characterId);
    // Redirect to book page with chat open
    window.location.href = `book.php?id=${bookId}&chat=character&character=${characterId}`;
}

// Set Primary Image
async function setPrimaryImage(imageId) {
    try {
        const response = await fetch('api/set_primary_image.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                character_id: characterId,
                image_id: imageId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show new primary image
            window.location.reload();
        } else {
            alert('Failed to set primary image: ' + result.message);
        }
    } catch (error) {
        console.error('Set primary image failed:', error);
        alert('Failed to set primary image');
    }
}

// Load Dialogue History
async function loadDialogueHistory() {
    const container = document.getElementById('dialogueHistory');
    if (!container) return;

    try {
        const response = await fetch(`api/get_character_dialogues.php?character_id=${characterId}`);
        const result = await response.json();

        if (result.success && result.dialogues && result.dialogues.length > 0) {
            let html = '<div class="dialogues-list">';

            result.dialogues.forEach(dialogue => {
                const date = new Date(dialogue.created_at).toLocaleString();
                html += `
                    <div class="dialogue-item">
                        <div class="dialogue-date">${date}</div>
                        ${dialogue.context ? `<div class="dialogue-context"><strong>Context:</strong> ${escapeHtml(dialogue.context)}</div>` : ''}
                        <div class="dialogue-exchange">
                            <div class="dialogue-user">
                                <strong>You:</strong> ${escapeHtml(dialogue.user_message)}
                            </div>
                            <div class="dialogue-character">
                                <strong>${escapeHtml(characterName)}:</strong> ${escapeHtml(dialogue.character_response)}
                            </div>
                        </div>
                        <button class="btn btn-sm" onclick="saveDialogueToScene(${dialogue.id})">💾 Save to Scene</button>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p style="text-align: center; color: var(--text-muted);">No dialogue history yet.</p>';
        }
    } catch (error) {
        console.error('Failed to load dialogue history:', error);
        container.innerHTML = '<p style="text-align: center; color: var(--error-color);">Failed to load dialogue history.</p>';
    }
}

// Save Dialogue to Scene (placeholder)
function saveDialogueToScene(dialogueId) {
    alert('Save to scene feature coming soon! This will let you export good dialogue snippets directly into your manuscript.');
}

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
