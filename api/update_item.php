<?php
/**
 * Update book item (status, position, etc.)
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

if (!$itemId || !$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Remove item_id and book_id from data
unset($data['item_id']);
unset($data['book_id']);

$metadata = [];
if (isset($data['metadata']) && is_array($data['metadata'])) {
    $metadata = $data['metadata'];
    unset($data['metadata']);
}

// Update the item
$result = updateBookItem($itemId, $bookId, $data);

if ($result['success'] && !empty($metadata)) {
    foreach ($metadata as $key => $value) {
        setItemMetadata($itemId, $key, $value);
    }
}

jsonResponse($result);
?>
