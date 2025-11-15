<?php
/**
 * Reorder book items within a parent (planning views)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/book_items.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$bookId = $data['book_id'] ?? null;
$parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : null;
$itemIds = $data['item_ids'] ?? null;

if (!$bookId || !is_array($itemIds)) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

$normalizedIds = array_values(array_unique(array_map('intval', $itemIds)));
if (count($normalizedIds) !== count($itemIds)) {
    jsonResponse(['success' => false, 'message' => 'Duplicate or invalid item IDs provided'], 400);
}

$parentId = $parentId !== null && $parentId !== '' ? (int) $parentId : null;

$pdo = getDBConnection();
$placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
$stmt = $pdo->prepare(
    "SELECT id, parent_id FROM book_items WHERE book_id = ? AND id IN ($placeholders)"
);
$stmt->execute(array_merge([$bookId], $normalizedIds));
$items = $stmt->fetchAll();

if (count($items) !== count($normalizedIds)) {
    jsonResponse(['success' => false, 'message' => 'One or more items could not be found'], 404);
}

foreach ($items as $item) {
    $itemParent = $item['parent_id'] !== null ? (int) $item['parent_id'] : null;
    if ($itemParent !== $parentId) {
        jsonResponse(['success' => false, 'message' => 'Items must share the same parent when reordering'], 400);
    }
}

$result = reorderBookItems($bookId, $parentId, $normalizedIds);

if (!$result['success']) {
    jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Unable to reorder items'], 500);
}

jsonResponse(['success' => true]);
