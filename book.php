<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/book_items.php';
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

$items = getBookItems($bookId);
$tree = buildTree($items);
$aiVoiceConfig = getAIChatVoiceConfig();

// Get current selected item
$currentItemId = $_GET['item'] ?? null;
$currentItem = $currentItemId ? getBookItem($currentItemId, $bookId) : null;
$currentMetadata = $currentItem ? getItemMetadata($currentItem['id']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($book['title']); ?> - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/book.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="dashboard.php" class="back-link">← Dashboard</a>
            <div class="book-title">
                <h1><?php echo h($book['title']); ?></h1>
                <span class="word-count"><?php echo number_format($book['current_word_count']); ?> words</span>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm" onclick="toggleAIChat()">💬 AI Assistant</button>
            <button class="btn btn-sm" onclick="showCharactersPanel()">👥 Characters</button>
            <button class="btn btn-sm" onclick="showExportModal()">📤 Export</button>
        </div>
    </nav>

    <div class="book-workspace">
        <!-- Left Sidebar: Binder (Hierarchical Structure) -->
        <aside class="binder-sidebar">
            <div class="binder-header">
                <h3>Binder</h3>
                <div class="binder-actions">
                    <button class="icon-btn" onclick="expandAll()" title="Expand All">▼</button>
                    <button class="icon-btn" onclick="collapseAll()" title="Collapse All">▶</button>
                </div>
            </div>

            <div class="binder-toolbar">
                <button class="btn btn-sm btn-primary" onclick="showNewItemModal(null)">+ New Item</button>
            </div>

            <div class="binder-tree" id="binderTree">
                <?php echo renderTree($tree, $currentItemId, $bookId); ?>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="content-area">
            <div class="workspace-tabs" id="workspaceTabs" role="tablist" aria-label="Workspace views">
                <button type="button" class="workspace-tab" id="workspaceTab-editor" role="tab" aria-controls="editorPanel" aria-selected="true" data-view="editor">✍️ Editor</button>
                <button type="button" class="workspace-tab" id="workspaceTab-corkboard" role="tab" aria-controls="corkboardPanel" aria-selected="false" data-view="corkboard">🗂️ Corkboard</button>
                <button type="button" class="workspace-tab" id="workspaceTab-outliner" role="tab" aria-controls="outlinerPanel" aria-selected="false" data-view="outliner">📋 Outliner</button>
            </div>

            <section id="editorPanel" class="workspace-panel" role="tabpanel" aria-labelledby="workspaceTab-editor" data-view="editor">
                <?php if ($currentItem): ?>
                    <div class="content-header">
                        <div>
                            <h2><?php echo h($currentItem['title']); ?></h2>
                            <div class="item-meta">
                                <span class="item-type"><?php echo h($currentItem['item_type']); ?></span>
                                <span class="word-count"><?php echo number_format($currentItem['word_count']); ?> words</span>
                                <label class="sr-only" for="itemStatusSelect">Status</label>
                                <select id="itemStatusSelect" class="status-select" onchange="updateItemStatus(<?php echo $currentItem['id']; ?>, this.value)">
                                    <option value="to_do" <?php echo $currentItem['status'] === 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                    <option value="in_progress" <?php echo $currentItem['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="done" <?php echo $currentItem['status'] === 'done' ? 'selected' : ''; ?>>Done</option>
                                    <option value="revised" <?php echo $currentItem['status'] === 'revised' ? 'selected' : ''; ?>>Revised</option>
                                </select>
                            </div>
                        </div>
                        <div class="content-actions">
                            <button class="btn btn-sm" onclick="createSnapshot(<?php echo $currentItem['id']; ?>)">📸 Snapshot</button>
                            <button class="btn btn-sm" onclick="showItemMetadata(<?php echo $currentItem['id']; ?>)">⚙️ Metadata</button>
                            <button class="btn btn-sm" onclick="deleteItem(<?php echo $currentItem['id']; ?>)">🗑️ Delete</button>
                        </div>
                    </div>

                    <div class="synopsis-section">
                        <label for="synopsis">Synopsis:</label>
                        <textarea class="synopsis-input" id="synopsis" placeholder="Brief summary of this section..."><?php echo h($currentItem['synopsis']); ?></textarea>
                    </div>

                    <div class="dictation-toolbar">
                        <div class="dictation-controls">
                            <button type="button" class="btn btn-sm dictation-btn" data-target="synopsis" data-status-target="dictationStatus" data-status-idle="Voice ready">🎙️ Dictate Synopsis</button>
                            <button type="button" class="btn btn-sm dictation-btn" data-target="contentEditor" data-status-target="dictationStatus" data-status-idle="Voice ready">🎙️ Dictate Content</button>
                        </div>
                        <div class="dictation-status" id="dictationStatus" data-default-text="Voice ready">Voice ready</div>
                    </div>

                    <div class="editor-section">
                        <textarea class="content-editor" id="contentEditor" placeholder="Start writing..."><?php echo h($currentItem['content']); ?></textarea>
                    </div>

                    <div class="save-indicator" id="saveIndicator">
                        <span class="saved">✓ All changes saved</span>
                    </div>
                <?php else: ?>
                    <div class="empty-editor">
                        <h3>Welcome to <?php echo h($book['title']); ?>!</h3>
                        <p>Select an item from the Binder to start writing, or create a new chapter or scene.</p>
                        <button class="btn btn-primary btn-lg" onclick="showNewItemModal(null)">Create First Chapter</button>
                    </div>
                <?php endif; ?>
            </section>

            <section id="corkboardPanel" class="workspace-panel planning-pane" role="tabpanel" aria-labelledby="workspaceTab-corkboard" data-view="corkboard" hidden>
                <div class="planning-onboarding" id="corkboardOnboarding">
                    <h3>Visual planning</h3>
                    <p>Arrange your scenes like index cards. Drag with a mouse, tap the move handle, or use the arrow keys while focused on a card to reorder.</p>
                    <ul class="quick-tips">
                        <li>Cards surface synopsis, status, labels, and word counts.</li>
                        <li>Drop cards to reshuffle chapters or beats.</li>
                        <li>Select any card to jump into the editor for deeper edits.</li>
                    </ul>
                </div>
                <div class="planning-toolbar" aria-live="polite">
                    <span class="collection-label" id="corkboardCollectionLabel">Loading corkboard…</span>
                </div>
                <div class="planning-empty" id="corkboardEmpty" hidden>
                    <p>No cards yet. Add scenes or chapters to see them here.</p>
                    <button type="button" class="btn btn-sm btn-primary" onclick="showNewItemModal(null)">Create first card</button>
                </div>
                <div class="corkboard-grid" id="corkboardGrid" data-parent-id="" aria-live="polite" aria-label="Corkboard cards"></div>
            </section>

            <section id="outlinerPanel" class="workspace-panel planning-pane" role="tabpanel" aria-labelledby="workspaceTab-outliner" data-view="outliner" hidden>
                <div class="planning-onboarding" id="outlinerOnboarding">
                    <h3>Structured outline</h3>
                    <p>Sort and edit key metadata inline. Changes are autosaved using the same endpoints as the editor.</p>
                    <ul class="quick-tips">
                        <li>Click headers to sort columns.</li>
                        <li>Edit titles or POV fields inline and press Enter to save.</li>
                        <li>Status updates sync with the binder and corkboard.</li>
                    </ul>
                </div>
                <div class="planning-empty" id="outlinerEmpty" hidden>
                    <p>No outline rows yet. Start by creating a chapter or scene in the binder.</p>
                </div>
                <div class="outliner-table-wrapper">
                    <table class="outliner-table" aria-label="Outline table">
                        <thead>
                            <tr>
                                <th scope="col"><button type="button" class="outliner-sort" data-sort="title">Title</button></th>
                                <th scope="col"><button type="button" class="outliner-sort" data-sort="item_type">Type</button></th>
                                <th scope="col"><button type="button" class="outliner-sort" data-sort="status">Status</button></th>
                                <th scope="col"><button type="button" class="outliner-sort" data-sort="pov">POV</button></th>
                                <th scope="col"><button type="button" class="outliner-sort" data-sort="word_count">Word Count</button></th>
                            </tr>
                        </thead>
                        <tbody id="outlinerTableBody"></tbody>
                    </table>
                </div>
            </section>

            <div class="planning-status" id="planningStatus" role="status" aria-live="polite"></div>
        </main>

        <!-- Right Sidebar: AI Chat Assistant -->
        <aside class="ai-chat-sidebar" id="aiChatSidebar">
            <div class="ai-chat-header">
                <h3>AI Assistant</h3>
                <button class="close-btn" onclick="toggleAIChat()">×</button>
            </div>

            <div class="ai-chat-messages" id="aiChatMessages">
                <div class="ai-message">
                    <div class="ai-avatar">🤖</div>
                    <div class="message-content">
                        <p>Hello! I'm your AI writing assistant. I can help you with:</p>
                        <ul>
                            <li>Brainstorming plot ideas and story arcs</li>
                            <li>Developing characters and their motivations</li>
                            <li>Creating scene descriptions and dialogue</li>
                            <li>Organizing your book structure</li>
                            <li>Generating character images and visuals</li>
                        </ul>
                        <p>How can I help you with your book today?</p>
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
                    <?php if ($aiVoiceConfig['mode'] === 'google' && !empty($aiVoiceConfig['voices'])): ?>
                        <label for="aiVoiceSelect" class="sr-only">AI voice</label>
                        <select id="aiVoiceSelect" class="ai-voice-select">
                            <?php foreach ($aiVoiceConfig['voices'] as $voiceIndex => $voiceOption): ?>
                                <option value="<?php echo h($voiceOption['name']); ?>" data-language="<?php echo h($voiceOption['languageCode']); ?>" data-model="<?php echo h($voiceOption['model']); ?>" data-prompt="<?php echo h($voiceOption['prompt']); ?>" data-audio="<?php echo h($voiceOption['audioEncoding']); ?>" <?php echo $voiceIndex === 0 ? 'selected' : ''; ?>>
                                    <?php echo h($voiceOption['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <div class="ai-dictation-status" id="aiDictationStatus" data-default-text="Tap the mic to speak">Tap the mic to speak</div>
                </div>
                <div class="ai-chat-input-row">
                    <textarea id="aiChatInput" placeholder="Ask me anything about your book..." rows="3"></textarea>
                    <button type="button" class="btn btn-primary" onclick="sendAIMessage()">Send</button>
                </div>
            </div>
        </aside>
    </div>

    <!-- New Item Modal -->
    <div id="newItemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Item</h3>
                <button class="modal-close" onclick="closeNewItemModal()">&times;</button>
            </div>
            <div style="padding: 25px;">
                <input type="hidden" id="parentItemId" value="">

                <div class="form-group">
                    <label for="itemType">Type *</label>
                    <select id="itemType" required>
                        <option value="folder">Folder</option>
                        <option value="chapter">Chapter</option>
                        <option value="scene" selected>Scene</option>
                        <option value="note">Note</option>
                        <option value="research">Research</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="itemTitle">Title *</label>
                    <input type="text" id="itemTitle" required autofocus>
                </div>

                <div class="form-group">
                    <label for="itemSynopsis">Synopsis</label>
                    <textarea id="itemSynopsis" rows="3"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewItemModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createNewItem()">Create</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.aiVoiceConfig = <?php echo json_encode($aiVoiceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="assets/js/planning-utils.js"></script>
    <script src="assets/js/book.js"></script>
</body>
</html>

<?php
function renderTree($items, $currentItemId, $bookId, $parentId = null) {
    $parentAttribute = $parentId === null ? 'root' : (int) $parentId;
    $html = '<ul class="tree-list" data-parent-id="' . $parentAttribute . '">';
    foreach ($items as $item) {
        $hasChildren = !empty($item['children']);
        $isActive = $item['id'] == $currentItemId;
        $icon = getItemIcon($item['item_type']);

        $html .= '<li class="tree-item' . ($isActive ? ' active' : '') . '" data-item-id="' . (int) $item['id'] . '" data-item-type="' . h($item['item_type']) . '" data-position="' . (int) $item['position'] . '">';
        $html .= '<div class="tree-item-content" data-item-id="' . (int) $item['id'] . '">';

        if ($hasChildren) {
            $html .= '<span class="tree-toggle" onclick="toggleTreeItem(this)">▶</span>';
        } else {
            $html .= '<span class="tree-spacer"></span>';
        }

        $html .= '<span class="tree-icon">' . $icon . '</span>';
        $html .= '<a href="book.php?id=' . $bookId . '&item=' . $item['id'] . '" class="tree-label" data-item-id="' . (int) $item['id'] . '" aria-current="' . ($isActive ? 'page' : 'false') . '">';
        $html .= h($item['title']);
        $html .= '</a>';

        $html .= '<div class="tree-actions">';
        $html .= '<button class="icon-btn" onclick="showNewItemModal(' . $item['id'] . ')" title="Add child">+</button>';
        $html .= '</div>';

        $html .= '</div>';

        if ($hasChildren) {
            $html .= renderTree($item['children'], $currentItemId, $bookId, $item['id']);
        }

        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function getItemIcon($type) {
    $icons = [
        'folder' => '📁',
        'chapter' => '📖',
        'scene' => '📝',
        'note' => '📄',
        'research' => '🔍'
    ];
    return $icons[$type] ?? '📄';
}
?>
