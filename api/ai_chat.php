<?php
/**
 * AI Chat Endpoint
 * Communicates with the configured AI provider for assistance
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/books.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/ai_context.php';

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
    // Track if any items, characters, locations, or plot threads were created or updated
    global $createdItems, $updatedItems, $createdCharacters, $updatedCharacters;
    global $createdLocations, $updatedLocations, $createdPlotThreads, $updatedPlotThreads;
    $createdItems = [];
    $updatedItems = [];
    $createdCharacters = [];
    $updatedCharacters = [];
    $createdLocations = [];
    $updatedLocations = [];
    $createdPlotThreads = [];
    $updatedPlotThreads = [];

    $response = callAIWithTools($message, $context, $bookId, $itemId);

    // Save conversation to database
    saveAIConversation($bookId, getCurrentUserId(), $message, $response);

    jsonResponse([
        'success' => true,
        'response' => $response,
        'items_created' => !empty($createdItems) ? $createdItems : null,
        'items_updated' => !empty($updatedItems) ? $updatedItems : null,
        'characters_created' => !empty($createdCharacters) ? $createdCharacters : null,
        'characters_updated' => !empty($updatedCharacters) ? $updatedCharacters : null,
        'locations_created' => !empty($createdLocations) ? $createdLocations : null,
        'locations_updated' => !empty($updatedLocations) ? $updatedLocations : null,
        'plot_threads_created' => !empty($createdPlotThreads) ? $createdPlotThreads : null,
        'plot_threads_updated' => !empty($updatedPlotThreads) ? $updatedPlotThreads : null
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
                            'description' => 'New status (e.g., "to_do", "in_progress", "done", "revised") (optional)'
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'New label/tag for the item (optional)'
                        ],
                        'metadata' => [
                            'type' => 'object',
                            'description' => 'Custom metadata fields like POV, Setting, Subplot, etc. Each key-value pair will be stored (optional)'
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
            ],
            [
                'name' => 'read_locations',
                'description' => 'Reads all locations/settings in the book. Use this to see what locations have been created.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ],
            [
                'name' => 'read_location',
                'description' => 'Reads detailed information about a specific location including description, atmosphere, and significance.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'location_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the location to read'
                        ]
                    ],
                    'required' => ['location_id']
                ]
            ],
            [
                'name' => 'create_location',
                'description' => 'Creates a new location/setting when first mentioned or discussed. Use this to add locations to the book\'s worldbuilding database.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'The location\'s name'
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Physical description of the location'
                        ],
                        'atmosphere' => [
                            'type' => 'string',
                            'description' => 'The mood, feeling, or atmosphere of this location'
                        ],
                        'significance' => [
                            'type' => 'string',
                            'description' => 'Why this location is important to the story'
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Additional notes about this location'
                        ]
                    ],
                    'required' => ['name']
                ]
            ],
            [
                'name' => 'update_location',
                'description' => 'Updates location information as new details are discussed or revealed. Use this to add or modify location details.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'location_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the location to update'
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Updated name (optional)'
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Updated physical description (optional)'
                        ],
                        'atmosphere' => [
                            'type' => 'string',
                            'description' => 'Updated atmosphere/mood (optional)'
                        ],
                        'significance' => [
                            'type' => 'string',
                            'description' => 'Updated significance to story (optional)'
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Updated notes (optional)'
                        ]
                    ],
                    'required' => ['location_id']
                ]
            ],
            [
                'name' => 'delete_location',
                'description' => 'Deletes a location from the book. Use this carefully when the user wants to remove a location.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'location_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the location to delete'
                        ]
                    ],
                    'required' => ['location_id']
                ]
            ],
            [
                'name' => 'read_plot_threads',
                'description' => 'Reads all plot threads (main plots, subplots, character arcs) in the book. Use this to see what storylines are being tracked.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new stdClass()
                ]
            ],
            [
                'name' => 'read_plot_thread',
                'description' => 'Reads detailed information about a specific plot thread including its description, type, and resolution status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the plot thread to read'
                        ]
                    ],
                    'required' => ['thread_id']
                ]
            ],
            [
                'name' => 'create_plot_thread',
                'description' => 'Creates a new plot thread to track a main plot, subplot, or character arc. Use this to organize story threads.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'The title of this plot thread'
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Description of what happens in this plot thread'
                        ],
                        'thread_type' => [
                            'type' => 'string',
                            'enum' => ['main', 'subplot', 'character_arc'],
                            'description' => 'The type of plot thread: main, subplot, or character_arc'
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['open', 'resolved'],
                            'description' => 'Whether this thread is still open or resolved (default: open)'
                        ],
                        'color' => [
                            'type' => 'string',
                            'description' => 'Hex color for visual distinction (e.g., #FF5733)'
                        ]
                    ],
                    'required' => ['title']
                ]
            ],
            [
                'name' => 'update_plot_thread',
                'description' => 'Updates a plot thread as the story develops. Use this to modify thread details or mark threads as resolved.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the plot thread to update'
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Updated title (optional)'
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Updated description (optional)'
                        ],
                        'thread_type' => [
                            'type' => 'string',
                            'enum' => ['main', 'subplot', 'character_arc'],
                            'description' => 'Updated type (optional)'
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['open', 'resolved'],
                            'description' => 'Updated status (optional)'
                        ],
                        'color' => [
                            'type' => 'string',
                            'description' => 'Updated color (optional)'
                        ]
                    ],
                    'required' => ['thread_id']
                ]
            ],
            [
                'name' => 'delete_plot_thread',
                'description' => 'Deletes a plot thread from the book. Use this carefully when the user wants to remove a storyline.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'thread_id' => [
                            'type' => 'number',
                            'description' => 'The ID of the plot thread to delete'
                        ]
                    ],
                    'required' => ['thread_id']
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

        case 'read_locations':
            return readLocationsFromAI($bookId, $input);

        case 'read_location':
            return readLocationFromAI($input, $bookId);

        case 'create_location':
            return createLocationFromAI($input, $bookId);

        case 'update_location':
            return updateLocationFromAI($input, $bookId);

        case 'delete_location':
            return deleteLocationFromAI($input, $bookId);

        case 'read_plot_threads':
            return readPlotThreadsFromAI($bookId, $input);

        case 'read_plot_thread':
            return readPlotThreadFromAI($input, $bookId);

        case 'create_plot_thread':
            return createPlotThreadFromAI($input, $bookId);

        case 'update_plot_thread':
            return updatePlotThreadFromAI($input, $bookId);

        case 'delete_plot_thread':
            return deletePlotThreadFromAI($input, $bookId);

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

        // Handle metadata updates
        $metadataUpdated = false;
        if (isset($input['metadata']) && is_array($input['metadata'])) {
            foreach ($input['metadata'] as $key => $value) {
                setItemMetadata($itemId, $key, $value);
                $metadataUpdated = true;
            }
        }

        if (empty($updateData) && !$metadataUpdated) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        // Update the item (if there are standard fields to update)
        if (!empty($updateData)) {
            $result = updateBookItem($itemId, $bookId, $updateData);

            // Log the result
            error_log("Update item result: " . json_encode($result));

            if (!$result['success']) {
                return ['success' => false, 'error' => $result['message'] ?? 'Failed to update item'];
            }
        }

        // Track updated items globally
        global $updatedItems;
        $updatedFields = array_keys($updateData);
        if ($metadataUpdated) {
            $updatedFields[] = 'metadata';
        }
        $updatedItems[] = [
            'item_id' => $itemId,
            'title' => $updateData['title'] ?? $item['title'],
            'updated_fields' => $updatedFields
        ];

        $updatedFieldsStr = implode(', ', $updatedFields);
        return [
            'success' => true,
            'item_id' => $itemId,
            'updated_fields' => $updatedFields,
            'message' => "Updated item '{$item['title']}': $updatedFieldsStr"
        ];
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

/**
 * Read all locations from AI request
 */
function readLocationsFromAI($bookId, $input) {
    require_once __DIR__ . '/../includes/locations.php';

    try {
        $locations = getLocations($bookId);

        // Format for AI consumption
        $formattedLocations = array_map(function($loc) {
            return [
                'id' => $loc['id'],
                'name' => $loc['name'],
                'description' => $loc['description'],
                'atmosphere' => $loc['atmosphere'],
                'significance' => $loc['significance']
            ];
        }, $locations);

        return [
            'success' => true,
            'locations' => $formattedLocations,
            'total_count' => count($locations),
            'message' => 'Retrieved ' . count($locations) . ' locations'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Read a single location from AI request
 */
function readLocationFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/locations.php';

    try {
        $locationId = $input['location_id'] ?? null;

        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID is required'];
        }

        $location = getLocation($locationId, $bookId);

        if (!$location) {
            return ['success' => false, 'error' => 'Location not found'];
        }

        return [
            'success' => true,
            'location' => [
                'id' => $location['id'],
                'name' => $location['name'],
                'description' => $location['description'],
                'atmosphere' => $location['atmosphere'],
                'significance' => $location['significance'],
                'notes' => $location['notes']
            ],
            'message' => "Retrieved location: {$location['name']}"
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a location from AI request
 */
function createLocationFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/locations.php';

    try {
        $name = $input['name'] ?? '';

        if (empty($name)) {
            return ['success' => false, 'error' => 'Location name is required'];
        }

        // Build location data
        $locationData = [
            'name' => $name,
            'description' => $input['description'] ?? '',
            'atmosphere' => $input['atmosphere'] ?? '',
            'significance' => $input['significance'] ?? '',
            'notes' => $input['notes'] ?? ''
        ];

        // Create the location
        $result = createLocation($bookId, $locationData);

        if ($result['success']) {
            $locationId = $result['location_id'];

            // Track created locations globally
            global $createdLocations;
            if (!isset($createdLocations)) {
                $createdLocations = [];
            }
            $createdLocations[] = [
                'location_id' => $locationId,
                'name' => $name
            ];

            return [
                'success' => true,
                'location_id' => $locationId,
                'name' => $name,
                'message' => "Created location: $name"
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to create location'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update a location from AI request
 */
function updateLocationFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/locations.php';

    try {
        $locationId = $input['location_id'] ?? null;

        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID is required'];
        }

        // Verify location exists
        $location = getLocation($locationId, $bookId);
        if (!$location) {
            return ['success' => false, 'error' => 'Location not found'];
        }

        // Build update data
        $updateData = [];
        $allowedFields = ['name', 'description', 'atmosphere', 'significance', 'notes'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        // Update the location
        $result = updateLocation($locationId, $bookId, $updateData);

        if ($result['success']) {
            // Track updated locations globally
            global $updatedLocations;
            if (!isset($updatedLocations)) {
                $updatedLocations = [];
            }
            $updatedLocations[] = [
                'location_id' => $locationId,
                'name' => $updateData['name'] ?? $location['name'],
                'updated_fields' => array_keys($updateData)
            ];

            $updatedFields = implode(', ', array_keys($updateData));
            return [
                'success' => true,
                'location_id' => $locationId,
                'updated_fields' => array_keys($updateData),
                'message' => "Updated location '{$location['name']}': $updatedFields"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to update location'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete a location from AI request
 */
function deleteLocationFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/locations.php';

    try {
        $locationId = $input['location_id'] ?? null;

        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID is required'];
        }

        // Get location details before deletion
        $location = getLocation($locationId, $bookId);
        if (!$location) {
            return ['success' => false, 'error' => 'Location not found'];
        }

        $locationName = $location['name'];

        // Delete the location
        $result = deleteLocation($locationId, $bookId);

        if ($result['success']) {
            return [
                'success' => true,
                'location_id' => $locationId,
                'message' => "Deleted location: $locationName"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to delete location'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Read all plot threads from AI request
 */
function readPlotThreadsFromAI($bookId, $input) {
    require_once __DIR__ . '/../includes/plot_threads.php';

    try {
        $threads = getPlotThreads($bookId);

        // Format for AI consumption
        $formattedThreads = array_map(function($thread) {
            return [
                'id' => $thread['id'],
                'title' => $thread['title'],
                'description' => $thread['description'],
                'thread_type' => $thread['thread_type'],
                'status' => $thread['status'],
                'color' => $thread['color']
            ];
        }, $threads);

        return [
            'success' => true,
            'plot_threads' => $formattedThreads,
            'total_count' => count($threads),
            'message' => 'Retrieved ' . count($threads) . ' plot threads'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Read a single plot thread from AI request
 */
function readPlotThreadFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/plot_threads.php';

    try {
        $threadId = $input['thread_id'] ?? null;

        if (!$threadId) {
            return ['success' => false, 'error' => 'Thread ID is required'];
        }

        $thread = getPlotThread($threadId, $bookId);

        if (!$thread) {
            return ['success' => false, 'error' => 'Plot thread not found'];
        }

        return [
            'success' => true,
            'plot_thread' => [
                'id' => $thread['id'],
                'title' => $thread['title'],
                'description' => $thread['description'],
                'thread_type' => $thread['thread_type'],
                'status' => $thread['status'],
                'color' => $thread['color']
            ],
            'message' => "Retrieved plot thread: {$thread['title']}"
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a plot thread from AI request
 */
function createPlotThreadFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/plot_threads.php';

    try {
        $title = $input['title'] ?? '';

        if (empty($title)) {
            return ['success' => false, 'error' => 'Plot thread title is required'];
        }

        // Build thread data
        $threadData = [
            'title' => $title,
            'description' => $input['description'] ?? '',
            'thread_type' => $input['thread_type'] ?? 'subplot',
            'status' => $input['status'] ?? 'open',
            'color' => $input['color'] ?? null
        ];

        // Create the thread
        $result = createPlotThread($bookId, $threadData);

        if ($result['success']) {
            $threadId = $result['thread_id'];

            // Track created threads globally
            global $createdPlotThreads;
            if (!isset($createdPlotThreads)) {
                $createdPlotThreads = [];
            }
            $createdPlotThreads[] = [
                'thread_id' => $threadId,
                'title' => $title,
                'type' => $threadData['thread_type']
            ];

            return [
                'success' => true,
                'thread_id' => $threadId,
                'title' => $title,
                'message' => "Created plot thread: $title ({$threadData['thread_type']})"
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to create plot thread'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update a plot thread from AI request
 */
function updatePlotThreadFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/plot_threads.php';

    try {
        $threadId = $input['thread_id'] ?? null;

        if (!$threadId) {
            return ['success' => false, 'error' => 'Thread ID is required'];
        }

        // Verify thread exists
        $thread = getPlotThread($threadId, $bookId);
        if (!$thread) {
            return ['success' => false, 'error' => 'Plot thread not found'];
        }

        // Build update data
        $updateData = [];
        $allowedFields = ['title', 'description', 'thread_type', 'status', 'color'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        // Update the thread
        $result = updatePlotThread($threadId, $bookId, $updateData);

        if ($result['success']) {
            // Track updated threads globally
            global $updatedPlotThreads;
            if (!isset($updatedPlotThreads)) {
                $updatedPlotThreads = [];
            }
            $updatedPlotThreads[] = [
                'thread_id' => $threadId,
                'title' => $updateData['title'] ?? $thread['title'],
                'updated_fields' => array_keys($updateData)
            ];

            $updatedFields = implode(', ', array_keys($updateData));
            return [
                'success' => true,
                'thread_id' => $threadId,
                'updated_fields' => array_keys($updateData),
                'message' => "Updated plot thread '{$thread['title']}': $updatedFields"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to update plot thread'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete a plot thread from AI request
 */
function deletePlotThreadFromAI($input, $bookId) {
    require_once __DIR__ . '/../includes/plot_threads.php';

    try {
        $threadId = $input['thread_id'] ?? null;

        if (!$threadId) {
            return ['success' => false, 'error' => 'Thread ID is required'];
        }

        // Get thread details before deletion
        $thread = getPlotThread($threadId, $bookId);
        if (!$thread) {
            return ['success' => false, 'error' => 'Plot thread not found'];
        }

        $threadTitle = $thread['title'];

        // Delete the thread
        $result = deletePlotThread($threadId, $bookId);

        if ($result['success']) {
            return [
                'success' => true,
                'thread_id' => $threadId,
                'message' => "Deleted plot thread: $threadTitle"
            ];
        } else {
            return ['success' => false, 'error' => $result['message'] ?? 'Failed to delete plot thread'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
