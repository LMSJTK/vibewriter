<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';
require_once 'includes/plot_threads.php';

requireLogin();

$bookId = $_GET['id'] ?? null;
$threadId = $_GET['thread'] ?? null;

if (!$bookId || !$threadId) {
    redirect('dashboard.php');
}

$user = getCurrentUser();
$book = getBook($bookId, $user['id']);

if (!$book) {
    redirect('dashboard.php');
}

$thread = getPlotThread($threadId, $bookId);

if (!$thread) {
    redirect('plot_threads.php?id=' . $bookId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($thread['title']); ?> - Plot Threads - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/plot_threads.css">
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar">
        <div class="navbar-left">
            <a href="plot_threads.php?id=<?php echo $bookId; ?>" class="back-link">← Back to Plot Threads</a>
            <div class="book-title">
                <h1><?php echo h($thread['title']); ?></h1>
                <span class="thread-type type-<?php echo h($thread['thread_type']); ?>">
                    <?php
                    $types = [
                        'main' => '⭐ Main Plot',
                        'subplot' => '🔀 Subplot',
                        'character_arc' => '👤 Character Arc'
                    ];
                    echo $types[$thread['thread_type']] ?? ucfirst($thread['thread_type']);
                    ?>
                </span>
            </div>
        </div>
        <div class="navbar-right">
            <button class="btn btn-sm btn-danger" onclick="deleteThread()">🗑️ Delete</button>
        </div>
    </nav>

    <div class="thread-detail">
        <div class="thread-detail-header">
            <div class="thread-meta-grid">
                <div class="meta-item">
                    <label>Type</label>
                    <select id="thread_type">
                        <option value="main" <?php echo $thread['thread_type'] === 'main' ? 'selected' : ''; ?>>⭐ Main Plot</option>
                        <option value="subplot" <?php echo $thread['thread_type'] === 'subplot' ? 'selected' : ''; ?>>🔀 Subplot</option>
                        <option value="character_arc" <?php echo $thread['thread_type'] === 'character_arc' ? 'selected' : ''; ?>>👤 Character Arc</option>
                    </select>
                </div>
                <div class="meta-item">
                    <label>Status</label>
                    <select id="status">
                        <option value="open" <?php echo $thread['status'] === 'open' ? 'selected' : ''; ?>>🔓 Open</option>
                        <option value="resolved" <?php echo $thread['status'] === 'resolved' ? 'selected' : ''; ?>>✅ Resolved</option>
                    </select>
                </div>
                <div class="meta-item">
                    <label>Color</label>
                    <input type="color" id="color" value="<?php echo h($thread['color'] ?: '#3b82f6'); ?>">
                </div>
            </div>

            <div class="save-indicator" id="saveIndicator">
                <span class="saved">✓ All changes saved</span>
            </div>
        </div>

        <!-- Description Section -->
        <div class="thread-section">
            <h4>Description</h4>
            <textarea id="description" rows="12" placeholder="Describe this plot thread in detail. What happens? Who's involved? How does it develop?"><?php echo h($thread['description']); ?></textarea>
        </div>
    </div>

    <script>
        const bookId = <?php echo (int) $bookId; ?>;
        const threadId = <?php echo (int) $threadId; ?>;
        const threadTitle = <?php echo json_encode($thread['title']); ?>;
    </script>
    <script src="assets/js/plot_thread_detail.js"></script>
</body>
</html>
