<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/plot_threads.php';
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

$plotThreads = getPlotThreads($bookId);
$aiVoiceConfig = getAIChatVoiceConfig();

// Organize threads by status
$openThreads = array_filter($plotThreads, fn($t) => $t['status'] === 'open');
$resolvedThreads = array_filter($plotThreads, fn($t) => $t['status'] === 'resolved');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plot Threads - <?php echo h($book['title']); ?> - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/plot_threads.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="book.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Book</a>
            <div class="book-title">
                <h1><?php echo h($book['title']); ?> - Plot Threads</h1>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm" onclick="toggleAIChat()">💬 AI Assistant</button>
            <button class="btn btn-sm btn-primary" onclick="showNewThreadModal()">+ New Plot Thread</button>
        </div>
    </nav>

    <div class="plot-threads-container">
        <?php if (empty($plotThreads)): ?>
            <div class="empty-state">
                <div class="empty-icon">🧵</div>
                <h2>No Plot Threads Yet</h2>
                <p>Start discussing your story's plot with the AI assistant, and plot threads will be tracked automatically!</p>
                <p>Or create one manually:</p>
                <button class="btn btn-primary btn-lg" onclick="showNewThreadModal()">Create First Plot Thread</button>
            </div>
        <?php else: ?>
            <!-- Kanban Board -->
            <div class="kanban-board">
                <!-- Open Threads Column -->
                <div class="kanban-column">
                    <div class="kanban-header">
                        <h3>🔓 Open Threads</h3>
                        <span class="thread-count"><?php echo count($openThreads); ?></span>
                    </div>
                    <div class="kanban-cards">
                        <?php if (empty($openThreads)): ?>
                            <div class="empty-column">
                                <p>No open threads</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($openThreads as $thread): ?>
                                <div class="thread-card" data-thread-id="<?php echo $thread['id']; ?>" style="border-left: 4px solid <?php echo h($thread['color'] ?: '#3b82f6'); ?>">
                                    <div class="thread-header">
                                        <h4><?php echo h($thread['title']); ?></h4>
                                        <span class="thread-type type-<?php echo h($thread['thread_type']); ?>">
                                            <?php
                                            $types = [
                                                'main' => '⭐ Main',
                                                'subplot' => '🔀 Subplot',
                                                'character_arc' => '👤 Character Arc'
                                            ];
                                            echo $types[$thread['thread_type']] ?? ucfirst($thread['thread_type']);
                                            ?>
                                        </span>
                                    </div>
                                    <?php if ($thread['description']): ?>
                                        <p class="thread-description"><?php echo h(substr($thread['description'], 0, 150)) . (strlen($thread['description']) > 150 ? '...' : ''); ?></p>
                                    <?php endif; ?>
                                    <div class="thread-actions">
                                        <button class="btn btn-sm" onclick="viewThread(<?php echo $thread['id']; ?>)">View Details</button>
                                        <button class="btn btn-sm btn-success" onclick="markThreadResolved(<?php echo $thread['id']; ?>)">✓ Resolve</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resolved Threads Column -->
                <div class="kanban-column">
                    <div class="kanban-header">
                        <h3>✅ Resolved Threads</h3>
                        <span class="thread-count"><?php echo count($resolvedThreads); ?></span>
                    </div>
                    <div class="kanban-cards">
                        <?php if (empty($resolvedThreads)): ?>
                            <div class="empty-column">
                                <p>No resolved threads yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($resolvedThreads as $thread): ?>
                                <div class="thread-card resolved" data-thread-id="<?php echo $thread['id']; ?>" style="border-left: 4px solid <?php echo h($thread['color'] ?: '#10b981'); ?>">
                                    <div class="thread-header">
                                        <h4><?php echo h($thread['title']); ?></h4>
                                        <span class="thread-type type-<?php echo h($thread['thread_type']); ?>">
                                            <?php
                                            $types = [
                                                'main' => '⭐ Main',
                                                'subplot' => '🔀 Subplot',
                                                'character_arc' => '👤 Character Arc'
                                            ];
                                            echo $types[$thread['thread_type']] ?? ucfirst($thread['thread_type']);
                                            ?>
                                        </span>
                                    </div>
                                    <?php if ($thread['description']): ?>
                                        <p class="thread-description"><?php echo h(substr($thread['description'], 0, 150)) . (strlen($thread['description']) > 150 ? '...' : ''); ?></p>
                                    <?php endif; ?>
                                    <div class="thread-actions">
                                        <button class="btn btn-sm" onclick="viewThread(<?php echo $thread['id']; ?>)">View Details</button>
                                        <button class="btn btn-sm" onclick="markThreadOpen(<?php echo $thread['id']; ?>)">↶ Reopen</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
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
                    <p>I can help you track and develop your story's plot threads! Tell me about your plot and I'll automatically create and update plot threads.</p>
                    <p>Try saying something like:</p>
                    <ul>
                        <li>"The main plot is about solving a murder mystery"</li>
                        <li>"There's a subplot about the detective's relationship with their partner"</li>
                        <li>"The antagonist's redemption arc needs to be resolved"</li>
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
                <textarea id="aiChatInput" placeholder="Discuss your plot threads..." rows="3"></textarea>
                <button type="button" class="btn btn-primary" onclick="sendAIMessage()">Send</button>
            </div>
        </div>
    </aside>

    <!-- New Plot Thread Modal -->
    <div id="newThreadModal" class="modal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <h3>Create New Plot Thread</h3>
                <button class="modal-close" onclick="closeNewThreadModal()">&times;</button>
            </div>
            <form id="newThreadForm" onsubmit="createThread(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="threadTitle">Title *</label>
                        <input type="text" id="threadTitle" required>
                    </div>
                    <div class="form-group">
                        <label for="threadType">Type *</label>
                        <select id="threadType" required>
                            <option value="main">⭐ Main Plot</option>
                            <option value="subplot" selected>🔀 Subplot</option>
                            <option value="character_arc">👤 Character Arc</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="threadDescription">Description</label>
                    <textarea id="threadDescription" rows="4" placeholder="What is this plot thread about?"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="threadStatus">Status *</label>
                        <select id="threadStatus" required>
                            <option value="open" selected>🔓 Open</option>
                            <option value="resolved">✅ Resolved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="threadColor">Color</label>
                        <input type="color" id="threadColor" value="#3b82f6">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewThreadModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Plot Thread</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.aiVoiceConfig = <?php echo json_encode($aiVoiceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="assets/js/book.js"></script>
    <script src="assets/js/plot_threads.js"></script>
</body>
</html>
