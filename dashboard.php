<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/books.php';

requireLogin();

$user = getCurrentUser();
$books = getUserBooks($user['id']);

// Handle book creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $result = createBook(
            $user['id'],
            $_POST['title'] ?? 'Untitled Book',
            $_POST['description'] ?? '',
            $_POST['genre'] ?? ''
        );

        if ($result['success']) {
            redirect('book.php?id=' . $result['book_id']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - VibeWriter</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <h1>VibeWriter</h1>
        </div>
        <div class="navbar-menu">
            <span>Welcome, <?php echo h($user['username']); ?></span>
            <a href="logout.php" class="btn btn-sm">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>My Books</h2>
            <button class="btn btn-primary" onclick="showNewBookModal()">+ New Book</button>
        </div>

        <?php if (empty($books)): ?>
            <div class="empty-state">
                <h3>No books yet</h3>
                <p>Create your first book to get started with VibeWriter!</p>
                <button class="btn btn-primary btn-lg" onclick="showNewBookModal()">Create Your First Book</button>
            </div>
        <?php else: ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <?php $stats = getBookStats($book['id']); ?>
                    <div class="book-card">
                        <div class="book-card-header">
                            <?php if ($book['cover_image']): ?>
                                <img src="<?php echo h($book['cover_image']); ?>" alt="Cover">
                            <?php else: ?>
                                <div class="book-cover-placeholder">
                                    <span><?php echo strtoupper(substr($book['title'], 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="book-card-body">
                            <h3><?php echo h($book['title']); ?></h3>
                            <?php if ($book['genre']): ?>
                                <span class="badge"><?php echo h($book['genre']); ?></span>
                            <?php endif; ?>
                            <?php if ($book['description']): ?>
                                <p class="book-description"><?php echo h(substr($book['description'], 0, 100)); ?><?php echo strlen($book['description']) > 100 ? '...' : ''; ?></p>
                            <?php endif; ?>

                            <div class="book-stats">
                                <div class="stat">
                                    <strong><?php echo number_format($stats['word_count']); ?></strong>
                                    <span>words</span>
                                </div>
                                <div class="stat">
                                    <strong><?php echo $stats['chapter_count']; ?></strong>
                                    <span>chapters</span>
                                </div>
                                <div class="stat">
                                    <strong><?php echo $stats['character_count']; ?></strong>
                                    <span>characters</span>
                                </div>
                            </div>

                            <div class="book-status">
                                <span class="status-badge status-<?php echo $book['status']; ?>">
                                    <?php echo ucfirst($book['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="book-card-footer">
                            <a href="book.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-block">Open Book</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- New Book Modal -->
    <div id="newBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Book</h3>
                <button class="modal-close" onclick="closeNewBookModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-group">
                    <label for="title">Book Title *</label>
                    <input type="text" id="title" name="title" required autofocus>
                </div>

                <div class="form-group">
                    <label for="genre">Genre</label>
                    <select id="genre" name="genre">
                        <option value="">Select a genre</option>
                        <option value="Fiction">Fiction</option>
                        <option value="Non-Fiction">Non-Fiction</option>
                        <option value="Fantasy">Fantasy</option>
                        <option value="Science Fiction">Science Fiction</option>
                        <option value="Mystery">Mystery</option>
                        <option value="Thriller">Thriller</option>
                        <option value="Romance">Romance</option>
                        <option value="Horror">Horror</option>
                        <option value="Literary Fiction">Literary Fiction</option>
                        <option value="Young Adult">Young Adult</option>
                        <option value="Biography">Biography</option>
                        <option value="Memoir">Memoir</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4" placeholder="What's your book about?"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewBookModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Book</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showNewBookModal() {
            document.getElementById('newBookModal').style.display = 'flex';
        }

        function closeNewBookModal() {
            document.getElementById('newBookModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('newBookModal');
            if (event.target === modal) {
                closeNewBookModal();
            }
        }
    </script>
</body>
</html>
