<?php
/**
 * Save traditional outline notes for a book
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/outline_notes.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
$bookId = $payload['book_id'] ?? null;
$outline = $payload['outline'] ?? '';

if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book_id'], 400);
}

$result = saveBookOutlineNotes($bookId, getCurrentUserId(), $outline);
if (!$result['success']) {
    jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Unable to save outline'], 500);
}

jsonResponse(['success' => true]);
?>
