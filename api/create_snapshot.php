<?php
/**
 * Create snapshot of book item (version control)
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
$title = $data['title'] ?? null;

if (!$itemId) {
    jsonResponse(['success' => false, 'message' => 'Missing item ID'], 400);
}

// Create the snapshot
$result = createSnapshot($itemId, $title);

jsonResponse($result);
?>
