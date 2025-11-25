<?php
/**
 * Delete Location API Endpoint
 * VibeWriter
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/locations.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$bookId = $data['book_id'] ?? null;
$locationId = $data['location_id'] ?? null;

if (!$bookId || !$locationId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
require_once __DIR__ . '/../includes/books.php';
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Verify location belongs to book
$location = getLocation($locationId, $bookId);
if (!$location) {
    jsonResponse(['success' => false, 'message' => 'Location not found'], 404);
}

// Delete location
$result = deleteLocation($locationId, $bookId);

jsonResponse($result);
?>
