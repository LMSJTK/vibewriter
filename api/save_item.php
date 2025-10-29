<?php
/**
 * Save book item content and synopsis
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_items.php';
require_once __DIR__ . '/../includes/books.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$itemId = $data['item_id'] ?? null;
$bookId = $data['book_id'] ?? null;
$content = $data['content'] ?? '';
$synopsis = $data['synopsis'] ?? '';

if (!$itemId || !$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Update the item
$result = updateBookItem($itemId, $bookId, [
    'content' => $content,
    'synopsis' => $synopsis
]);

if ($result['success']) {
    // Get updated word count
    $item = getBookItem($itemId, $bookId);
    jsonResponse([
        'success' => true,
        'word_count' => $item['word_count']
    ]);
} else {
    jsonResponse(['success' => false, 'message' => $result['message']], 500);
}
?>
