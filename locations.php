<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/locations.php';
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

$locations = getLocations($bookId);
$aiVoiceConfig = getAIChatVoiceConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locations - <?php echo h($book['title']); ?> - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/locations.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="book.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Book</a>
            <div class="book-title">
                <h1><?php echo h($book['title']); ?> - Locations</h1>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm" onclick="toggleAIChat()">💬 AI Assistant</button>
            <button class="btn btn-sm btn-primary" onclick="showNewLocationModal()">+ New Location</button>
        </div>
    </nav>

    <div class="locations-container">
        <?php if (empty($locations)): ?>
            <div class="empty-state">
                <div class="empty-icon">🗺️</div>
                <h2>No Locations Yet</h2>
                <p>Start discussing the settings in your story with the AI assistant, and location profiles will be created automatically!</p>
                <p>Or create one manually:</p>
                <button class="btn btn-primary btn-lg" onclick="showNewLocationModal()">Create First Location</button>
            </div>
        <?php else: ?>
            <div class="locations-grid">
                <?php foreach ($locations as $location): ?>
                    <div class="location-card" data-location-id="<?php echo $location['id']; ?>">
                        <div class="location-image">
                            <?php if ($location['image']): ?>
                                <img src="<?php echo h($location['image']); ?>" alt="<?php echo h($location['name']); ?>">
                            <?php else: ?>
                                <div class="location-placeholder">
                                    <span class="location-icon">📍</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="location-info">
                            <h3><?php echo h($location['name']); ?></h3>

                            <?php if ($location['atmosphere']): ?>
                                <div class="location-atmosphere">
                                    <span class="label">Atmosphere:</span>
                                    <span class="value"><?php echo h(substr($location['atmosphere'], 0, 50)) . (strlen($location['atmosphere']) > 50 ? '...' : ''); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($location['significance']): ?>
                                <div class="location-significance">
                                    <span class="label">Significance:</span>
                                    <span class="value"><?php echo h(substr($location['significance'], 0, 50)) . (strlen($location['significance']) > 50 ? '...' : ''); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($location['description']): ?>
                                <p class="location-snippet"><?php echo h(substr($location['description'], 0, 100)) . (strlen($location['description']) > 100 ? '...' : ''); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="location-actions">
                            <button class="btn btn-sm" onclick="viewLocation(<?php echo $location['id']; ?>)">View Details</button>
                        </div>
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
                    <p>I can help you develop the locations and settings in your story! Tell me about your world and I'll automatically create and update location profiles.</p>
                    <p>Try saying something like:</p>
                    <ul>
                        <li>"The story takes place in a bustling medieval marketplace"</li>
                        <li>"Tell me more about the castle"</li>
                        <li>"What if the forest had an eerie, magical quality?"</li>
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
                <textarea id="aiChatInput" placeholder="Discuss your locations and settings..." rows="3"></textarea>
                <button type="button" class="btn btn-primary" onclick="sendAIMessage()">Send</button>
            </div>
        </div>
    </aside>

    <!-- New Location Modal -->
    <div id="newLocationModal" class="modal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <h3>Create New Location</h3>
                <button class="modal-close" onclick="closeNewLocationModal()">&times;</button>
            </div>
            <form id="newLocationForm" onsubmit="createLocation(event)">
                <div class="form-group">
                    <label for="locationName">Name *</label>
                    <input type="text" id="locationName" required>
                </div>

                <div class="form-group">
                    <label for="locationDescription">Description</label>
                    <textarea id="locationDescription" rows="4" placeholder="Describe the location's physical appearance, layout, features..."></textarea>
                </div>

                <div class="form-group">
                    <label for="locationAtmosphere">Atmosphere</label>
                    <textarea id="locationAtmosphere" rows="3" placeholder="The mood, feeling, or vibe of this place..."></textarea>
                </div>

                <div class="form-group">
                    <label for="locationSignificance">Significance</label>
                    <textarea id="locationSignificance" rows="3" placeholder="Why is this location important to the story?"></textarea>
                </div>

                <div class="form-group">
                    <label for="locationNotes">Notes</label>
                    <textarea id="locationNotes" rows="3" placeholder="Any other notes about this location..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewLocationModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Location</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.aiVoiceConfig = <?php echo json_encode($aiVoiceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="assets/js/book.js"></script>
    <script src="assets/js/locations.js"></script>
</body>
</html>
