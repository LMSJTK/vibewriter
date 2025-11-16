<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tts_client.php';

if (!defined('CLI_TEST_MODE') || !CLI_TEST_MODE) {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON payload'], 400);
}

if (empty($input['text'])) {
    jsonResponse(['success' => false, 'message' => 'Text is required'], 400);
}

if (!hasGoogleTTSConfig()) {
    jsonResponse(['success' => false, 'message' => 'Google Text-to-Speech is not configured'], 400);
}

try {
    $result = synthesizeTextToSpeech($input['text'], [
        'voice' => $input['voice'] ?? ($input['voice_name'] ?? null),
        'languageCode' => $input['languageCode'] ?? null,
        'prompt' => $input['prompt'] ?? null,
        'audioEncoding' => $input['audioEncoding'] ?? null,
        'model' => $input['model'] ?? ($input['model_name'] ?? null),
        'speakingRate' => $input['speakingRate'] ?? null,
        'ssml' => $input['ssml'] ?? null
    ]);

    jsonResponse([
        'success' => true,
        'audioContent' => $result['audioContent'],
        'audioEncoding' => $result['audioEncoding'],
        'mimeType' => $result['mimeType']
    ]);
} catch (Throwable $error) {
    jsonResponse([
        'success' => false,
        'message' => $error->getMessage()
    ], 500);
}
