<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/vibes.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

$bookId = $_GET['book_id'] ?? null;
if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book_id'], 400);
}

$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

$vibe = getLatestBookVibe($bookId);
jsonResponse([
    'success' => true,
    'vibe' => $vibe
]);
?>
