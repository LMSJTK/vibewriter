<?php
/**
 * Debug AI Context - Shows what instructions the AI is receiving
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== AI CONTEXT DEBUG ===\n\n";

// Manually extract the buildAIContext function
$file = file_get_contents(__DIR__ . '/api/ai_chat.php');

// Find the context building section
preg_match('/\$context \.= "\\n=== CRITICAL TOOL USAGE RULES ===.*?return \$context;/s', $file, $matches);

if (empty($matches)) {
    echo "ERROR: Could not extract context from ai_chat.php\n";
    echo "Trying alternative pattern...\n\n";

    // Try to find the buildAIContext function
    preg_match('/function buildAIContext.*?^}/ms', $file, $funcMatches);

    if (!empty($funcMatches)) {
        echo "Found buildAIContext function:\n";
        echo str_repeat("=", 80) . "\n";
        echo $funcMatches[0];
        echo "\n" . str_repeat("=", 80) . "\n";
    }
} else {
    echo "Context building code:\n";
    echo str_repeat("=", 80) . "\n";
    echo $matches[0];
    echo "\n" . str_repeat("=", 80) . "\n";
}

echo "\n\nCHECKING TOOL DEFINITIONS:\n";
echo str_repeat("=", 80) . "\n";

// Extract tool definitions
preg_match('/\$tools = \[(.*?)\];/s', $file, $toolMatches);

if (!empty($toolMatches)) {
    $toolsCode = $toolMatches[1];

    // Count how many tools
    preg_match_all("/'name'\s*=>\s*'([^']+)'/", $toolsCode, $nameMatches);

    echo "Number of tools defined: " . count($nameMatches[1]) . "\n\n";

    foreach ($nameMatches[1] as $idx => $toolName) {
        echo ($idx + 1) . ". $toolName\n";

        // Find description for this tool
        $pattern = "/'name'\s*=>\s*'" . preg_quote($toolName, '/') . "'.*?'description'\s*=>\s*'([^']+)'/s";
        if (preg_match($pattern, $toolsCode, $descMatch)) {
            echo "   Description: " . substr($descMatch[1], 0, 80) . "...\n";
        }

        // Check if it has required fields
        $pattern = "/'name'\s*=>\s*'" . preg_quote($toolName, '/') . "'.*?'required'\s*=>\s*\[(.*?)\]/s";
        if (preg_match($pattern, $toolsCode, $reqMatch)) {
            $required = preg_replace('/["\'\s]/', '', $reqMatch[1]);
            echo "   Required: " . ($required ?: 'none') . "\n";
        }
        echo "\n";
    }
}

echo "\nKEY INSTRUCTIONS CHECK:\n";
echo str_repeat("=", 80) . "\n";

$criticalPhrases = [
    'CRITICAL TOOL USAGE RULES' => 'Header present',
    'MUST use the corresponding tool' => 'Mandate to use tools',
    'DO NOT just describe' => 'Anti-description warning',
    'update_binder_item' => 'Update tool mentioned',
    'WRONG RESPONSE' => 'Example of wrong behavior',
    'RIGHT RESPONSE' => 'Example of correct behavior',
    'Immediately call update_binder_item' => 'Specific update instruction'
];

foreach ($criticalPhrases as $phrase => $description) {
    $found = stripos($file, $phrase) !== false;
    $status = $found ? '✓' : '✗';
    echo "$status $description\n";
    echo "  Search term: '$phrase'\n";
    if ($found) {
        // Show context around the phrase
        $pos = stripos($file, $phrase);
        $context = substr($file, max(0, $pos - 50), 150);
        echo "  Context: " . trim($context) . "\n";
    }
    echo "\n";
}

echo "\nSUMMARY:\n";
echo str_repeat("=", 80) . "\n";
echo "✓ If all checks pass, the context is properly configured\n";
echo "✗ If checks fail, the bot won't know to use tools\n";
echo "\nNext step: Check actual API logs when you send a message\n";
echo "The debug logging added to ai_chat.php will show:\n";
echo "  - What's being sent to Claude\n";
echo "  - Claude's stop_reason (should be 'tool_use', not 'end_turn')\n";
echo "  - What tools Claude is calling (if any)\n";

?>
