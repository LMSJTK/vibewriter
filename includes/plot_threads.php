<?php
/**
 * Plot Thread Management Functions
 * VibeWriter - AI-Powered Book Writing Tool
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get all plot threads for a book
 */
function getPlotThreads($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT *
        FROM plot_threads
        WHERE book_id = ?
        ORDER BY thread_type, title
    ");
    $stmt->execute([$bookId]);
    return $stmt->fetchAll();
}

/**
 * Get a single plot thread
 */
function getPlotThread($threadId, $bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM plot_threads
        WHERE id = ? AND book_id = ?
    ");
    $stmt->execute([$threadId, $bookId]);
    return $stmt->fetch();
}

/**
 * Create a new plot thread
 */
function createPlotThread($bookId, $data) {
    $pdo = getDBConnection();

    $fields = [
        'title', 'description', 'thread_type', 'status', 'color'
    ];

    $values = [$bookId];
    $placeholders = ['?'];
    $setFields = ['book_id'];

    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $setFields[] = $field;
            $placeholders[] = '?';
            $values[] = $data[$field];
        }
    }

    $sql = "INSERT INTO plot_threads (" . implode(', ', $setFields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return ['success' => true, 'thread_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Update a plot thread
 */
function updatePlotThread($threadId, $bookId, $data) {
    $pdo = getDBConnection();

    $fields = [];
    $values = [];

    $allowedFields = [
        'title', 'description', 'thread_type', 'status', 'color'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }

    $values[] = $threadId;
    $values[] = $bookId;

    $sql = "UPDATE plot_threads SET " . implode(', ', $fields) . " WHERE id = ? AND book_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return ['success' => false, 'message' => 'Plot thread not found or no changes made'];
        }

        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete a plot thread
 */
function deletePlotThread($threadId, $bookId) {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("DELETE FROM plot_threads WHERE id = ? AND book_id = ?");
        $stmt->execute([$threadId, $bookId]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get plot threads by type
 */
function getPlotThreadsByType($bookId, $threadType) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM plot_threads
        WHERE book_id = ? AND thread_type = ?
        ORDER BY title
    ");
    $stmt->execute([$bookId, $threadType]);
    return $stmt->fetchAll();
}

/**
 * Get open (unresolved) plot threads
 */
function getOpenPlotThreads($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM plot_threads
        WHERE book_id = ? AND status = 'open'
        ORDER BY thread_type, title
    ");
    $stmt->execute([$bookId]);
    return $stmt->fetchAll();
}
?>
