<?php
/**
 * Generate Character Image API Endpoint
 * Uses Google Gemini API to generate character portraits
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/characters.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['character_id']) || !isset($input['book_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$characterId = (int)$input['character_id'];
$bookId = (int)$input['book_id'];
$additionalPrompt = $input['additional_prompt'] ?? null;

// Verify book ownership
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT id FROM books WHERE id = ? AND user_id = ?");
$stmt->execute([$bookId, $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get character
$character = getCharacter($characterId, $bookId);
if (!$character) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Character not found']);
    exit;
}

// Generate image
$result = generateCharacterImage($character, $additionalPrompt);

if ($result['success']) {
    // If this is the first image, set it as primary
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM character_images WHERE character_id = ?");
    $stmt->execute([$characterId]);
    $imageCount = $stmt->fetch()['count'];

    if ($imageCount == 1) {
        setPrimaryCharacterImage($result['image_id'], $characterId);
    }

    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($result);
?>
