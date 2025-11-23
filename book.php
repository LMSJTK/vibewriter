<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/book_items.php';
require_once 'includes/vibes.php';
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
$bookVibe = getLatestBookVibe($bookId);
$activePalette = $bookVibe['palette'] ?? [
    'primary' => '#5b67ff',
    'secondary' => '#0f172a',
    'accent' => '#e0f2fe'
];

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
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
</head>
<body class="book-page<?php echo $bookVibe ? ' vibe-themed' : ''; ?>">
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="dashboard.php" class="back-link">← Dashboard</a>
            <div class="book-title">
                <h1><?php echo h($book['title']); ?></h1>
                <span class="word-count book-word-count"><?php echo number_format($book['current_word_count']); ?> words</span>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm" onclick="toggleAIChat()">💬 AI Assistant</button>
            <button class="btn btn-sm" onclick="showCharactersPanel()">👥 Characters</button>
            <button class="btn btn-sm" onclick="toggleVibePanel()">🎵 Vibe</button>
            <button class="btn btn-sm" onclick="showExportModal()">📤 Export</button>
        </div>
    </nav>

    <!-- Minimal Persistent Music Controls -->
    <div class="mini-vibe-controls" id="miniVibeControls">
        <button type="button" class="mini-vibe-btn" id="miniVibePrevBtn" title="Previous" aria-label="Previous song">⏮</button>
        <button type="button" class="mini-vibe-btn mini-vibe-play" id="miniVibePlayPauseBtn" title="Play/Pause" aria-label="Play or pause">▶</button>
        <button type="button" class="mini-vibe-btn" id="miniVibeNextBtn" title="Next" aria-label="Next song">⏭</button>
        <div class="mini-vibe-track" id="miniVibeTrackTitle">No track</div>
    </div>

    <!-- Vibe Panel (Collapsible) -->
    <aside class="vibe-panel" id="vibePanel">
        <div class="vibe-panel-header">
            <h3>Book Vibe</h3>
            <button class="close-btn" onclick="toggleVibePanel()">×</button>
        </div>

        <div class="vibe-panel-content">
            <section class="vibe-summary-section" data-book-id="<?php echo (int) $book['id']; ?>" style="--vibe-primary: <?php echo h($activePalette['primary']); ?>; --vibe-secondary: <?php echo h($activePalette['secondary']); ?>; --vibe-accent: <?php echo h($activePalette['accent']); ?>;">
                <div class="vibe-eyebrow">Current Vibe</div>
                <p class="vibe-summary-text" id="vibeSummaryText"><?php echo h($bookVibe['summary'] ?? 'We\'ll generate a vibe after your next milestone.'); ?></p>

                <div class="vibe-colors-group">
                    <label>Color Palette</label>
                    <div class="vibe-colors" id="vibeColorChips">
                        <span class="vibe-chip" style="--chip-color: <?php echo h($activePalette['primary']); ?>" aria-label="Primary color"></span>
                        <span class="vibe-chip" style="--chip-color: <?php echo h($activePalette['secondary']); ?>" aria-label="Secondary color"></span>
                        <span class="vibe-chip" style="--chip-color: <?php echo h($activePalette['accent']); ?>" aria-label="Accent color"></span>
                    </div>
                </div>

                <div class="vibe-milestone">
                    <label>Milestone</label>
                    <div id="vibeMilestoneText"><?php echo h($bookVibe['milestone_label'] ?? 'Awaiting next milestone'); ?></div>
                </div>
            </section>

            <section class="vibe-player-section" aria-live="polite">
                <div class="vibe-track-info">
                    <div class="vibe-track" id="vibeTrackTitle">No track selected</div>
                    <div class="vibe-track-note" id="vibeTrackNote">Hit play to start the vibe.</div>
                </div>

                <div class="vibe-player-controls">
                    <button type="button" class="icon-btn" id="vibePrevBtn" aria-label="Previous song">⏮</button>
                    <button type="button" class="icon-btn vibe-play-large" id="vibePlayPauseBtn" aria-label="Play or pause">▶</button>
                    <button type="button" class="icon-btn" id="vibeNextBtn" aria-label="Next song">⏭</button>
                </div>

                <button type="button" class="btn btn-block" id="refreshVibeButton">Refresh Vibe</button>
            </section>
        </div>

        <iframe id="vibePlayerFrame" title="Vibe media player" allow="autoplay" hidden></iframe>
    </aside>

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
                <button type="button" class="workspace-tab" id="workspaceTab-outlineNotes" role="tab" aria-controls="outlineNotesPanel" aria-selected="false" data-view="outline-notes">📝 Outline Notes</button>
            </div>

            <section id="editorPanel" class="workspace-panel" role="tabpanel" aria-labelledby="workspaceTab-editor" data-view="editor">
                <?php if ($currentItem): ?>
                    <div class="content-header">
                        <div>
                            <h2><?php echo h($currentItem['title']); ?></h2>
                            <div class="item-meta">
                                <span class="item-type"><?php echo h($currentItem['item_type']); ?></span>
                                <span class="word-count item-word-count"><?php echo number_format($currentItem['word_count']); ?> words</span>
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
                        <div class="richtext-wrapper">
                            <div id="synopsisToolbar" class="quill-toolbar" aria-label="Synopsis formatting toolbar">
                                <span class="ql-formats">
                                    <button class="ql-bold" aria-label="Bold"></button>
                                    <button class="ql-italic" aria-label="Italic"></button>
                                    <button class="ql-underline" aria-label="Underline"></button>
                                </span>
                                <span class="ql-formats">
                                    <button class="ql-list" value="bullet" aria-label="Bulleted list"></button>
                                </span>
                            </div>
                            <div id="synopsisEditor" class="quill-editor synopsis-editor" aria-label="Synopsis editor"></div>
                            <textarea class="synopsis-input richtext-source" id="synopsis" placeholder="Brief summary of this section..." aria-label="Synopsis fallback editor"><?php echo h($currentItem['synopsis']); ?></textarea>
                        </div>
                    </div>

                        <div class="dictation-toolbar">
                            <div class="dictation-controls">
                                <button type="button" class="btn btn-sm dictation-btn" data-target="synopsis" data-status-target="dictationStatus" data-status-idle="Voice ready">🎙️ Dictate Synopsis</button>
                                <button type="button" class="btn btn-sm dictation-btn" data-target="content" data-status-target="dictationStatus" data-status-idle="Voice ready">🎙️ Dictate Content</button>
                            </div>
                            <div class="dictation-status" id="dictationStatus" data-default-text="Voice ready">Voice ready</div>
                        </div>

                    <div class="editor-section">
                        <div id="contentToolbar" class="quill-toolbar" aria-label="Content formatting toolbar">
                            <span class="ql-formats">
                                <select class="ql-header" aria-label="Heading">
                                    <option selected></option>
                                    <option value="2">Heading 1</option>
                                    <option value="3">Heading 2</option>
                                </select>
                            </span>
                            <span class="ql-formats">
                                <button class="ql-bold" aria-label="Bold"></button>
                                <button class="ql-italic" aria-label="Italic"></button>
                                <button class="ql-underline" aria-label="Underline"></button>
                                <button class="ql-strike" aria-label="Strikethrough"></button>
                            </span>
                            <span class="ql-formats">
                                <button class="ql-list" value="ordered" aria-label="Numbered list"></button>
                                <button class="ql-list" value="bullet" aria-label="Bulleted list"></button>
                                <button class="ql-blockquote" aria-label="Block quote"></button>
                                <button class="ql-code-block" aria-label="Code block"></button>
                            </span>
                            <span class="ql-formats">
                                <button class="ql-link" aria-label="Insert link"></button>
                                <button class="ql-clean" aria-label="Clear formatting"></button>
                            </span>
                        </div>
                        <div id="contentEditorRich" class="quill-editor content-editor" aria-label="Content editor"></div>
                        <textarea class="content-editor richtext-source" id="contentEditor" placeholder="Start writing..." aria-label="Content fallback editor"><?php echo h($currentItem['content']); ?></textarea>
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

            <section id="outlineNotesPanel" class="workspace-panel planning-pane outline-notes-pane" role="tabpanel" aria-labelledby="workspaceTab-outlineNotes" data-view="outline-notes" hidden>
                <div class="planning-onboarding">
                    <h3>Traditional outline</h3>
                    <p>Draft a nested outline for your story, character arcs, or beats. Use it as a scratchpad for you and your AI writing partner.</p>
                    <ul class="quick-tips">
                        <li>Use Tab / Shift+Tab (or the toolbar buttons) to indent and outdent lines.</li>
                        <li>Start lines with “-” or “•” to create bullets; mix in notes, dialogue beats, or questions.</li>
                        <li>Everything autosaves, so you can outline freely without losing momentum.</li>
                    </ul>
                </div>

                <div class="outline-notes-toolbar" aria-live="polite">
                    <div class="outline-notes-actions">
                        <button type="button" class="btn btn-sm" id="outlineAddBullet">＋ Bullet</button>
                        <button type="button" class="btn btn-sm" id="outlineIndent">↳ Indent</button>
                        <button type="button" class="btn btn-sm" id="outlineOutdent">↰ Outdent</button>
                    </div>
                    <div class="outline-notes-status" id="outlineNotesStatus" aria-live="polite">Loading outline…</div>
                </div>

                <div class="outline-notes-editor-wrap">
                    <label class="sr-only" for="outlineNotesEditor">Outline notes editor</label>
                    <div id="outlineNotesToolbar" class="quill-toolbar" aria-label="Outline formatting toolbar">
                        <span class="ql-formats">
                            <button class="ql-list" value="bullet" aria-label="Bulleted list"></button>
                            <button class="ql-list" value="ordered" aria-label="Numbered list"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-indent" value="-1" aria-label="Outdent"></button>
                            <button class="ql-indent" value="+1" aria-label="Indent"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-bold" aria-label="Bold"></button>
                            <button class="ql-italic" aria-label="Italic"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-clean" aria-label="Clear formatting"></button>
                        </span>
                    </div>
                    <div id="outlineNotesQuill" class="quill-editor outline-notes-editor" aria-label="Outline notes editor"></div>
                    <textarea id="outlineNotesEditor" class="outline-notes-editor richtext-source" placeholder="Type your outline here. Press Tab to indent nested beats or Shift+Tab to outdent." aria-label="Outline notes fallback editor"></textarea>
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
        window.initialBookVibe = <?php echo json_encode($bookVibe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        window.currentBookId = <?php echo (int) $book['id']; ?>;
    </script>
    <script src="https://cdn.quilljs.com/1.3.7/quill.js"></script>
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
