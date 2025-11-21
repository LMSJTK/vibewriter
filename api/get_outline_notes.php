<?php
/**
 * Get traditional outline notes for a book
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/outline_notes.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

$bookId = $_GET['book_id'] ?? null;
if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book_id'], 400);
}

$outline = getBookOutlineNotes($bookId, getCurrentUserId());
if ($outline === null) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

jsonResponse([
    'success' => true,
    'outline' => $outline,
]);
?>
