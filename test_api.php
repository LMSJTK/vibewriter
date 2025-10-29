<?php
/**
 * API Diagnostic Tool
 * Tests Claude API connectivity and configuration
 */

require_once __DIR__ . '/config/config.php';

// Set content type
header('Content-Type: text/plain');

echo "=== VibeWriter API Diagnostic Tool ===\n\n";

// 1. Check PHP version
echo "1. PHP Version: " . phpversion() . "\n";
if (version_compare(phpversion(), '7.4.0', '<')) {
    echo "   ⚠️  WARNING: PHP 7.4 or higher is recommended\n";
} else {
    echo "   ✓ OK\n";
}
echo "\n";

// 2. Check cURL extension
echo "2. cURL Extension: ";
if (function_exists('curl_init')) {
    echo "✓ Installed\n";
    $curlVersion = curl_version();
    echo "   Version: " . $curlVersion['version'] . "\n";
    echo "   SSL Version: " . $curlVersion['ssl_version'] . "\n";
} else {
    echo "✗ NOT INSTALLED\n";
    echo "   ERROR: cURL is required for API communication\n";
}
echo "\n";

// 3. Check API key configuration
echo "3. API Key Configuration: ";
if (empty(AI_API_KEY)) {
    echo "✗ NOT CONFIGURED\n";
    echo "   ERROR: Please set AI_API_KEY in config/config.php\n";
} else {
    $keyLength = strlen(AI_API_KEY);
    $keyPreview = substr(AI_API_KEY, 0, 8) . '...' . substr(AI_API_KEY, -4);
    echo "✓ Configured\n";
    echo "   Length: $keyLength characters\n";
    echo "   Preview: $keyPreview\n";
}
echo "\n";

// 4. Check API endpoint
echo "4. API Endpoint: " . AI_API_ENDPOINT . "\n";
echo "   Model: " . AI_MODEL . "\n";
echo "\n";

// 5. Check database connection
echo "5. Database Connection: ";
try {
    $pdo = getDBConnection();
    echo "✓ Connected\n";
} catch (Exception $e) {
    echo "✗ FAILED\n";
    echo "   ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. Check ai_conversations table
echo "6. AI Conversations Table: ";
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("DESCRIBE ai_conversations");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Exists\n";
    echo "   Columns: " . implode(', ', $columns) . "\n";
} catch (Exception $e) {
    echo "✗ NOT FOUND\n";
    echo "   ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Test API call (only if API key is configured)
if (!empty(AI_API_KEY) && function_exists('curl_init')) {
    echo "7. Testing API Connection...\n";

    $payload = [
        'model' => AI_MODEL,
        'max_tokens' => 50,
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Respond with just the word "success" if you receive this message.'
            ]
        ]
    ];

    $ch = curl_init(AI_API_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . AI_API_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);

    curl_close($ch);

    if ($curlErrno) {
        echo "   ✗ cURL Error ($curlErrno): $curlError\n";
    } else {
        echo "   HTTP Status Code: $httpCode\n";

        if ($httpCode === 200) {
            echo "   ✓ API call successful!\n";
            $result = json_decode($response, true);
            if (isset($result['content'][0]['text'])) {
                echo "   Response: " . $result['content'][0]['text'] . "\n";
            } else {
                echo "   Response format: " . substr($response, 0, 200) . "...\n";
            }
        } else {
            echo "   ✗ API call failed\n";
            $errorData = json_decode($response, true);
            if ($errorData && isset($errorData['error'])) {
                echo "   Error Type: " . ($errorData['error']['type'] ?? 'unknown') . "\n";
                echo "   Error Message: " . ($errorData['error']['message'] ?? 'unknown') . "\n";
            } else {
                echo "   Raw Response: " . substr($response, 0, 500) . "\n";
            }
        }
    }
} else {
    echo "7. API Test: ⊘ Skipped (prerequisites not met)\n";
}

echo "\n=== Diagnostic Complete ===\n";
?>
