<?php
/**
 * Create new book item
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

$bookId = $data['book_id'] ?? null;
$parentId = $data['parent_id'] ?? null;
$itemType = $data['item_type'] ?? 'scene';
$title = $data['title'] ?? 'Untitled';
$synopsis = $data['synopsis'] ?? '';

if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book ID'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Create the item
$result = createBookItem($bookId, $parentId, $itemType, $title, $synopsis);

jsonResponse($result);
?>
