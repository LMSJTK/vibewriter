<?php
/**
 * Set Primary Character Image API Endpoint
 * VibeWriter
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/characters.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$characterId = $data['character_id'] ?? null;
$imageId = $data['image_id'] ?? null;

if (!$characterId || !$imageId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Set primary image
$result = setPrimaryCharacterImage($imageId, $characterId);

jsonResponse($result);
?>
