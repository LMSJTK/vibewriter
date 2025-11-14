<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/characters.php';

requireLogin();

$bookId = $_GET['id'] ?? null;
$characterId = $_GET['character'] ?? null;

if (!$bookId || !$characterId) {
    redirect('dashboard.php');
}

$user = getCurrentUser();
$book = getBook($bookId, $user['id']);

if (!$book) {
    redirect('dashboard.php');
}

$character = getCharacter($characterId, $bookId);

if (!$character) {
    redirect('characters.php?id=' . $bookId);
}

$images = getCharacterImages($characterId);
$stats = getCharacterStats($characterId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($character['name']); ?> - Characters - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/characters.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="characters.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Characters</a>
            <div class="book-title">
                <h1><?php echo h($character['name']); ?></h1>
                <span class="character-role role-<?php echo h($character['role']); ?>">
                    <?php echo ucfirst(h($character['role'])); ?>
                </span>
            </div>
        </div>
        <div class="navbar-right">
            <?php if (!empty($character['personality']) && !empty($character['speech_patterns'])): ?>
                <button class="btn btn-sm btn-chat" onclick="chatWithCharacter()">💬 Chat with <?php echo h($character['name']); ?></button>
            <?php endif; ?>
            <button class="btn btn-sm btn-danger" onclick="deleteCharacter()">🗑️ Delete</button>
        </div>
    </nav>

    <div class="character-detail">
        <div class="character-header">
            <div class="character-header-image">
                <?php if ($character['profile_image']): ?>
                    <img src="<?php echo h($character['profile_image']); ?>" alt="<?php echo h($character['name']); ?>">
                <?php else: ?>
                    <div class="character-placeholder-large">
                        <span class="character-initial-large"><?php echo strtoupper(substr($character['name'], 0, 1)); ?></span>
                    </div>
                <?php endif; ?>
                <div class="image-actions">
                    <button class="btn btn-sm" onclick="alert('Image generation coming soon!')">🎨 Generate Image</button>
                    <button class="btn btn-sm" onclick="alert('Image upload coming soon!')">📤 Upload Image</button>
                </div>
                <?php if ($character['ai_generated']): ?>
                    <div class="ai-badge-large" title="Created by AI">🤖 AI Generated</div>
                <?php endif; ?>
            </div>

            <div class="character-header-info">
                <div class="character-meta-grid">
                    <div class="meta-item">
                        <label>Age</label>
                        <input type="number" id="age" value="<?php echo h($character['age']); ?>" min="0" max="200">
                    </div>
                    <div class="meta-item">
                        <label>Gender</label>
                        <input type="text" id="gender" value="<?php echo h($character['gender']); ?>">
                    </div>
                    <div class="meta-item">
                        <label>Role</label>
                        <select id="role">
                            <option value="protagonist" <?php echo $character['role'] === 'protagonist' ? 'selected' : ''; ?>>Protagonist</option>
                            <option value="antagonist" <?php echo $character['role'] === 'antagonist' ? 'selected' : ''; ?>>Antagonist</option>
                            <option value="supporting" <?php echo $character['role'] === 'supporting' ? 'selected' : ''; ?>>Supporting</option>
                            <option value="minor" <?php echo $character['role'] === 'minor' ? 'selected' : ''; ?>>Minor</option>
                        </select>
                    </div>
                </div>

                <div class="character-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $stats['image_count']; ?></span>
                        <span class="stat-label">Images</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $stats['dialogue_count']; ?></span>
                        <span class="stat-label">Dialogues</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $stats['mention_count']; ?></span>
                        <span class="stat-label">Mentions</span>
                    </div>
                </div>

                <div class="save-indicator" id="saveIndicator">
                    <span class="saved">✓ All changes saved</span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="character-tabs">
            <button class="character-tab active" onclick="switchTab('profile')">📋 Profile</button>
            <button class="character-tab" onclick="switchTab('personality')">💭 Personality</button>
            <button class="character-tab" onclick="switchTab('background')">📖 Background</button>
            <button class="character-tab" onclick="switchTab('relationships')">👥 Relationships</button>
            <button class="character-tab" onclick="switchTab('images')">🖼️ Images (<?php echo count($images); ?>)</button>
            <button class="character-tab" onclick="switchTab('dialogue')">💬 Dialogue (<?php echo $stats['dialogue_count']; ?>)</button>
        </div>

        <!-- Tab Content -->
        <div class="tab-content active" id="tab-profile">
            <div class="character-section">
                <h4>Physical Description</h4>
                <textarea id="physical_description" rows="6" placeholder="Describe the character's appearance, clothing style, distinctive features..."><?php echo h($character['physical_description']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-personality">
            <div class="character-section">
                <h4>Personality Traits</h4>
                <textarea id="personality" rows="6" placeholder="Describe their temperament, quirks, values, fears..."><?php echo h($character['personality']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>Speech Patterns</h4>
                <textarea id="speech_patterns" rows="4" placeholder="How do they speak? Any catchphrases, dialect, or unique verbal habits..."><?php echo h($character['speech_patterns']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>Voice Description (for dialogue)</h4>
                <textarea id="voice_description" rows="4" placeholder="Tone, vocabulary level, speech rhythm, formality..."><?php echo h($character['voice_description']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>Dialogue Examples</h4>
                <textarea id="dialogue_examples" rows="6" placeholder="Example quotes that capture their voice..."><?php echo h($character['dialogue_examples']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-background">
            <div class="character-section">
                <h4>Background & History</h4>
                <textarea id="background" rows="8" placeholder="Their past, formative experiences, family history..."><?php echo h($character['background']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>Motivation & Goals</h4>
                <textarea id="motivation" rows="6" placeholder="What drives them? What do they want? Why?"><?php echo h($character['motivation']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>Character Arc</h4>
                <textarea id="arc" rows="6" placeholder="How does this character change throughout the story?"><?php echo h($character['arc']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-relationships">
            <div class="character-section">
                <h4>Relationships with Other Characters</h4>
                <textarea id="relationships" rows="10" placeholder="Describe their relationships with other characters in the story..."><?php echo h($character['relationships']); ?></textarea>
            </div>

            <div class="character-section">
                <h4>General Notes</h4>
                <textarea id="notes" rows="6" placeholder="Any other notes about this character..."><?php echo h($character['notes']); ?></textarea>
            </div>
        </div>

        <div class="tab-content" id="tab-images">
            <?php if (empty($images)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🖼️</div>
                    <h3>No Images Yet</h3>
                    <p>Generate or upload images to visualize this character.</p>
                    <button class="btn btn-primary" onclick="alert('Image generation coming soon!')">Generate Image with AI</button>
                </div>
            <?php else: ?>
                <div class="images-grid">
                    <?php foreach ($images as $image): ?>
                        <div class="image-item <?php echo $image['is_primary'] ? 'primary' : ''; ?>">
                            <img src="<?php echo h($image['file_path']); ?>" alt="<?php echo h($character['name']); ?>">
                            <?php if ($image['is_primary']): ?>
                                <div class="primary-badge">Primary</div>
                            <?php else: ?>
                                <button class="set-primary-btn" onclick="setPrimaryImage(<?php echo $image['id']; ?>)">Set as Primary</button>
                            <?php endif; ?>
                            <?php if ($image['prompt']): ?>
                                <div class="image-prompt"><?php echo h($image['prompt']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="tab-dialogue">
            <?php if ($stats['dialogue_count'] === 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <h3>No Dialogue History Yet</h3>
                    <p>Chat with <?php echo h($character['name']); ?> in character mode to practice dialogue.</p>
                    <?php if (!empty($character['personality']) && !empty($character['speech_patterns'])): ?>
                        <button class="btn btn-primary btn-lg" onclick="chatWithCharacter()">Start Chatting with <?php echo h($character['name']); ?></button>
                    <?php else: ?>
                        <p style="color: var(--text-muted); margin-top: 10px;">
                            <small>Add personality and speech patterns first to enable chat mode.</small>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div id="dialogueHistory" class="dialogue-history">
                    <!-- Will be loaded via AJAX -->
                    <p style="text-align: center; color: var(--text-muted);">Loading dialogue history...</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const bookId = <?php echo $bookId; ?>;
        const characterId = <?php echo $characterId; ?>;
        const characterName = <?php echo json_encode($character['name']); ?>;
    </script>
    <script src="assets/js/character_detail.js"></script>
</body>
</html>
