<?php
/**
 * Get AI Conversation History
 * Returns all AI chat messages for a specific book
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$bookId = $_GET['book_id'] ?? null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50; // Default to last 50 messages

if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book_id parameter'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

try {
    $pdo = getDBConnection();

    // Get conversation history ordered by most recent first
    $stmt = $pdo->prepare("
        SELECT
            id,
            message,
            response,
            context_type,
            context_id,
            created_at
        FROM ai_conversations
        WHERE book_id = ? AND user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");

    $stmt->execute([$bookId, getCurrentUserId(), $limit]);
    $conversations = $stmt->fetchAll();

    // Reverse the array so oldest messages are first
    $conversations = array_reverse($conversations);

    jsonResponse([
        'success' => true,
        'conversations' => $conversations,
        'total_count' => count($conversations)
    ]);
} catch (Exception $e) {
    error_log("Get AI Conversations Error: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Failed to retrieve conversations: ' . $e->getMessage()
    ], 500);
}
?>
