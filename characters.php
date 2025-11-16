<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/characters.php';
require_once 'includes/tts_client.php';

requireLogin();

$bookId = $_GET['id'] ?? null;
if (!$bookId) {
    redirect('dashboard.php');
}

$user = getCurrentUser();
$book = getBook($bookId, $user['id']);

if (!$book) {
    redirect('dashboard.php');
}

$characters = getCharacters($bookId);
$aiVoiceConfig = getAIChatVoiceConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Characters - <?php echo h($book['title']); ?> - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/characters.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="book.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Book</a>
            <div class="book-title">
                <h1><?php echo h($book['title']); ?> - Characters</h1>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm" onclick="toggleAIChat()">💬 AI Assistant</button>
            <button class="btn btn-sm btn-primary" onclick="showNewCharacterModal()">+ New Character</button>
        </div>
    </nav>

    <div class="characters-container">
        <?php if (empty($characters)): ?>
            <div class="empty-state">
                <div class="empty-icon">👥</div>
                <h2>No Characters Yet</h2>
                <p>Start discussing your characters with the AI assistant, and character profiles will be created automatically!</p>
                <p>Or create one manually:</p>
                <button class="btn btn-primary btn-lg" onclick="showNewCharacterModal()">Create First Character</button>
            </div>
        <?php else: ?>
            <div class="characters-grid">
                <?php foreach ($characters as $character): ?>
                    <div class="character-card" data-character-id="<?php echo $character['id']; ?>">
                        <div class="character-image">
                            <?php if ($character['primary_image']): ?>
                                <img src="<?php echo h($character['primary_image']); ?>" alt="<?php echo h($character['name']); ?>">
                            <?php else: ?>
                                <div class="character-placeholder">
                                    <span class="character-initial"><?php echo strtoupper(substr($character['name'], 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($character['ai_generated']): ?>
                                <div class="ai-badge" title="Created by AI">🤖</div>
                            <?php endif; ?>
                        </div>

                        <div class="character-info">
                            <h3><?php echo h($character['name']); ?></h3>
                            <span class="character-role role-<?php echo h($character['role']); ?>">
                                <?php echo ucfirst(h($character['role'])); ?>
                            </span>

                            <?php if ($character['age'] || $character['gender']): ?>
                                <div class="character-meta">
                                    <?php if ($character['age']): ?>
                                        <span><?php echo h($character['age']); ?> years</span>
                                    <?php endif; ?>
                                    <?php if ($character['gender']): ?>
                                        <span><?php echo h($character['gender']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($character['personality']): ?>
                                <p class="character-snippet"><?php echo h(substr($character['personality'], 0, 100)) . (strlen($character['personality']) > 100 ? '...' : ''); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="character-actions">
                            <button class="btn btn-sm" onclick="viewCharacter(<?php echo $character['id']; ?>)">View Details</button>
                            <?php if (!empty($character['personality']) && !empty($character['speech_patterns'])): ?>
                                <button class="btn btn-sm btn-chat" onclick="chatWithCharacter(<?php echo $character['id']; ?>)">💬 Chat</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($character['image_count'] > 0): ?>
                            <div class="character-stats">
                                <span title="<?php echo $character['image_count']; ?> images">🖼️ <?php echo $character['image_count']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- AI Chat Sidebar -->
    <aside class="ai-chat-sidebar" id="aiChatSidebar">
        <div class="ai-chat-header">
            <h3>AI Assistant</h3>
            <button class="close-btn" onclick="toggleAIChat()">×</button>
        </div>

        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="ai-message">
                <div class="ai-avatar">🤖</div>
                <div class="message-content">
                    <p>I can help you develop your characters! Tell me about your characters and I'll automatically create and update their profiles.</p>
                    <p>Try saying something like:</p>
                    <ul>
                        <li>"Sarah is a tough detective in her 30s"</li>
                        <li>"Tell me more about Marcus"</li>
                        <li>"What if the antagonist had a troubled past?"</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="ai-chat-input-area">
            <div class="ai-chat-tools">
                <button type="button" class="btn btn-sm dictation-btn" data-target="aiChatInput" data-status-target="aiDictationStatus" data-status-idle="Tap the mic to speak">🎙️ Dictate</button>
                <button type="button" class="btn btn-sm ai-voice-toggle" id="aiVoiceToggle" aria-pressed="false" data-tts-mode="<?php echo h($aiVoiceConfig['mode']); ?>">
                    <span class="icon" aria-hidden="true">🔈</span>
                    <span class="label">Voice replies off</span>
                </button>
                <?php if (in_array($aiVoiceConfig['mode'], ['google', 'elevenlabs'], true) && !empty($aiVoiceConfig['voices'])): ?>
                    <label for="aiVoiceSelect" class="sr-only">AI voice</label>
                    <select id="aiVoiceSelect" class="ai-voice-select">
                        <?php foreach ($aiVoiceConfig['voices'] as $voiceIndex => $voiceOption): ?>
                            <option value="<?php echo h($voiceOption['id'] ?? $voiceOption['name']); ?>"
                                data-name="<?php echo h($voiceOption['name'] ?? ''); ?>"
                                data-language="<?php echo h($voiceOption['languageCode'] ?? ''); ?>"
                                data-model="<?php echo h($voiceOption['model'] ?? ''); ?>"
                                data-prompt="<?php echo h($voiceOption['prompt'] ?? ''); ?>"
                                data-audio="<?php echo h($voiceOption['audioEncoding'] ?? ''); ?>"
                                data-voice-id="<?php echo h($voiceOption['voiceId'] ?? ''); ?>"
                                data-output-format="<?php echo h($voiceOption['outputFormat'] ?? ''); ?>"
                                <?php echo $voiceIndex === 0 ? 'selected' : ''; ?>>
                                <?php echo h($voiceOption['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <div class="ai-dictation-status" id="aiDictationStatus" data-default-text="Tap the mic to speak">Tap the mic to speak</div>
            </div>
            <div class="ai-chat-input-row">
                <textarea id="aiChatInput" placeholder="Discuss your characters..." rows="3"></textarea>
                <button type="button" class="btn btn-primary" onclick="sendAIMessage()">Send</button>
            </div>
        </div>
    </aside>

    <!-- New Character Modal -->
    <div id="newCharacterModal" class="modal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <h3>Create New Character</h3>
                <button class="modal-close" onclick="closeNewCharacterModal()">&times;</button>
            </div>
            <form id="newCharacterForm" onsubmit="createCharacter(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="characterName">Name *</label>
                        <input type="text" id="characterName" required>
                    </div>
                    <div class="form-group">
                        <label for="characterRole">Role *</label>
                        <select id="characterRole" required>
                            <option value="protagonist">Protagonist</option>
                            <option value="antagonist">Antagonist</option>
                            <option value="supporting" selected>Supporting</option>
                            <option value="minor">Minor</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="characterAge">Age</label>
                        <input type="number" id="characterAge" min="0" max="200">
                    </div>
                    <div class="form-group">
                        <label for="characterGender">Gender</label>
                        <input type="text" id="characterGender">
                    </div>
                </div>

                <div class="form-group">
                    <label for="characterPhysical">Physical Description</label>
                    <textarea id="characterPhysical" rows="3" placeholder="Appearance, clothing style, distinctive features..."></textarea>
                </div>

                <div class="form-group">
                    <label for="characterPersonality">Personality</label>
                    <textarea id="characterPersonality" rows="3" placeholder="Traits, temperament, quirks..."></textarea>
                </div>

                <div class="form-group">
                    <label for="characterBackground">Background</label>
                    <textarea id="characterBackground" rows="3" placeholder="History, formative experiences..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewCharacterModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Character</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.aiVoiceConfig = <?php echo json_encode($aiVoiceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const bookId = <?php echo $bookId; ?>;
    </script>
    <script src="assets/js/book.js"></script>
    <script src="assets/js/characters.js"></script>
</body>
</html>
