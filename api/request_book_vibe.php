<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/vibes.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$bookId = $data['book_id'] ?? null;
$milestoneLabel = $data['milestone_label'] ?? null;
$milestoneValue = $data['milestone_value'] ?? null;
$force = !empty($data['force']);

if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book_id'], 400);
}

$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

if (!$force && $milestoneValue && (int)($book['last_vibe_milestone'] ?? 0) >= (int)$milestoneValue) {
    jsonResponse([
        'success' => true,
        'message' => 'Vibe already generated for this milestone.',
        'vibe' => getLatestBookVibe($bookId)
    ]);
}

try {
    $vibe = generateBookVibe($book, [
        'label' => $milestoneLabel ?? 'New milestone',
        'value' => $milestoneValue ?? ($book['current_word_count'] ?? 0)
    ]);

    jsonResponse([
        'success' => true,
        'vibe' => $vibe
    ]);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
?>
