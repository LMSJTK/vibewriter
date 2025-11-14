<?php
/**
 * AI Chat Endpoint
 * Communicates with the configured AI provider for assistance
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/ai_client.php';

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
        'response' => "I'm your AI assistant, but I need to be configured with an API key first. Please add your AI provider API key to config/config.php to enable AI features.\n\nIn the meantime, I can help you understand that I'm designed to:\n- Help brainstorm plot ideas\n- Develop characters\n- Suggest scene descriptions\n- Organize your story structure\n- Generate character images\n\nPlease configure the API key to enable these features!"
    ]);
}

// Build context for AI
$context = buildAIContext($book, $itemId);

// Call AI model
try {
    // Track if any items or characters were created or updated
    global $createdItems, $updatedItems, $createdCharacters, $updatedCharacters;
    $createdItems = [];
    $updatedItems = [];
    $createdCharacters = [];
    $updatedCharacters = [];

    $response = callAIWithTools($message, $context, $bookId, $itemId);

    // Save conversation to database
    saveAIConversation($bookId, getCurrentUserId(), $message, $response);

    jsonResponse([
        'success' => true,
        'response' => $response,
        'items_created' => !empty($createdItems) ? $createdItems : null,
        'items_updated' => !empty($updatedItems) ? $updatedItems : null,
        'characters_created' => !empty($createdCharacters) ? $createdCharacters : null,
        'characters_updated' => !empty($updatedCharacters) ? $updatedCharacters : null
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

    $context .= "CHARACTER MANAGEMENT TOOLS:\n\n";

    $context .= "6. read_characters - See all characters in the book\n";
    $context .= "   Use this to check what characters already exist\n\n";

    $context .= "7. read_character - Get full details about a specific character\n";
    $context .= "   Required: character_id (number)\n\n";

    $context .= "8. create_character - Create a new character when first mentioned\n";
    $context .= "   Required: name (string)\n";
    $context .= "   Optional: role, physical_description, personality, speech_patterns, background, motivation, age, gender\n";
    $context .= "   Example: When user mentions 'Sarah is a detective', immediately call create_character\n\n";

    $context .= "9. update_character - Add details to an existing character\n";
    $context .= "   Required: character_id (number)\n";
    $context .= "   Optional: Any character field (name, personality, physical_description, etc.)\n";
    $context .= "   Use this when user reveals new information about a character\n\n";

    $context .= "10. delete_character - Remove a character\n";
    $context .= "   Required: character_id (number)\n\n";

    $context .= "=== ACTION WORKFLOW ===\n";
    $context .= "BINDER ITEMS:\n";
    $context .= "1. If user says 'update the title of X to Y' → Immediately call update_binder_item tool\n";
    $context .= "2. If user says 'create a chapter called X' → Immediately call create_binder_item tool\n";
    $context .= "3. If user says 'delete X' → Immediately call delete_binder_item tool\n\n";

    $context .= "CHARACTERS:\n";
    $context .= "1. When user first mentions a character → Immediately call create_character with available details\n";
    $context .= "2. When user adds details about existing character → Call update_character with character_id\n";
    $context .= "3. When discussing characters, use read_characters first to see what exists\n";
    $context .= "4. Example: User says 'Sarah is a tough detective' → create_character with name='Sarah', role='protagonist', personality='tough detective'\n\n";

    $context .= "After tools execute, respond based on the result the tool returns.\n\n";

    $context .= "WRONG RESPONSE: 'I found chapter \"Test\" with ID 6. Now I'll update its title to \"New Title\"'\n";
    $context .= "RIGHT RESPONSE: [Use update_binder_item tool immediately, then say] 'I've updated the chapter title to \"New Title\"'\n\n";

    $context .= "Provide helpful, creative assistance for writing this book. Be encouraging and specific in your suggestions.";

    return $context;
}

/**
 * Call AI model with tool support
 */
function callAIWithTools($message, $context, $bookId = null, $itemId = null) {
    if (usingOpenAIProvider()) {
        return callOpenAIWithTools($message, $context, $bookId, $itemId);
    }

    return callAnthropicWithTools($message, $context, $bookId, $itemId);
}

function callAnthropicWithTools($message, $context, $bookId = null, $itemId = null) {
    $payload = [
        'model' => AI_MODEL,
        'max_tokens' => 2048,
        'tools' => getAIWritingTools(),
        'messages' => [
            [
                'role' => 'user',
                'content' => $context . "\n\nUser: " . $message
            ]
        ]
    ];

    $result = makeAnthropicAPIRequest($payload);

    $maxToolRounds = 5;
    $toolRound = 0;

    while (isset($result['stop_reason']) && $result['stop_reason'] === 'tool_use' && $toolRound < $maxToolRounds) {
        $toolRound++;

        error_log("Anthropic stop_reason: " . $result['stop_reason'] . " (round $toolRound)");
        error_log("Anthropic content types: " . json_encode(array_map(function($c) { return $c['type']; }, $result['content'])));

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

        $assistantContent = $result['content'];
        foreach ($assistantContent as &$content) {
            if ($content['type'] === 'tool_use' && isset($content['input']) && is_array($content['input'])) {
                if (empty($content['input'])) {
                    $content['input'] = new stdClass();
                } elseif (array_keys($content['input']) === range(0, count($content['input']) - 1)) {
                    // Leave numeric arrays as-is
                }
            }
        }
        unset($content);

        $payload['messages'][] = [
            'role' => 'assistant',
            'content' => $assistantContent
        ];
        $payload['messages'][] = [
            'role' => 'user',
            'content' => $toolResults
        ];

        $result = makeAnthropicAPIRequest($payload);
    }

    error_log("Anthropic final stop_reason: " . ($result['stop_reason'] ?? 'unknown') . " after $toolRound tool rounds");

    if (isset($result['content'])) {
        foreach ($result['content'] as $content) {
            if ($content['type'] === 'text') {
                return $content['text'];
            }
        }
    }

    throw new Exception("No text response from API");
}

function callOpenAIWithTools($message, $context, $bookId = null, $itemId = null) {
    $payload = [
        'model' => AI_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => $context
            ],
            [
                'role' => 'user',
                'content' => $message
            ]
        ],
        'temperature' => 0.8,
        'max_completion_tokens' => 2048,
        'tools' => convertToolsForOpenAI(getAIWritingTools()),
        'tool_choice' => 'auto',
        'response_format' => ['type' => 'text']
    ];

    $maxToolRounds = 5;
    $toolRound = 0;

    while ($toolRound < $maxToolRounds) {
        $result = makeOpenAIRequest($payload);

        if (!isset($result['choices'][0]['message'])) {
            throw new Exception('Invalid response from OpenAI API');
        }

        $choice = $result['choices'][0];
        $messageBlock = $choice['message'];

        if (!empty($messageBlock['tool_calls'])) {
            $toolRound++;

            error_log("OpenAI tool_calls round $toolRound: " . count($messageBlock['tool_calls']));

            $payload['messages'][] = $messageBlock;

            foreach ($messageBlock['tool_calls'] as $toolCall) {
                $arguments = $toolCall['function']['arguments'] ?? '{}';
                $decodedArguments = json_decode($arguments, true);
                if ($decodedArguments === null) {
                    $decodedArguments = [];
                }

                $toolInput = [
                    'name' => $toolCall['function']['name'] ?? '',
                    'input' => $decodedArguments
                ];

                $toolResult = handleToolUse($toolInput, $bookId, $itemId);

                $payload['messages'][] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? uniqid('tool_', true),
                    'content' => json_encode($toolResult)
                ];
            }

            continue;
        }

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

        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new Exception('OpenAI response was truncated (max tokens reached)');
        }

        break;
    }

    throw new Exception('No text response from OpenAI API');
}

function getAIWritingTools() {
    static $tools = null;

    if ($tools === null) {
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
            ],
            [
                'name' => 'read_characters',
                'description' => 'Reads all characters in the book. Use this to see what characters have been created and their basic information.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ],
            [
                'name' => 'read_character',
                'description' => 'Reads detailed information about a specific character including personality, appearance, background, relationships, and dialogue patterns.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'character_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the character to read'
                        ]
                    ],
                    'required' => ['character_id']
                ]
            ],
            [
                'name' => 'create_character',
                'description' => 'Creates a new character when they are first mentioned or discussed. Use this to add characters to the book\'s character database as they come up in conversation.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'The character\'s name'
                        ],
                        'role' => [
                            'type' => 'string',
                            'enum' => ['protagonist', 'antagonist', 'supporting', 'minor'],
                            'description' => 'The character\'s role in the story'
                        ],
                        'physical_description' => [
                            'type' => 'string',
                            'description' => 'Physical appearance, clothing style, distinctive features'
                        ],
                        'personality' => [
                            'type' => 'string',
                            'description' => 'Personality traits, temperament, quirks'
                        ],
                        'speech_patterns' => [
                            'type' => 'string',
                            'description' => 'How they speak: dialect, common phrases, tone'
                        ],
                        'background' => [
                            'type' => 'string',
                            'description' => 'Backstory, history, formative experiences'
                        ],
                        'motivation' => [
                            'type' => 'string',
                            'description' => 'Goals, desires, what drives them'
                        ],
                        'age' => [
                            'type' => 'number',
                            'description' => 'Age in years (optional)'
                        ],
                        'gender' => [
                            'type' => 'string',
                            'description' => 'Gender identity (optional)'
                        ]
                    ],
                    'required' => ['name']
                ]
            ],
            [
                'name' => 'update_character',
                'description' => 'Updates character information as new details are discussed or revealed. Use this to add or modify character details, personality, appearance, background, relationships, etc.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'character_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the character to update'
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Updated name (optional)'
                        ],
                        'role' => [
                            'type' => 'string',
                            'enum' => ['protagonist', 'antagonist', 'supporting', 'minor'],
                            'description' => 'Updated role (optional)'
                        ],
                        'physical_description' => [
                            'type' => 'string',
                            'description' => 'Updated physical appearance (optional)'
                        ],
                        'personality' => [
                            'type' => 'string',
                            'description' => 'Updated personality traits (optional)'
                        ],
                        'speech_patterns' => [
                            'type' => 'string',
                            'description' => 'Updated speech patterns (optional)'
                        ],
                        'voice_description' => [
                            'type' => 'string',
                            'description' => 'Description of how they speak for dialogue generation (optional)'
                        ],
                        'background' => [
                            'type' => 'string',
                            'description' => 'Updated background (optional)'
                        ],
                        'motivation' => [
                            'type' => 'string',
                            'description' => 'Updated motivation (optional)'
                        ],
                        'arc' => [
                            'type' => 'string',
                            'description' => 'Character arc and development (optional)'
                        ],
                        'relationships' => [
                            'type' => 'string',
                            'description' => 'Relationships with other characters (optional)'
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Additional notes (optional)'
                        ],
                        'age' => [
                            'type' => 'number',
                            'description' => 'Updated age (optional)'
                        ],
                        'gender' => [
                            'type' => 'string',
                            'description' => 'Updated gender (optional)'
                        ]
                    ],
                    'required' => ['character_id']
                ]
            ],
            [
                'name' => 'delete_character',
                'description' => 'Deletes a character from the book. Use this carefully when the user wants to remove a character.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'character_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the character to delete'
                        ]
                    ],
                    'required' => ['character_id']
                ]
            ]
        ];
    }

    return $tools;
}

/**
 * Handle tool use requests from the AI assistant
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

        case 'read_characters':
            return readCharactersFromAI($bookId, $input);

        case 'read_character':
            return readCharacterFromAI($input, $bookId);

        case 'create_character':
            return createCharacterFromAI($input, $bookId);

        case 'update_character':
            return updateCharacterFromAI($input, $bookId);

        case 'delete_character':
            return deleteCharacterFromAI($input, $bookId);

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

/**
 * Read all characters from AI request
 */
function readCharactersFromAI($bookId, $input) {
    require_once __DIR__ . '/../includes/characters.php';

    try {
        $characters = getCharacters($bookId);

        // Format for AI consumption
        $formattedCharacters = array_map(function($char) {
            return [
                'id' => $char['id'],
                'name' => $char['name'],
                'role' => $char['role'],
                'age' => $char['age'],
                'gender' => $char['gender'],
                'physical_description' => $char['physical_description'],
                'personality' => $char['personality'],
                'has_image' => !empty($char['primary_image'])
            ];
        }, $characters);

        return [
            'success' => true,
            'characters' => $formattedCharacters,
            'total_count' => count($characters),
            'message' => 'Retrieved ' . count($characters) . ' characters'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Read a single character from AI request
 */
function readCharacterFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/characters.php';

    try {
        $characterId = $input['character_id'] ?? null;

        if (!$characterId) {
            return ['success' => false, 'error' => 'Character ID is required'];
        }

        $character = getCharacter($characterId, $bookId);

        if (!$character) {
            return ['success' => false, 'error' => 'Character not found'];
        }

        return [
            'success' => true,
            'character' => [
                'id' => $character['id'],
                'name' => $character['name'],
                'role' => $character['role'],
                'age' => $character['age'],
                'gender' => $character['gender'],
                'physical_description' => $character['physical_description'],
                'personality' => $character['personality'],
                'speech_patterns' => $character['speech_patterns'],
                'voice_description' => $character['voice_description'],
                'background' => $character['background'],
                'motivation' => $character['motivation'],
                'arc' => $character['arc'],
                'relationships' => $character['relationships'],
                'notes' => $character['notes']
            ],
            'message' => "Retrieved character: {$character['name']}"
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a character from AI request
 */
function createCharacterFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/characters.php';

    try {
        $name = $input['name'] ?? '';

        if (empty($name)) {
            return ['success' => false, 'error' => 'Character name is required'];
        }

        // Build character data
        $characterData = [
            'name' => $name,
            'role' => $input['role'] ?? 'supporting',
            'physical_description' => $input['physical_description'] ?? '',
            'personality' => $input['personality'] ?? '',
            'speech_patterns' => $input['speech_patterns'] ?? '',
            'background' => $input['background'] ?? '',
            'motivation' => $input['motivation'] ?? '',
            'age' => $input['age'] ?? null,
            'gender' => $input['gender'] ?? '',
            'ai_generated' => true,
            'ai_metadata' => [
                'created_by_ai' => true,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];

        // Create the character
        $result = createCharacter($bookId, $characterData);

        if ($result['success']) {
            $characterId = $result['character_id'];

            // Track created characters globally
            global $createdCharacters;
            if (!isset($createdCharacters)) {
                $createdCharacters = [];
            }
            $createdCharacters[] = [
                'character_id' => $characterId,
                'name' => $name,
                'role' => $characterData['role']
            ];

            return [
                'success' => true,
                'character_id' => $characterId,
                'name' => $name,
                'message' => "Created character: $name ({$characterData['role']})"
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to create character'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update a character from AI request
 */
function updateCharacterFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/characters.php';

    try {
        $characterId = $input['character_id'] ?? null;

        if (!$characterId) {
            return ['success' => false, 'error' => 'Character ID is required'];
        }

        // Verify character exists
        $character = getCharacter($characterId, $bookId);
        if (!$character) {
            return ['success' => false, 'error' => 'Character not found'];
        }

        // Build update data
        $updateData = [];
        $allowedFields = [
            'name', 'role', 'age', 'gender', 'physical_description',
            'personality', 'speech_patterns', 'voice_description',
            'background', 'motivation', 'arc', 'relationships', 'notes'
        ];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        // Update the character
        $result = updateCharacter($characterId, $bookId, $updateData);

        if ($result['success']) {
            // Track updated characters globally
            global $updatedCharacters;
            if (!isset($updatedCharacters)) {
                $updatedCharacters = [];
            }
            $updatedCharacters[] = [
                'character_id' => $characterId,
                'name' => $updateData['name'] ?? $character['name'],
                'updated_fields' => array_keys($updateData)
            ];

            $updatedFields = implode(', ', array_keys($updateData));
            return [
                'success' => true,
                'character_id' => $characterId,
                'updated_fields' => array_keys($updateData),
                'message' => "Updated character '{$character['name']}': $updatedFields"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to update character'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete a character from AI request
 */
function deleteCharacterFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/characters.php';

    try {
        $characterId = $input['character_id'] ?? null;

        if (!$characterId) {
            return ['success' => false, 'error' => 'Character ID is required'];
        }

        // Get character details before deletion
        $character = getCharacter($characterId, $bookId);
        if (!$character) {
            return ['success' => false, 'error' => 'Character not found'];
        }

        $characterName = $character['name'];

        // Delete the character
        $result = deleteCharacter($characterId, $bookId);

        if ($result['success']) {
            return [
                'success' => true,
                'character_id' => $characterId,
                'message' => "Deleted character: $characterName"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to delete character'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
