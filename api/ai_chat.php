<?php
/**
 * AI Chat Endpoint
 * Communicates with Claude API for AI assistance
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';

// Skip web-specific checks when running in CLI test mode
if (!defined('CLI_TEST_MODE') || !CLI_TEST_MODE) {
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
} else {
    // In CLI test mode, skip request processing
    // The test will call the functions directly
    // Exit early to just load function definitions
    goto skip_request_processing;
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
    // Track if any items were created or updated
    global $createdItems, $updatedItems;
    $createdItems = [];
    $updatedItems = [];

    $response = callClaudeAPI($message, $context, $bookId, $itemId);

    // Save conversation to database
    saveAIConversation($bookId, getCurrentUserId(), $message, $response);

    jsonResponse([
        'success' => true,
        'response' => $response,
        'items_created' => !empty($createdItems) ? $createdItems : null,
        'items_updated' => !empty($updatedItems) ? $updatedItems : null
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

skip_request_processing:

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

    $context .= "\n=== CRITICAL TOOL USAGE RULES ===\n";
    $context .= "When the user asks you to create, read, update, or delete binder items, you MUST use the corresponding tool in your response.\n";
    $context .= "DO NOT just describe what you will do - actually invoke the tool.\n";
    $context .= "NEVER say 'I will update...' or 'Now I'll create...' - just USE THE TOOL immediately.\n\n";

    $context .= "Available Tools:\n\n";

    $context .= "1. read_binder_items - See all chapters, scenes, and sections in the binder\n";
    $context .= "   Use this first to understand what already exists\n\n";

    $context .= "2. read_binder_item - Get full details of a specific item by ID\n";
    $context .= "   Required: item_id (number)\n\n";

    $context .= "3. create_binder_item - Add a new chapter, scene, note, research, or folder\n";
    $context .= "   Required: title (string), item_type (string: 'chapter', 'scene', 'folder', 'note', 'research')\n";
    $context .= "   Optional: synopsis, content, parent_id\n\n";

    $context .= "4. update_binder_item - Modify an existing item's title, content, synopsis, status, or label\n";
    $context .= "   Required: item_id (number)\n";
    $context .= "   Optional: title, synopsis, content, status, label\n";
    $context .= "   Example: To change title, call update_binder_item with {item_id: 6, title: 'New Title'}\n\n";

    $context .= "5. delete_binder_item - Remove an item and all its children\n";
    $context .= "   Required: item_id (number)\n\n";

    $context .= "=== ACTION WORKFLOW ===\n";
    $context .= "1. If user says 'update the title of X to Y' → Immediately call update_binder_item tool\n";
    $context .= "2. If user says 'create a chapter called X' → Immediately call create_binder_item tool\n";
    $context .= "3. If user says 'delete X' → Immediately call delete_binder_item tool\n";
    $context .= "4. After the tool executes, respond based on the result the tool returns\n\n";

    $context .= "WRONG RESPONSE: 'I found chapter \"Test\" with ID 6. Now I'll update its title to \"New Title\"'\n";
    $context .= "RIGHT RESPONSE: [Use update_binder_item tool immediately, then say] 'I've updated the chapter title to \"New Title\"'\n\n";

    $context .= "Provide helpful, creative assistance for writing this book. Be encouraging and specific in your suggestions.";

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
            'name' => 'read_binder_items',
            'description' => 'Reads all items in the book\'s binder structure. Use this to see what chapters, scenes, and other items already exist in the book. Returns the hierarchical structure with all items.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'parent_id' => [
                        'type' => 'number',
                        'description' => 'Optional: Filter to only show children of a specific parent item'
                    ]
                ]
            ]
        ],
        [
            'name' => 'read_binder_item',
            'description' => 'Reads detailed information about a specific binder item including its title, type, synopsis, content, and metadata. Use this to examine the details of a particular chapter, scene, or other item.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => [
                        'type' => 'number',
                        'description' => 'The ID of the item to read'
                    ]
                ],
                'required' => ['item_id']
            ]
        ],
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
                    'content' => [
                        'type' => 'string',
                        'description' => 'The initial content for this item (optional)'
                    ],
                    'parent_id' => [
                        'type' => 'number',
                        'description' => 'The ID of the parent item to nest this under (optional, defaults to root level)'
                    ]
                ],
                'required' => ['title', 'item_type']
            ]
        ],
        [
            'name' => 'update_binder_item',
            'description' => 'Updates an existing binder item. Can update title, synopsis, content, status, label, or metadata. Use this to modify, edit, or revise existing chapters, scenes, or other items.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => [
                        'type' => 'number',
                        'description' => 'The ID of the item to update'
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'New title for the item (optional)'
                    ],
                    'synopsis' => [
                        'type' => 'string',
                        'description' => 'New synopsis/description (optional)'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'New content for the item (optional)'
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'New status (e.g., "draft", "in_progress", "complete") (optional)'
                    ],
                    'label' => [
                        'type' => 'string',
                        'description' => 'New label/tag for the item (optional)'
                    ]
                ],
                'required' => ['item_id']
            ]
        ],
        [
            'name' => 'delete_binder_item',
            'description' => 'Deletes a binder item and all its children. Use this carefully when the user wants to remove a chapter, scene, or other item from their book.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => [
                        'type' => 'number',
                        'description' => 'The ID of the item to delete'
                    ]
                ],
                'required' => ['item_id']
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

    // Handle multiple rounds of tool use (agentic loop)
    // This allows the AI to call tools sequentially (e.g., search for item, then update it)
    $maxToolRounds = 5; // Prevent infinite loops
    $toolRound = 0;

    while (isset($result['stop_reason']) && $result['stop_reason'] === 'tool_use' && $toolRound < $maxToolRounds) {
        $toolRound++;

        // Log the stop reason for debugging
        error_log("Claude API stop_reason: " . $result['stop_reason'] . " (round $toolRound)");
        error_log("Claude API content types: " . json_encode(array_map(function($c) { return $c['type']; }, $result['content'])));

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

        // Fix tool_use content to ensure input fields are objects, not arrays
        // This prevents PHP from encoding empty objects as [] instead of {}
        $assistantContent = $result['content'];
        foreach ($assistantContent as &$content) {
            if ($content['type'] === 'tool_use' && isset($content['input'])) {
                // Ensure input is always an object for JSON encoding
                // Convert empty arrays to objects, and preserve associative arrays as objects
                if (is_array($content['input'])) {
                    if (empty($content['input'])) {
                        // Empty array -> empty object
                        $content['input'] = new stdClass();
                    } elseif (array_keys($content['input']) === range(0, count($content['input']) - 1)) {
                        // Numeric array - this shouldn't happen for tool inputs, but keep as-is
                        // (tool inputs should always be objects/associative arrays)
                    } else {
                        // Associative array - ensure it stays as object
                        // json_encode will handle this correctly as long as it's not empty
                    }
                }
            }
        }
        unset($content); // Break reference

        // Continue conversation with tool results
        $payload['messages'][] = [
            'role' => 'assistant',
            'content' => $assistantContent
        ];
        $payload['messages'][] = [
            'role' => 'user',
            'content' => $toolResults
        ];

        // Get next response (might use more tools or return final answer)
        $result = makeClaudeAPIRequest($payload);
    }

    // Log final stop reason
    error_log("Claude API final stop_reason: " . ($result['stop_reason'] ?? 'unknown') . " after $toolRound tool rounds");

    // Extract text response
    foreach ($result['content'] as $content) {
        if ($content['type'] === 'text') {
            return $content['text'];
        }
    }

    throw new Exception("No text response from API");
}

/**
 * Safely encode JSON for Claude API
 * Ensures that objects are properly encoded as {} not []
 */
function encodeForClaudeAPI($data) {
    // Use JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE for cleaner output
    // JSON_PRESERVE_ZERO_FRACTION ensures numbers are properly formatted
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Make a request to Claude API
 */
function makeClaudeAPIRequest($payload) {
    $apiKey = AI_API_KEY;
    $endpoint = AI_API_ENDPOINT;

    $jsonPayload = encodeForClaudeAPI($payload);

    // Log the payload for debugging (only in development)
    // Uncomment to debug: error_log("Claude API Payload: " . $jsonPayload);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
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
        // Log the failed payload for debugging
        error_log("Failed Claude API request. Payload: " . substr($jsonPayload, 0, 1000));
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

    // Log tool execution
    error_log("AI Tool Called: $toolName with input: " . json_encode($input));

    switch ($toolName) {
        case 'read_binder_items':
            return readBinderItemsFromAI($bookId, $input);

        case 'read_binder_item':
            return readBinderItemFromAI($input, $bookId);

        case 'create_binder_item':
            return createBinderItemFromAI($input, $bookId);

        case 'update_binder_item':
            return updateBinderItemFromAI($input, $bookId);

        case 'delete_binder_item':
            return deleteBinderItemFromAI($input, $bookId);

        default:
            return ['success' => false, 'error' => 'Unknown tool: ' . $toolName];
    }
}

/**
 * Read all binder items from AI request
 */
function readBinderItemsFromAI($bookId, $input) {
    require_once __DIR__ . '/../includes/book_items.php';

    try {
        $items = getBookItems($bookId);
        $parentId = $input['parent_id'] ?? null;

        // Filter by parent if specified
        if ($parentId !== null) {
            $items = array_filter($items, function($item) use ($parentId) {
                return $item['parent_id'] == $parentId;
            });
        }

        // Build hierarchical structure
        $tree = buildTree($items);

        // Format for AI consumption
        $formattedItems = formatItemsForAI($tree);

        return [
            'success' => true,
            'items' => $formattedItems,
            'total_count' => count($items),
            'message' => 'Retrieved ' . count($items) . ' items from the binder'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Format items in a readable way for the AI
 */
function formatItemsForAI($items, $indent = 0) {
    $formatted = [];
    foreach ($items as $item) {
        $info = [
            'id' => $item['id'],
            'title' => $item['title'],
            'type' => $item['item_type'],
            'synopsis' => $item['synopsis'],
            'word_count' => $item['word_count'],
            'status' => $item['status'],
            'indent_level' => $indent
        ];

        if (isset($item['children']) && !empty($item['children'])) {
            $info['children'] = formatItemsForAI($item['children'], $indent + 1);
        }

        $formatted[] = $info;
    }
    return $formatted;
}

/**
 * Read a single binder item from AI request
 */
function readBinderItemFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/book_items.php';

    try {
        $itemId = $input['item_id'] ?? null;

        if (!$itemId) {
            return ['success' => false, 'error' => 'Item ID is required'];
        }

        $item = getBookItem($itemId, $bookId);

        if (!$item) {
            return ['success' => false, 'error' => 'Item not found'];
        }

        // Get metadata
        $metadata = getItemMetadata($itemId);

        return [
            'success' => true,
            'item' => [
                'id' => $item['id'],
                'title' => $item['title'],
                'type' => $item['item_type'],
                'synopsis' => $item['synopsis'],
                'content' => $item['content'],
                'word_count' => $item['word_count'],
                'status' => $item['status'],
                'label' => $item['label'],
                'parent_id' => $item['parent_id'],
                'metadata' => $metadata
            ],
            'message' => "Retrieved item: {$item['title']}"
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
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
        $content = $input['content'] ?? '';
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
        $result = createBookItem($bookId, $parentId, $itemType, $title, $synopsis, $content);

        if ($result['success']) {
            $itemId = $result['item_id'];

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
 * Update a binder item from AI request
 */
function updateBinderItemFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/book_items.php';

    try {
        $itemId = $input['item_id'] ?? null;

        if (!$itemId) {
            return ['success' => false, 'error' => 'Item ID is required'];
        }

        // Verify item exists
        $item = getBookItem($itemId, $bookId);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found'];
        }

        // Build update data
        $updateData = [];
        $allowedFields = ['title', 'synopsis', 'content', 'status', 'label'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        // Update the item
        $result = updateBookItem($itemId, $bookId, $updateData);

        // Log the result
        error_log("Update item result: " . json_encode($result));

        if ($result['success']) {
            // Track updated items globally
            global $updatedItems;
            $updatedItems[] = [
                'item_id' => $itemId,
                'title' => $updateData['title'] ?? $item['title'],
                'updated_fields' => array_keys($updateData)
            ];

            $updatedFields = implode(', ', array_keys($updateData));
            return [
                'success' => true,
                'item_id' => $itemId,
                'updated_fields' => array_keys($updateData),
                'message' => "Updated item '{$item['title']}': $updatedFields"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to update item'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete a binder item from AI request
 */
function deleteBinderItemFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/book_items.php';

    try {
        $itemId = $input['item_id'] ?? null;

        if (!$itemId) {
            return ['success' => false, 'error' => 'Item ID is required'];
        }

        // Get item details before deletion
        $item = getBookItem($itemId, $bookId);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found'];
        }

        $itemTitle = $item['title'];

        // Delete the item
        $result = deleteBookItem($itemId, $bookId);

        if ($result['success']) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'message' => "Deleted item: $itemTitle"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to delete item'];
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
