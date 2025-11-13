<?php
/**
 * Direct test of AI update functionality
 * This bypasses the UI to test the API directly
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/books.php';

// Set up test session
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'test_user';
    $_SESSION['email'] = 'test@example.com';
}

// CLI test mode
define('CLI_TEST_MODE', true);

// Capture and suppress initial output
ob_start();
require_once __DIR__ . '/api/ai_chat.php';
$capturedOutput = ob_get_clean();

// Now call the API directly
$bookId = 1;
$message = 'Update item 6 title to "Test Name Changed Via API"';

header('Content-Type: text/plain; charset=utf-8');
echo "=== DIRECT AI UPDATE TEST ===\n\n";

echo "Test Message: $message\n\n";

if (empty(AI_API_KEY)) {
    echo "ERROR: AI_API_KEY not configured in config/config.php\n";
    echo "Please add your Claude API key to test.\n";
    exit;
}

echo "Calling Claude API...\n";
echo str_repeat("-", 60) . "\n\n";

try {
    $book = getBook($bookId, 1);

    if (!$book) {
        echo "ERROR: Book ID $bookId not found\n";
        exit;
    }

    // Build context
    $context = buildAIContext($book, null);

    // Call Claude
    global $createdItems, $updatedItems;
    $createdItems = [];
    $updatedItems = [];

    $response = callClaudeAPI($message, $context, $bookId, null);

    echo "RESPONSE:\n";
    echo str_repeat("=", 60) . "\n";
    echo $response;
    echo "\n" . str_repeat("=", 60) . "\n\n";

    echo "TRACKING INFO:\n";
    echo "Items created: " . count($createdItems) . "\n";
    if (!empty($createdItems)) {
        print_r($createdItems);
    }

    echo "Items updated: " . count($updatedItems) . "\n";
    if (!empty($updatedItems)) {
        print_r($updatedItems);
    }

    if (empty($updatedItems)) {
        echo "\n❌ UPDATE FAILED: Bot did not call update_binder_item tool\n\n";

        echo "TROUBLESHOOTING:\n";
        echo "1. Check PHP error logs for '=== CLAUDE API RESPONSE ==='\n";
        echo "2. Look for stop_reason - should be 'tool_use', not 'end_turn'\n";
        echo "3. If stop_reason is 'end_turn', Claude chose not to use tools\n";
        echo "4. Check if response contains phrases like 'I will' or 'Now I'll'\n";
        echo "\nPossible causes:\n";
        echo "- API key for wrong Claude version (needs Claude 3+)\n";
        echo "- Model doesn't support tools (check AI_MODEL in config)\n";
        echo "- Context is too long and instructions get cut off\n";
    } else {
        echo "\n✓ SUCCESS: Bot called update_binder_item tool\n";
        echo "Check database to verify item 6 title was updated.\n";
    }

} catch (Exception $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== END TEST ===\n";
?>
