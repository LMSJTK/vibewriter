<?php
/**
 * Update Plot Thread API Endpoint
 * VibeWriter
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plot_threads.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$bookId = $data['book_id'] ?? null;
$threadId = $data['thread_id'] ?? null;

if (!$bookId || !$threadId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
require_once __DIR__ . '/../includes/books.php';
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Verify plot thread belongs to book
$thread = getPlotThread($threadId, $bookId);
if (!$thread) {
    jsonResponse(['success' => false, 'message' => 'Plot thread not found'], 404);
}

// Update plot thread
$result = updatePlotThread($threadId, $bookId, $data);

jsonResponse($result);
?>
