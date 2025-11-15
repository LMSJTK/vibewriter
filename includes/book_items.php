<?php
/**
 * Book Items Management (Hierarchical Structure)
 * VibeWriter - AI-Powered Book Writing Tool
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get all items for a book in hierarchical structure
 */
function getBookItems($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM book_items
        WHERE book_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$bookId]);
    return $stmt->fetchAll();
}

/**
 * Get a single book item
 */
function getBookItem($itemId, $bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM book_items
        WHERE id = ? AND book_id = ?
    ");
    $stmt->execute([$itemId, $bookId]);
    return $stmt->fetch();
}

/**
 * Create a new book item (chapter, scene, note, etc.)
 */
function createBookItem($bookId, $parentId, $itemType, $title, $synopsis = '', $content = '') {
    $pdo = getDBConnection();

    // Get next position
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 as next_position
        FROM book_items
        WHERE book_id = ? AND parent_id " . ($parentId ? "= ?" : "IS NULL")
    );
    $params = $parentId ? [$bookId, $parentId] : [$bookId];
    $stmt->execute($params);
    $position = $stmt->fetch()['next_position'];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO book_items (book_id, parent_id, item_type, title, synopsis, content, position)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$bookId, $parentId, $itemType, $title, $synopsis, $content, $position]);
        return ['success' => true, 'item_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Update book item
 */
function updateBookItem($itemId, $bookId, $data) {
    $pdo = getDBConnection();

    $fields = [];
    $values = [];

    $allowedFields = ['title', 'synopsis', 'content', 'status', 'label', 'position', 'parent_id'];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    // Update word count if content is provided
    if (isset($data['content'])) {
        $wordCount = str_word_count(strip_tags($data['content']));
        $fields[] = "word_count = ?";
        $values[] = $wordCount;
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }

    $values[] = $itemId;
    $values[] = $bookId;

    $sql = "UPDATE book_items SET " . implode(', ', $fields) . " WHERE id = ? AND book_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        // Check if any rows were actually updated
        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return ['success' => false, 'message' => 'Item not found or no changes made'];
        }

        // Update book's total word count
        updateBookWordCount($bookId);

        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete book item
 */
function deleteBookItem($itemId, $bookId) {
    $pdo = getDBConnection();

    try {
        // This will cascade delete all children
        $stmt = $pdo->prepare("DELETE FROM book_items WHERE id = ? AND book_id = ?");
        $stmt->execute([$itemId, $bookId]);

        // Update book's total word count
        updateBookWordCount($bookId);

        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Reorder book items
 */
function reorderBookItems($bookId, $parentId, $items) {
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $positionedItems = array_values($items);

        foreach ($positionedItems as $position => $itemId) {
            $stmt = $pdo->prepare("
                UPDATE book_items
                SET position = ?, parent_id = ?
                WHERE id = ? AND book_id = ?
            ");
            $stmt->execute([
                $position,
                $parentId,
                $itemId,
                $bookId
            ]);
        }

        $pdo->commit();
        return ['success' => true];
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Build hierarchical tree structure from flat array
 */
function buildTree($items, $parentId = null) {
    $branch = [];

    foreach ($items as $item) {
        if ($item['parent_id'] == $parentId) {
            $children = buildTree($items, $item['id']);
            if ($children) {
                $item['children'] = $children;
            }
            $branch[] = $item;
        }
    }

    return $branch;
}

/**
 * Create a snapshot of an item (for version control)
 */
function createSnapshot($itemId, $title = null) {
    $pdo = getDBConnection();

    // Get current content
    $stmt = $pdo->prepare("SELECT content FROM book_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $content = $stmt->fetch()['content'] ?? '';

    if (!$title) {
        $title = 'Snapshot ' . date('Y-m-d H:i:s');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO snapshots (item_id, title, content)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$itemId, $title, $content]);
        return ['success' => true, 'snapshot_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get snapshots for an item
 */
function getSnapshots($itemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM snapshots
        WHERE item_id = ?
        ORDER BY snapshot_date DESC
    ");
    $stmt->execute([$itemId]);
    return $stmt->fetchAll();
}

/**
 * Update book's total word count
 */
function updateBookWordCount($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE books
        SET current_word_count = (
            SELECT COALESCE(SUM(word_count), 0)
            FROM book_items
            WHERE book_id = ? AND item_type IN ('scene', 'chapter')
        )
        WHERE id = ?
    ");
    $stmt->execute([$bookId, $bookId]);
}

/**
 * Get item metadata
 */
function getItemMetadata($itemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT meta_key, meta_value
        FROM item_metadata
        WHERE item_id = ?
    ");
    $stmt->execute([$itemId]);
    $metadata = [];
    foreach ($stmt->fetchAll() as $row) {
        $metadata[$row['meta_key']] = $row['meta_value'];
    }
    return $metadata;
}

/**
 * Set item metadata
 */
function setItemMetadata($itemId, $metaKey, $metaValue) {
    $pdo = getDBConnection();

    // Check if exists
    $stmt = $pdo->prepare("
        SELECT id FROM item_metadata
        WHERE item_id = ? AND meta_key = ?
    ");
    $stmt->execute([$itemId, $metaKey]);

    if ($stmt->fetch()) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE item_metadata
            SET meta_value = ?
            WHERE item_id = ? AND meta_key = ?
        ");
        $stmt->execute([$metaValue, $itemId, $metaKey]);
    } else {
        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO item_metadata (item_id, meta_key, meta_value)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$itemId, $metaKey, $metaValue]);
    }
}
?>
