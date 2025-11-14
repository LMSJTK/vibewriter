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
define('AI_API_KEY', ''); // Add your Claude/OpenAI API key here
define('AI_API_ENDPOINT', 'https://api.anthropic.com/v1/messages'); // For Claude
define('AI_MODEL', 'claude-3-5-sonnet-20241022');

// Image generation API (placeholder)
define('IMAGE_API_KEY', ''); // Add your image generation API key
define('IMAGE_API_ENDPOINT', ''); // e.g., DALL-E, Midjourney, Stable Diffusion

// Google Gemini API for image generation
define('GEMINI_API_KEY', ''); // Add your Gemini API key here

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
