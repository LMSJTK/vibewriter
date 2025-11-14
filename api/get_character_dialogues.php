<?php
/**
 * Get Character Dialogues API Endpoint
 * VibeWriter
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/characters.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$characterId = $_GET['character_id'] ?? null;

if (!$characterId) {
    jsonResponse(['success' => false, 'message' => 'Character ID required'], 400);
}

// Get dialogue history
$dialogues = getCharacterDialogues($characterId, 50);

jsonResponse([
    'success' => true,
    'dialogues' => $dialogues,
    'count' => count($dialogues)
]);
?>
