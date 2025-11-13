<?php
/**
 * Debug AI Chat - Shows detailed info about AI context and responses
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/books.php';

// Set up a test session
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

define('CLI_TEST_MODE', true);
ob_start();
require_once __DIR__ . '/api/ai_chat.php';
ob_end_clean();

header('Content-Type: text/plain; charset=utf-8');

echo "=== AI CHAT DEBUG TOOL ===\n\n";

// Get book info
$bookId = 1;
$book = getBook($bookId, 1);

if (!$book) {
    echo "ERROR: Book ID 1 not found\n";
    exit;
}

// Build context
$context = buildAIContext($book, null);

echo "1. CONTEXT LENGTH: " . strlen($context) . " characters\n\n";

echo "2. CONTEXT PREVIEW (first 500 chars):\n";
echo str_repeat("-", 60) . "\n";
echo substr($context, 0, 500) . "...\n";
echo str_repeat("-", 60) . "\n\n";

echo "3. CHECKING FOR CRITICAL INSTRUCTIONS:\n";
$keywords = [
    'CRITICAL TOOL USAGE RULES',
    'update_binder_item',
    'MUST use the corresponding tool',
    'DO NOT just describe',
    'WRONG RESPONSE',
    'RIGHT RESPONSE'
];

foreach ($keywords as $keyword) {
    $found = strpos($context, $keyword) !== false ? '✓ FOUND' : '✗ MISSING';
    echo "$found - '$keyword'\n";
}

echo "\n4. TOOL DEFINITIONS:\n";
echo str_repeat("-", 60) . "\n";

// Get the tools from callClaudeAPI function
$reflection = new ReflectionFunction('callClaudeAPI');
$filePath = $reflection->getFileName();
$startLine = $reflection->getStartLine();

// Read the function to extract tool definitions
$lines = file($filePath);
$inToolsSection = false;
$toolsLines = [];
$bracketCount = 0;

for ($i = $startLine; $i < count($lines); $i++) {
    $line = $lines[$i];

    if (strpos($line, '$tools = [') !== false) {
        $inToolsSection = true;
        $bracketCount = 1;
        continue;
    }

    if ($inToolsSection) {
        // Count brackets to find the end of array
        $bracketCount += substr_count($line, '[') - substr_count($line, ']');
        $toolsLines[] = $line;

        if ($bracketCount <= 0) {
            break;
        }
    }
}

// Parse tool names
$toolsText = implode('', $toolsLines);
preg_match_all("/'name'\s*=>\s*'([^']+)'/", $toolsText, $matches);
$toolNames = $matches[1];

echo "Defined tools: " . count($toolNames) . "\n";
foreach ($toolNames as $name) {
    echo "  - $name\n";
}

echo "\n5. API CONFIGURATION:\n";
echo str_repeat("-", 60) . "\n";
echo "Endpoint: " . AI_API_ENDPOINT . "\n";
echo "Model: " . AI_MODEL . "\n";
echo "API Key configured: " . (empty(AI_API_KEY) ? 'NO ❌' : 'YES ✓ (' . substr(AI_API_KEY, 0, 10) . '...)') . "\n";

if (empty(AI_API_KEY)) {
    echo "\n⚠️  WARNING: AI_API_KEY is not configured!\n";
    echo "Update config/config.php with your Claude API key.\n";
}

echo "\n6. TEST MESSAGE:\n";
echo str_repeat("-", 60) . "\n";
echo "To test, send this message to the AI chat:\n";
echo '"Update item 6 title to Test Updated"\n\n';
echo "Expected behavior:\n";
echo "- Bot should call update_binder_item tool immediately\n";
echo "- Response should include items_updated array\n";
echo "- No conversational response like 'I will update...'\n";

echo "\n7. CHECK PHP ERROR LOG:\n";
echo str_repeat("-", 60) . "\n";

// Try to find where PHP is logging
$logLocations = [
    '/var/log/php_errors.log',
    '/var/log/php/error.log',
    ini_get('error_log'),
    '/tmp/php_errors.log'
];

echo "Potential log locations:\n";
foreach ($logLocations as $loc) {
    if (!empty($loc) && file_exists($loc)) {
        echo "  ✓ $loc (exists, " . filesize($loc) . " bytes)\n";
        echo "    View with: tail -f $loc\n";
    } elseif (!empty($loc)) {
        echo "  ✗ $loc (not found)\n";
    }
}

echo "\nAlternatively, check your web server error log:\n";
echo "  - Apache: /var/log/apache2/error.log\n";
echo "  - Nginx: /var/log/nginx/error.log\n";
echo "  - systemd: journalctl -xe\n";

echo "\n=== END DEBUG INFO ===\n";
?>
