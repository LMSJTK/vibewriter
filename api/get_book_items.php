<?php
/**
 * Fetch book items with planning metadata
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/book_items.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$bookId = $_GET['book_id'] ?? null;
if ($bookId !== null && !is_numeric($bookId)) {
    jsonResponse(['success' => false, 'message' => 'Invalid book ID'], 400);
}
$bookId = $bookId !== null ? (int) $bookId : null;
if (!$bookId) {
    jsonResponse(['success' => false, 'message' => 'Missing book ID'], 400);
}

$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

$items = getBookItems($bookId);
$itemIds = array_column($items, 'id');
$metadataByItem = [];

if (!empty($itemIds)) {
    $pdo = getDBConnection();
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT item_id, meta_key, meta_value FROM item_metadata WHERE item_id IN ($placeholders)"
    );
    $stmt->execute($itemIds);
    foreach ($stmt->fetchAll() as $row) {
        $itemId = (int) $row['item_id'];
        if (!isset($metadataByItem[$itemId])) {
            $metadataByItem[$itemId] = [];
        }
        $metadataByItem[$itemId][$row['meta_key']] = $row['meta_value'];
    }
}

$formattedItems = array_map(function ($item) use ($metadataByItem) {
    $itemId = (int) $item['id'];
    return [
        'id' => $itemId,
        'book_id' => (int) $item['book_id'],
        'parent_id' => $item['parent_id'] !== null ? (int) $item['parent_id'] : null,
        'item_type' => $item['item_type'],
        'title' => $item['title'],
        'synopsis' => $item['synopsis'],
        'word_count' => (int) $item['word_count'],
        'position' => (int) $item['position'],
        'status' => $item['status'],
        'label' => $item['label'],
        'metadata' => $metadataByItem[$itemId] ?? new stdClass(),
    ];
}, $items);

jsonResponse([
    'success' => true,
    'items' => $formattedItems,
]);
