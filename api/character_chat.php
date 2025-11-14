<?php
/**
 * Character Chat API Endpoint
 * Allows users to chat WITH a character in their personality/voice
 * For developing authentic dialogue
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/characters.php';
require_once __DIR__ . '/../includes/ai_client.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$bookId = $data['book_id'] ?? null;
$characterId = $data['character_id'] ?? null;
$message = $data['message'] ?? '';
$context = $data['context'] ?? null; // Optional scene/situation context

if (!$bookId || !$characterId || !$message) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Get character
$character = getCharacter($characterId, $bookId);
if (!$character) {
    jsonResponse(['success' => false, 'message' => 'Character not found'], 404);
}

// Check if character has enough information for dialogue mode
if (empty($character['personality']) || empty($character['speech_patterns'])) {
    jsonResponse([
        'success' => false,
        'message' => 'This character needs personality and speech patterns defined before chat mode can be used. Please add these details in the character profile.'
    ], 400);
}

// Check if API key is configured
if (empty(AI_API_KEY)) {
    jsonResponse([
        'success' => false,
        'message' => 'AI API key not configured. Please add your AI provider API key to config/config.php to enable character chat.'
    ], 500);
}

// Build character personality context for AI
$characterContext = buildCharacterContext($character, $book, $context);

// Call AI provider in character mode
try {
    $response = callAIAsCharacter($message, $characterContext);

    // Save dialogue to history
    $dialogueResult = saveCharacterDialogue(
        $characterId,
        $bookId,
        getCurrentUserId(),
        $message,
        $response,
        $context
    );

    jsonResponse([
        'success' => true,
        'response' => $response,
        'character_name' => $character['name'],
        'dialogue_id' => $dialogueResult['dialogue_id'] ?? null
    ]);
} catch (Exception $e) {
    error_log("Character Chat Error: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Character chat failed: ' . $e->getMessage()
    ], 500);
}

/**
 * Build context for AI to respond as the character
 */
function buildCharacterContext($character, $book, $sceneContext = null) {
    $context = "You are roleplaying as a character from a book. Your goal is to respond authentically in character to help the author develop realistic dialogue.\n\n";

    $context .= "=== CHARACTER PROFILE ===\n";
    $context .= "Name: " . $character['name'] . "\n";
    $context .= "Role: " . ucfirst($character['role']) . "\n";

    if ($character['age']) {
        $context .= "Age: " . $character['age'] . " years old\n";
    }

    if ($character['gender']) {
        $context .= "Gender: " . $character['gender'] . "\n";
    }

    if ($character['physical_description']) {
        $context .= "\nPhysical Description:\n" . $character['physical_description'] . "\n";
    }

    if ($character['personality']) {
        $context .= "\nPersonality:\n" . $character['personality'] . "\n";
    }

    if ($character['background']) {
        $context .= "\nBackground:\n" . $character['background'] . "\n";
    }

    if ($character['motivation']) {
        $context .= "\nMotivation & Goals:\n" . $character['motivation'] . "\n";
    }

    if ($character['relationships']) {
        $context .= "\nRelationships:\n" . $character['relationships'] . "\n";
    }

    $context .= "\n=== VOICE & SPEECH ===\n";

    if ($character['speech_patterns']) {
        $context .= "Speech Patterns:\n" . $character['speech_patterns'] . "\n";
    }

    if ($character['voice_description']) {
        $context .= "\nVoice Description:\n" . $character['voice_description'] . "\n";
    }

    if ($character['dialogue_examples']) {
        $context .= "\nExample Dialogue:\n" . $character['dialogue_examples'] . "\n";
    }

    $context .= "\n=== STORY CONTEXT ===\n";
    $context .= "Book: " . $book['title'] . "\n";
    if ($book['genre']) {
        $context .= "Genre: " . $book['genre'] . "\n";
    }

    if ($sceneContext) {
        $context .= "\nCurrent Scene/Situation:\n" . $sceneContext . "\n";
    }

    $context .= "\n=== INSTRUCTIONS ===\n";
    $context .= "Respond as " . $character['name'] . " would respond in this conversation.\n";
    $context .= "Stay completely in character based on the personality, background, and speech patterns above.\n";
    $context .= "Use the voice description and example dialogue to match their speaking style.\n";
    $context .= "Keep responses natural and conversational - this is dialogue practice for the author.\n";
    $context .= "Do NOT break character or refer to yourself as an AI.\n";
    $context .= "Do NOT explain the character's thoughts unless asked - just speak as them.\n\n";

    $context .= "The author (user) is talking to you to develop authentic dialogue for their book.";

    return $context;
}

/**
 * Call the configured AI provider in character mode
 */
function callAIAsCharacter($message, $characterContext) {
    if (usingOpenAIProvider()) {
        return callOpenAIAsCharacter($message, $characterContext);
    }

    return callAnthropicAsCharacter($message, $characterContext);
}

function callAnthropicAsCharacter($message, $characterContext) {
    $payload = [
        'model' => AI_MODEL,
        'max_tokens' => 1024,
        'messages' => [
            [
                'role' => 'user',
                'content' => $characterContext . "\n\n" . $message
            ]
        ]
    ];

    error_log("=== CHARACTER CHAT REQUEST (Anthropic) ===");
    error_log("Message: " . substr($message, 0, 100));

    $result = makeAnthropicAPIRequest($payload);

    if (isset($result['content']) && is_array($result['content'])) {
        foreach ($result['content'] as $content) {
            if (($content['type'] ?? '') === 'text') {
                return $content['text'];
            }
        }
    }

    throw new Exception("No text response from API");
}

function callOpenAIAsCharacter($message, $characterContext) {
    $payload = [
        'model' => AI_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => $characterContext
            ],
            [
                'role' => 'user',
                'content' => $message
            ]
        ],
        'temperature' => 0.9,
        'max_completion_tokens' => 1024,
        'response_format' => ['type' => 'text']
    ];

    error_log("=== CHARACTER CHAT REQUEST (OpenAI) ===");
    error_log("Message: " . substr($message, 0, 100));

    $result = makeOpenAIRequest($payload);

    if (!isset($result['choices'][0]['message'])) {
        throw new Exception('Invalid response from OpenAI API');
    }

    $messageBlock = $result['choices'][0]['message'];
    $content = $messageBlock['content'] ?? '';

    if (is_array($content)) {
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') {
                $text .= $part['text'];
            }
        }
        if ($text !== '') {
            return $text;
        }
    } elseif (is_string($content) && $content !== '') {
        return $content;
    }

    throw new Exception('No text response from OpenAI API');
}
?>
