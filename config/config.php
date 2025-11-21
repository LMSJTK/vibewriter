<?php
/**
 * Main Configuration File
 * VibeWriter - AI-Powered Book Writing Tool
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base paths
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '/');

// Include database configuration
require_once BASE_PATH . '/config/database.php';

// Upload directories
define('UPLOAD_DIR', BASE_PATH . '/uploads/');
define('COVERS_DIR', UPLOAD_DIR . 'covers/');
define('CHARACTERS_DIR', UPLOAD_DIR . 'characters/');
define('LOCATIONS_DIR', UPLOAD_DIR . 'locations/');
define('RESEARCH_DIR', UPLOAD_DIR . 'research/');

// Create upload directories if they don't exist
$dirs = [UPLOAD_DIR, COVERS_DIR, CHARACTERS_DIR, LOCATIONS_DIR, RESEARCH_DIR];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// AI API Configuration (placeholder - you'll need to add your API key)
// Supported providers: 'anthropic' (Claude models) or 'openai' (GPT models)
define('AI_PROVIDER', 'anthropic');
define('AI_API_KEY', ''); // Add your AI provider API key here
if (AI_PROVIDER === 'openai') {
    define('AI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
} else {
    define('AI_API_ENDPOINT', 'https://api.anthropic.com/v1/messages');
}
define('AI_MODEL', 'claude-3-5-sonnet-20241022');

// Image generation API (placeholder)
define('IMAGE_API_KEY', ''); // Add your image generation API key
define('IMAGE_API_ENDPOINT', ''); // e.g., DALL-E, Midjourney, Stable Diffusion

// Google Gemini API for image generation
define('GEMINI_API_KEY', ''); // Add your Gemini API key here

// Google OAuth / service account configuration (optional, used when API keys are not supported)
define('GOOGLE_SERVICE_ACCOUNT_EMAIL', '');
define('GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY', '');
define('GOOGLE_OAUTH_TOKEN_URI', 'https://oauth2.googleapis.com/token');
define('GOOGLE_DEFAULT_OAUTH_SCOPES', [
    'https://www.googleapis.com/auth/cloud-platform',
    'https://www.googleapis.com/auth/generative-language.retriever'
]);
define('GOOGLE_GEMINI_IMAGE_SCOPES', [
    'https://www.googleapis.com/auth/generative-language'
]);

// Google Text-to-Speech (Gemini TTS) configuration
define('GOOGLE_TTS_API_KEY', '');
define('GOOGLE_TTS_ACCESS_TOKEN', '');
define('GOOGLE_TTS_USER_PROJECT', '');
define('GOOGLE_TTS_ENDPOINT', 'https://texttospeech.googleapis.com/v1/text:synthesize');
define('GOOGLE_TTS_DEFAULT_LANGUAGE', 'en-US');
define('GOOGLE_TTS_DEFAULT_MODEL', 'gemini-2.5-flash-tts');
define('GOOGLE_TTS_DEFAULT_AUDIO_ENCODING', 'MP3');
define('GOOGLE_TTS_VOICES', [
    [
        'name' => 'Kore',
        'label' => 'Kore · Curious contemporary',
        'languageCode' => 'en-US',
        'model_name' => 'gemini-2.5-flash-tts',
        'prompt' => 'Say the following in a curious, upbeat tone that feels conversational and modern.'
    ],
    [
        'name' => 'Rhea',
        'label' => 'Rhea · Warm storyteller',
        'languageCode' => 'en-US',
        'model_name' => 'gemini-2.5-flash-tts',
        'prompt' => 'Speak like a warm narrator with gentle pacing and a hint of wonder.'
    ],
    [
        'name' => 'Nia',
        'label' => 'Nia · Dramatic performer',
        'languageCode' => 'en-GB',
        'model_name' => 'gemini-2.5-flash-tts',
        'prompt' => 'Deliver the lines with dramatic flair and crisp articulation.'
    ],
    [
        'name' => 'Piper',
        'label' => 'Piper · Youthful energy',
        'languageCode' => 'en-AU',
        'model_name' => 'gemini-2.5-flash-tts',
        'prompt' => 'Sound energetic, fresh, and a little playful.'
    ]
]);

// ElevenLabs Text-to-Speech configuration
define('ELEVENLABS_API_KEY', '');
define('ELEVENLABS_TTS_ENDPOINT', 'https://api.elevenlabs.io/v1/text-to-speech');
define('ELEVENLABS_TTS_DEFAULT_MODEL', 'eleven_multilingual_v2');
define('ELEVENLABS_TTS_DEFAULT_OUTPUT_FORMAT', 'mp3_44100_128');
define('ELEVENLABS_TTS_VOICES', [
    [
        'id' => '65dhNaIr3Y4ovumVtdy0',
        'label' => 'Vivid · expressive storyteller',
        'description' => 'Warm, energetic, with cinematic pacing.',
        'model_id' => 'eleven_multilingual_v2'
    ],
    [
        'id' => 'qxTFXDYbGcR8GaHSjczg',
        'label' => 'Noir · calm detective',
        'description' => 'Steady, grounded delivery perfect for internal monologues.',
        'model_id' => 'eleven_multilingual_v2'
    ],
    [
        'id' => 'kNie5n4lYl7TrvqBZ4iG',
        'label' => 'Spark · lively confidant',
        'description' => 'Friendly and upbeat voice for lighter scenes.',
        'model_id' => 'eleven_multilingual_v2'
    ]
]);

// Timezone
date_default_timezone_set('UTC');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to require login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

// Helper function to get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Helper function to sanitize output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit;
}

// Helper function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Helper function to verify CSRF token
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// JSON response helper
function jsonResponse($data, $statusCode = 200) {
    // Skip headers in CLI test mode
    if (!defined('CLI_TEST_MODE') || !CLI_TEST_MODE) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}
?>
