/**
 * Character Detail Page JavaScript
 * Handles editing, auto-save, tabs, and interactions
 */

let autoSaveTimer;
let hasUnsavedChanges = false;
const detailBookId = typeof window !== 'undefined' && typeof window.characterBookId !== 'undefined'
    ? window.characterBookId
    : (typeof window !== 'undefined' && typeof window.bookId !== 'undefined' ? window.bookId : null);

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
        book_id: detailBookId,
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
                book_id: detailBookId,
                character_id: characterId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Redirect to characters page
            window.location.href = `characters.php?id=${detailBookId}`;
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
    if (!characterHasDialogueCapability) {
        alert('This character needs personality and speech patterns defined before chat mode can be used. Please fill in these fields in the Personality tab.');
        // Switch to personality tab
        document.querySelectorAll('.character-tab').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.querySelector('.character-tab:nth-child(2)').classList.add('active');
        document.getElementById('tab-personality').classList.add('active');
        return;
    }

    // Open chat sidebar
    document.getElementById('characterChatSidebar').classList.add('active');
    setTimeout(() => {
        document.getElementById('characterChatInput').focus();
    }, 300);
}

// Close Character Chat
function closeCharacterChat() {
    document.getElementById('characterChatSidebar').classList.remove('active');
}

// Send Character Message
async function sendCharacterMessage() {
    const input = document.getElementById('characterChatInput');
    const message = input.value.trim();
    const contextInput = document.getElementById('sceneContext');
    const context = contextInput.value.trim() || null;

    if (!message) return;

    // Add user message to chat
    addUserMessageToChat(message);
    input.value = '';

    // Show typing indicator
    const typingIndicator = addCharacterTypingIndicator();

    try {
        const response = await fetch('api/character_chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: detailBookId,
                character_id: characterId,
                message: message,
                context: context
            })
        });

        const result = await response.json();

        // Remove typing indicator
        typingIndicator.remove();

        if (result.success) {
            addCharacterMessageToChat(result.response);

            // Reload dialogue history in the dialogue tab (if we're on it)
            if (document.getElementById('tab-dialogue').classList.contains('active')) {
                loadDialogueHistory();
            }
        } else {
            addCharacterMessageToChat('*[Error: ' + (result.message || 'Failed to get response') + ']*');
        }
    } catch (error) {
        console.error('Character chat failed:', error);
        typingIndicator.remove();
        addCharacterMessageToChat('*[Error: Failed to communicate with character]*');
    }
}

// Add user message to chat
function addUserMessageToChat(message) {
    const messagesContainer = document.getElementById('characterChatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'user-message';
    messageDiv.innerHTML = `
        <div class="user-avatar">👤</div>
        <div class="message-content">${escapeHtml(message)}</div>
    `;
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Add character message to chat
function addCharacterMessageToChat(message) {
    const messagesContainer = document.getElementById('characterChatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'character-message';
    const characterInitial = characterName.charAt(0).toUpperCase();
    messageDiv.innerHTML = `
        <div class="character-avatar">${characterInitial}</div>
        <div class="message-content">${escapeHtml(message)}</div>
    `;
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    if (typeof speakAIResponse === 'function') {
        speakAIResponse(message);
    }
}

// Add typing indicator
function addCharacterTypingIndicator() {
    const messagesContainer = document.getElementById('characterChatMessages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'character-message typing-indicator';
    const characterInitial = characterName.charAt(0).toUpperCase();
    typingDiv.innerHTML = `
        <div class="character-avatar">${characterInitial}</div>
        <div class="message-content">
            <span class="typing-dots">
                <span>.</span><span>.</span><span>.</span>
            </span>
        </div>
    `;
    messagesContainer.appendChild(typingDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    return typingDiv;
}

// Enter key to send message (Shift+Enter for new line)
document.addEventListener('DOMContentLoaded', function() {
    const chatInput = document.getElementById('characterChatInput');
    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendCharacterMessage();
            }
        });
    }
});

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

// Generate Character Image
async function generateImage() {
    const generateBtn = document.getElementById('generateImageBtn');

    // Confirm if character has limited description
    const physicalDesc = document.getElementById('physical_description')?.value;
    if (!physicalDesc || physicalDesc.length < 20) {
        if (!confirm('This character has minimal physical description. The AI will do its best, but you may want to add more details in the Profile tab first. Generate anyway?')) {
            return;
        }
    }

    // Disable button and show loading state
    if (generateBtn) {
        generateBtn.disabled = true;
        generateBtn.innerHTML = '⏳ Generating...';
    }

    try {
        const response = await fetch('api/generate_character_image.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: detailBookId,
                character_id: characterId,
                additional_prompt: null // Could add a prompt input field later
            })
        });

        const result = await response.json();

        if (result.success) {
            // Show success message
            alert('Image generated successfully! Reloading page to display...');
            // Reload page to show new image
            window.location.reload();
        } else {
            alert('Failed to generate image: ' + (result.message || 'Unknown error'));
            console.error('Generation error:', result);
        }
    } catch (error) {
        console.error('Image generation failed:', error);
        alert('Failed to generate image: ' + error.message);
    } finally {
        // Re-enable button
        if (generateBtn) {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '🎨 Generate Image';
        }
    }
}

const characterDetailExports = typeof window !== 'undefined'
    ? window
    : (typeof globalThis !== 'undefined' ? globalThis : {});
characterDetailExports.switchTab = switchTab;
characterDetailExports.deleteCharacter = deleteCharacter;
characterDetailExports.chatWithCharacter = chatWithCharacter;
characterDetailExports.closeCharacterChat = closeCharacterChat;
characterDetailExports.sendCharacterMessage = sendCharacterMessage;
characterDetailExports.setPrimaryImage = setPrimaryImage;
characterDetailExports.generateImage = generateImage;
characterDetailExports.saveDialogueToScene = saveDialogueToScene;

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
