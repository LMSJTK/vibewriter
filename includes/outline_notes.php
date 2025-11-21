<?php
/**
 * Traditional outline notes (freeform nested plans) per book.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/books.php';

/**
 * Fetch outline notes for a book the user owns.
 */
function getBookOutlineNotes($bookId, $userId) {
    $book = getBook($bookId, $userId);
    if (!$book) {
        return null;
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT outline_text FROM book_outline_notes WHERE book_id = ?");
    $stmt->execute([$bookId]);
    $row = $stmt->fetch();

    return $row ? ($row['outline_text'] ?? '') : '';
}

/**
 * Save outline notes for a book the user owns.
 */
function saveBookOutlineNotes($bookId, $userId, $outlineText) {
    $book = getBook($bookId, $userId);
    if (!$book) {
        return ['success' => false, 'message' => 'Book not found'];
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO book_outline_notes (book_id, outline_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE outline_text = VALUES(outline_text)");

    try {
        $stmt->execute([$bookId, $outlineText]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>
