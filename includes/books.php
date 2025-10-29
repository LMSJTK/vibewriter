<?php
/**
 * Book Management Functions
 * VibeWriter - AI-Powered Book Writing Tool
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get all books for a user
 */
function getUserBooks($userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM books
        WHERE user_id = ?
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get a single book by ID
 */
function getBook($bookId, $userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM books
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$bookId, $userId]);
    return $stmt->fetch();
}

/**
 * Create a new book
 */
function createBook($userId, $title, $description = '', $genre = '') {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO books (user_id, title, description, genre)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $title, $description, $genre]);
        $bookId = $pdo->lastInsertId();

        // Create default folders
        createDefaultBookStructure($bookId);

        return ['success' => true, 'book_id' => $bookId];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create default book structure (Manuscript, Research, Characters, etc.)
 */
function createDefaultBookStructure($bookId) {
    $pdo = getDBConnection();

    $defaultFolders = [
        ['Manuscript', 'folder', 'Your book chapters and scenes', 0],
        ['Research', 'folder', 'Research materials and notes', 1],
        ['Characters', 'folder', 'Character profiles and sheets', 2],
        ['Locations', 'folder', 'Settings and world-building', 3],
        ['Outline', 'folder', 'Story structure and plot points', 4]
    ];

    foreach ($defaultFolders as $index => $folder) {
        $stmt = $pdo->prepare("
            INSERT INTO book_items (book_id, item_type, title, synopsis, position)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$bookId, $folder[1], $folder[0], $folder[2], $folder[3]]);
    }
}

/**
 * Update book
 */
function updateBook($bookId, $userId, $data) {
    $pdo = getDBConnection();

    $fields = [];
    $values = [];

    $allowedFields = ['title', 'description', 'genre', 'target_word_count', 'status', 'cover_image'];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }

    $values[] = $bookId;
    $values[] = $userId;

    $sql = "UPDATE books SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete book
 */
function deleteBook($bookId, $userId) {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("DELETE FROM books WHERE id = ? AND user_id = ?");
        $stmt->execute([$bookId, $userId]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get book statistics
 */
function getBookStats($bookId) {
    $pdo = getDBConnection();

    // Get word count
    $stmt = $pdo->prepare("
        SELECT SUM(word_count) as total_words
        FROM book_items
        WHERE book_id = ? AND item_type IN ('scene', 'chapter')
    ");
    $stmt->execute([$bookId]);
    $wordCount = $stmt->fetch()['total_words'] ?? 0;

    // Get chapter count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as chapter_count
        FROM book_items
        WHERE book_id = ? AND item_type = 'chapter'
    ");
    $stmt->execute([$bookId]);
    $chapterCount = $stmt->fetch()['chapter_count'] ?? 0;

    // Get scene count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as scene_count
        FROM book_items
        WHERE book_id = ? AND item_type = 'scene'
    ");
    $stmt->execute([$bookId]);
    $sceneCount = $stmt->fetch()['scene_count'] ?? 0;

    // Get character count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as character_count
        FROM characters
        WHERE book_id = ?
    ");
    $stmt->execute([$bookId]);
    $characterCount = $stmt->fetch()['character_count'] ?? 0;

    return [
        'word_count' => $wordCount,
        'chapter_count' => $chapterCount,
        'scene_count' => $sceneCount,
        'character_count' => $characterCount
    ];
}
?>
