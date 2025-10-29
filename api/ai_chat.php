<?php
/**
 * AI Chat Endpoint
 * Communicates with Claude API for AI assistance
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$bookId = $data['book_id'] ?? null;
$itemId = $data['item_id'] ?? null;
$message = $data['message'] ?? '';

if (!$bookId || !$message) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify book belongs to user
$book = getBook($bookId, getCurrentUserId());
if (!$book) {
    jsonResponse(['success' => false, 'message' => 'Book not found'], 404);
}

// Check if API key is configured
if (empty(AI_API_KEY)) {
    jsonResponse([
        'success' => true,
        'response' => "I'm your AI assistant, but I need to be configured with an API key first. Please add your Claude API key to config/config.php to enable AI features.\n\nIn the meantime, I can help you understand that I'm designed to:\n- Help brainstorm plot ideas\n- Develop characters\n- Suggest scene descriptions\n- Organize your story structure\n- Generate character images\n\nPlease configure the API key to enable these features!"
    ]);
}

// Build context for AI
$context = buildAIContext($book, $itemId);

// Call Claude API
try {
    // Track if any items were created
    global $createdItems;
    $createdItems = [];

    $response = callClaudeAPI($message, $context, $bookId, $itemId);

    // Save conversation to database
    saveAIConversation($bookId, getCurrentUserId(), $message, $response);

    jsonResponse([
        'success' => true,
        'response' => $response,
        'items_created' => !empty($createdItems) ? $createdItems : null
    ]);
} catch (Exception $e) {
    // Log detailed error for debugging
    error_log("AI Chat Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    jsonResponse([
        'success' => false,
        'message' => 'AI request failed: ' . $e->getMessage(),
        'debug' => [
            'error' => $e->getMessage(),
            'api_configured' => !empty(AI_API_KEY),
            'curl_available' => function_exists('curl_init')
        ]
    ], 500);
}

/**
 * Build context for AI from book and current item
 */
function buildAIContext($book, $itemId) {
    $context = "You are an AI writing assistant helping an author with their book.\n\n";
    $context .= "Book Title: " . $book['title'] . "\n";

    if ($book['genre']) {
        $context .= "Genre: " . $book['genre'] . "\n";
    }

    if ($book['description']) {
        $context .= "Description: " . $book['description'] . "\n";
    }

    // Add current item context if available
    if ($itemId) {
        require_once __DIR__ . '/../includes/book_items.php';
        $item = getBookItem($itemId, $book['id']);
        if ($item) {
            $context .= "\nCurrent Section: " . $item['title'] . " (" . $item['item_type'] . ")\n";
            if ($item['synopsis']) {
                $context .= "Synopsis: " . $item['synopsis'] . "\n";
            }
        }
    }

    $context .= "\nYou have the ability to create items in the book's binder structure. When the user asks you to:\n";
    $context .= "- Create, add, or outline chapters, scenes, or other sections\n";
    $context .= "- Organize their book structure\n";
    $context .= "- Break down the story into parts\n";
    $context .= "\nUse the create_binder_item tool to actually create these items. Item types available:\n";
    $context .= "- 'chapter': For book chapters\n";
    $context .= "- 'scene': For individual scenes within chapters\n";
    $context .= "- 'folder': For organizing multiple items together\n";
    $context .= "- 'note': For notes and ideas\n";
    $context .= "- 'research': For research materials\n";
    $context .= "\nAfter creating items, mention what you created so the user knows the binder has been updated.\n";
    $context .= "\nProvide helpful, creative assistance for writing this book. Be encouraging and specific in your suggestions.";

    return $context;
}

/**
 * Call Claude API with tool support
 */
function callClaudeAPI($message, $context, $bookId = null, $itemId = null) {
    $apiKey = AI_API_KEY;
    $endpoint = AI_API_ENDPOINT;

    // Define tools available to the AI
    $tools = [
        [
            'name' => 'create_binder_item',
            'description' => 'Creates a new item in the book\'s binder structure (chapter, scene, note, etc.). Use this when the user asks you to create, add, or outline new sections of their book.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'The title of the item to create'
                    ],
                    'item_type' => [
                        'type' => 'string',
                        'enum' => ['folder', 'chapter', 'scene', 'note', 'research'],
                        'description' => 'The type of item: folder (for organizing), chapter, scene, note, or research'
                    ],
                    'synopsis' => [
                        'type' => 'string',
                        'description' => 'A brief synopsis or description of this item (optional)'
                    ],
                    'parent_id' => [
                        'type' => 'number',
                        'description' => 'The ID of the parent item to nest this under (optional, defaults to root level)'
                    ]
                ],
                'required' => ['title', 'item_type']
            ]
        ]
    ];

    $payload = [
        'model' => AI_MODEL,
        'max_tokens' => 2048,
        'tools' => $tools,
        'messages' => [
            [
                'role' => 'user',
                'content' => $context . "\n\nUser: " . $message
            ]
        ]
    ];

    // Make initial API call
    $result = makeClaudeAPIRequest($payload);

    // Handle tool use
    if (isset($result['stop_reason']) && $result['stop_reason'] === 'tool_use') {
        $toolResults = [];

        foreach ($result['content'] as $content) {
            if ($content['type'] === 'tool_use') {
                $toolResult = handleToolUse($content, $bookId, $itemId);
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $content['id'],
                    'content' => json_encode($toolResult)
                ];
            }
        }

        // Continue conversation with tool results
        $payload['messages'][] = [
            'role' => 'assistant',
            'content' => $result['content']
        ];
        $payload['messages'][] = [
            'role' => 'user',
            'content' => $toolResults
        ];

        // Get final response after tool use
        $result = makeClaudeAPIRequest($payload);
    }

    // Extract text response
    foreach ($result['content'] as $content) {
        if ($content['type'] === 'text') {
            return $content['text'];
        }
    }

    throw new Exception("No text response from API");
}

/**
 * Make a request to Claude API
 */
function makeClaudeAPIRequest($payload) {
    $apiKey = AI_API_KEY;
    $endpoint = AI_API_ENDPOINT;

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);

    curl_close($ch);

    if ($curlErrno) {
        throw new Exception("cURL error ($curlErrno): $curlError");
    }

    if ($httpCode !== 200) {
        $errorDetails = "API returned status code: $httpCode";
        $errorData = json_decode($response, true);
        if ($errorData && isset($errorData['error'])) {
            $errorDetails .= " - " . json_encode($errorData['error']);
        } else {
            $errorDetails .= " - Response: " . substr($response, 0, 500);
        }
        throw new Exception($errorDetails);
    }

    $result = json_decode($response, true);

    if (!$result) {
        throw new Exception("Invalid JSON response from API");
    }

    return $result;
}

/**
 * Handle tool use requests from Claude
 */
function handleToolUse($toolUse, $bookId, $itemId) {
    $toolName = $toolUse['name'];
    $input = $toolUse['input'];

    switch ($toolName) {
        case 'create_binder_item':
            return createBinderItemFromAI($input, $bookId);

        default:
            return ['success' => false, 'error' => 'Unknown tool: ' . $toolName];
    }
}

/**
 * Create a binder item from AI request
 */
function createBinderItemFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/book_items.php';

    try {
        $title = $input['title'] ?? '';
        $itemType = $input['item_type'] ?? 'scene';
        $synopsis = $input['synopsis'] ?? '';
        $parentId = $input['parent_id'] ?? null;

        if (empty($title)) {
            return ['success' => false, 'error' => 'Title is required'];
        }

        // Validate item type
        $validTypes = ['folder', 'chapter', 'scene', 'note', 'research'];
        if (!in_array($itemType, $validTypes)) {
            return ['success' => false, 'error' => 'Invalid item type'];
        }

        // Create the item
        $itemId = createBookItem($bookId, $parentId, $itemType, $title, $synopsis);

        if ($itemId) {
            // Track created items globally
            global $createdItems;
            $createdItems[] = [
                'item_id' => $itemId,
                'title' => $title,
                'type' => $itemType
            ];

            return [
                'success' => true,
                'item_id' => $itemId,
                'title' => $title,
                'type' => $itemType,
                'message' => "Created $itemType: $title"
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to create item'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Save AI conversation to database
 */
function saveAIConversation($bookId, $userId, $message, $response) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO ai_conversations (book_id, user_id, message, response)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$bookId, $userId, $message, $response]);
}
?>
