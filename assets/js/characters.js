/**
 * VibeWriter Characters JavaScript
 * Handles character creation, viewing, and AI chat integration
 */

// Show new character modal
function showNewCharacterModal() {
    document.getElementById('newCharacterModal').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('characterName').focus();
    }, 100);
}

// Close new character modal
function closeNewCharacterModal() {
    document.getElementById('newCharacterModal').style.display = 'none';
    document.getElementById('newCharacterForm').reset();
}

// Create new character
async function createCharacter(event) {
    event.preventDefault();

    const data = {
        book_id: bookId,
        name: document.getElementById('characterName').value,
        role: document.getElementById('characterRole').value,
        age: document.getElementById('characterAge').value || null,
        gender: document.getElementById('characterGender').value,
        physical_description: document.getElementById('characterPhysical').value,
        personality: document.getElementById('characterPersonality').value,
        background: document.getElementById('characterBackground').value
    };

    try {
        const response = await fetch('api/create_character.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show new character
            window.location.reload();
        } else {
            alert('Failed to create character: ' + result.message);
        }
    } catch (error) {
        console.error('Create character failed:', error);
        alert('Failed to create character');
    }
}

// View character details
function viewCharacter(characterId) {
    window.location.href = `character_detail.php?id=${bookId}&character=${characterId}`;
}

// Chat with character (personality mode)
function chatWithCharacter(characterId) {
    // Store character ID in session storage
    sessionStorage.setItem('chatCharacterId', characterId);

    // Open AI chat with character context
    toggleAIChat();

    // Add system message about character mode
    const messagesContainer = document.getElementById('aiChatMessages');
    const characterCard = document.querySelector(`[data-character-id="${characterId}"]`);
    const characterName = characterCard ? characterCard.querySelector('h3').textContent : 'Character';

    const modeMessage = document.createElement('div');
    modeMessage.className = 'system-message';
    modeMessage.style.cssText = `
        background: #10b981;
        color: white;
        padding: 10px 15px;
        border-radius: 8px;
        margin: 10px 0;
        text-align: center;
        font-size: 0.9em;
    `;
    modeMessage.textContent = `💬 Chat mode: Speaking with ${characterName}`;
    messagesContainer.appendChild(modeMessage);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Override sendAIMessage to handle character chat mode
if (typeof window.originalSendAIMessage === 'undefined') {
    window.originalSendAIMessage = sendAIMessage;

    window.sendAIMessage = async function() {
        const chatCharacterId = sessionStorage.getItem('chatCharacterId');

        if (chatCharacterId) {
            // Character chat mode - send to special endpoint
            await sendCharacterChatMessage(chatCharacterId);
        } else {
            // Normal AI chat
            await window.originalSendAIMessage();
        }
    };
}

// Send message in character chat mode
async function sendCharacterChatMessage(characterId) {
    const input = document.getElementById('aiChatInput');
    const message = input.value.trim();

    if (!message) return;

    // Add user message to chat
    addUserMessage(message);
    input.value = '';

    // Show typing indicator
    const typingIndicator = addAITypingIndicator();

    try {
        const response = await fetch('api/character_chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                book_id: bookId,
                character_id: characterId,
                message: message
            })
        });

        const result = await response.json();

        // Remove typing indicator
        typingIndicator.remove();

        if (result.success) {
            addAIMessage(result.response);
        } else {
            addAIMessage('Sorry, I encountered an error. Please try again.\n\n' + (result.message || ''));
        }
    } catch (error) {
        console.error('Character chat failed:', error);
        typingIndicator.remove();
        addAIMessage('Sorry, I encountered an error. Please try again.');
    }
}

// Exit character chat mode
function exitCharacterChat() {
    sessionStorage.removeItem('chatCharacterId');
    const systemMessages = document.querySelectorAll('.system-message');
    systemMessages.forEach(msg => msg.remove());
}

// Update book.js sendAIMessage to handle character notifications
const originalBinderSendAIMessage = window.sendAIMessage;

// Override to add character notification handling
if (typeof window.handleCharacterNotifications === 'undefined') {
    window.handleCharacterNotifications = function(charactersCreated, charactersUpdated) {
        const hasCreated = charactersCreated && charactersCreated.length > 0;
        const hasUpdated = charactersUpdated && charactersUpdated.length > 0;

        if (hasCreated || hasUpdated) {
            showCharacterUpdateNotification(
                charactersCreated || [],
                charactersUpdated || []
            );
        }
    };
}

// Show notification when AI creates or updates characters
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
        top: 60px;
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
