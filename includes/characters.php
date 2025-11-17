<?php
/**
 * Character Management Functions
 * VibeWriter - AI-Powered Book Writing Tool
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/google_oauth.php';

/**
 * Get all characters for a book
 */
function getCharacters($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT c.*,
               COUNT(DISTINCT ci.id) as image_count,
               (SELECT file_path FROM character_images WHERE character_id = c.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM characters c
        LEFT JOIN character_images ci ON c.id = ci.character_id
        WHERE c.book_id = ?
        GROUP BY c.id
        ORDER BY c.role, c.name
    ");
    $stmt->execute([$bookId]);
    return $stmt->fetchAll();
}

/**
 * Get a single character
 */
function getCharacter($characterId, $bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM characters
        WHERE id = ? AND book_id = ?
    ");
    $stmt->execute([$characterId, $bookId]);
    return $stmt->fetch();
}

/**
 * Create a new character
 */
function createCharacter($bookId, $data) {
    $pdo = getDBConnection();

    $fields = [
        'name', 'role', 'age', 'gender', 'physical_description',
        'personality', 'speech_patterns', 'voice_description',
        'background', 'motivation', 'arc', 'relationships',
        'notes', 'ai_generated'
    ];

    $values = [$bookId];
    $placeholders = ['?'];
    $setFields = ['book_id'];

    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $setFields[] = $field;
            $placeholders[] = '?';
            $values[] = $data[$field];
        }
    }

    // Handle JSON metadata
    if (isset($data['ai_metadata']) && is_array($data['ai_metadata'])) {
        $setFields[] = 'ai_metadata';
        $placeholders[] = '?';
        $values[] = json_encode($data['ai_metadata']);
    }

    $sql = "INSERT INTO characters (" . implode(', ', $setFields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return ['success' => true, 'character_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Update a character
 */
function updateCharacter($characterId, $bookId, $data) {
    $pdo = getDBConnection();

    $fields = [];
    $values = [];

    $allowedFields = [
        'name', 'role', 'age', 'gender', 'physical_description',
        'personality', 'speech_patterns', 'voice_description', 'dialogue_examples',
        'background', 'motivation', 'arc', 'relationships', 'notes', 'profile_image'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    // Handle JSON metadata
    if (isset($data['ai_metadata'])) {
        if (is_array($data['ai_metadata'])) {
            $fields[] = "ai_metadata = JSON_MERGE_PATCH(COALESCE(ai_metadata, '{}'), ?)";
            $values[] = json_encode($data['ai_metadata']);
        } else {
            $fields[] = "ai_metadata = ?";
            $values[] = $data['ai_metadata'];
        }
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }

    $values[] = $characterId;
    $values[] = $bookId;

    $sql = "UPDATE characters SET " . implode(', ', $fields) . " WHERE id = ? AND book_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return ['success' => false, 'message' => 'Character not found or no changes made'];
        }

        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete a character
 */
function deleteCharacter($characterId, $bookId) {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("DELETE FROM characters WHERE id = ? AND book_id = ?");
        $stmt->execute([$characterId, $bookId]);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get character images
 */
function getCharacterImages($characterId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM character_images
        WHERE character_id = ?
        ORDER BY is_primary DESC, created_at DESC
    ");
    $stmt->execute([$characterId]);
    return $stmt->fetchAll();
}

/**
 * Add character image
 */
function addCharacterImage($characterId, $filePath, $prompt = null, $generationParams = null, $isPrimary = false) {
    $pdo = getDBConnection();

    try {
        // If setting as primary, unset other primary images
        if ($isPrimary) {
            $stmt = $pdo->prepare("UPDATE character_images SET is_primary = 0 WHERE character_id = ?");
            $stmt->execute([$characterId]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO character_images (character_id, file_path, prompt, generation_params, is_primary)
            VALUES (?, ?, ?, ?, ?)
        ");

        $paramsJson = is_array($generationParams) ? json_encode($generationParams) : $generationParams;

        $stmt->execute([$characterId, $filePath, $prompt, $paramsJson, $isPrimary ? 1 : 0]);

        // Update character's profile_image if this is primary
        if ($isPrimary) {
            $stmt = $pdo->prepare("UPDATE characters SET profile_image = ? WHERE id = ?");
            $stmt->execute([$filePath, $characterId]);
        }

        return ['success' => true, 'image_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Set primary character image
 */
function setPrimaryCharacterImage($imageId, $characterId) {
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        // Unset all primary images for this character
        $stmt = $pdo->prepare("UPDATE character_images SET is_primary = 0 WHERE character_id = ?");
        $stmt->execute([$characterId]);

        // Set new primary
        $stmt = $pdo->prepare("UPDATE character_images SET is_primary = 1 WHERE id = ? AND character_id = ?");
        $stmt->execute([$imageId, $characterId]);

        // Update character's profile_image
        $stmt = $pdo->prepare("
            UPDATE characters c
            JOIN character_images ci ON ci.character_id = c.id
            SET c.profile_image = ci.file_path
            WHERE ci.id = ? AND c.id = ?
        ");
        $stmt->execute([$imageId, $characterId]);

        $pdo->commit();
        return ['success' => true];
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Save character dialogue (for chat-with-character mode)
 */
function saveCharacterDialogue($characterId, $bookId, $userId, $userMessage, $characterResponse, $context = null) {
    $pdo = getDBConnection();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO character_dialogues (character_id, book_id, user_id, user_message, character_response, context)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$characterId, $bookId, $userId, $userMessage, $characterResponse, $context]);
        return ['success' => true, 'dialogue_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get character dialogue history
 */
function getCharacterDialogues($characterId, $limit = 50) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM character_dialogues
        WHERE character_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$characterId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Search characters by name
 */
function searchCharacters($bookId, $searchTerm) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM characters
        WHERE book_id = ? AND name LIKE ?
        ORDER BY name
    ");
    $stmt->execute([$bookId, "%$searchTerm%"]);
    return $stmt->fetchAll();
}

/**
 * Get character statistics
 */
function getCharacterStats($characterId) {
    $pdo = getDBConnection();

    // Count dialogues
    $stmt = $pdo->prepare("SELECT COUNT(*) as dialogue_count FROM character_dialogues WHERE character_id = ?");
    $stmt->execute([$characterId]);
    $dialogueCount = $stmt->fetch()['dialogue_count'];

    // Count images
    $stmt = $pdo->prepare("SELECT COUNT(*) as image_count FROM character_images WHERE character_id = ?");
    $stmt->execute([$characterId]);
    $imageCount = $stmt->fetch()['image_count'];

    // Count mentions in AI conversations (if character name is in message/response)
    // This is a simple implementation - could be enhanced with proper mention tracking
    $stmt = $pdo->prepare("
        SELECT c.name, COUNT(ac.id) as mention_count
        FROM characters c
        LEFT JOIN ai_conversations ac ON ac.book_id = (SELECT book_id FROM characters WHERE id = ?)
            AND (ac.message LIKE CONCAT('%', c.name, '%') OR ac.response LIKE CONCAT('%', c.name, '%'))
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$characterId, $characterId]);
    $mentions = $stmt->fetch();
    $mentionCount = $mentions ? $mentions['mention_count'] : 0;

    return [
        'dialogue_count' => $dialogueCount,
        'image_count' => $imageCount,
        'mention_count' => $mentionCount
    ];
}

/**
 * Generate character image using Google Gemini API
 */
function generateCharacterImage($character, $additionalPrompt = null) {
    if (empty(GEMINI_API_KEY)) {
        return ['success' => false, 'message' => 'Gemini API key not configured'];
    }

    // Build image generation prompt from character details
    $prompt = "Create a detailed character portrait of ";
    $prompt .= $character['name'];

    if (!empty($character['physical_description'])) {
        $prompt .= ". Physical appearance: " . $character['physical_description'];
    }

    if (!empty($character['age'])) {
        $prompt .= ". Age: " . $character['age'];
    }

    if (!empty($character['gender'])) {
        $prompt .= ". Gender: " . $character['gender'];
    }

    if (!empty($character['personality'])) {
        $prompt .= ". Personality traits to reflect in expression: " . substr($character['personality'], 0, 200);
    }

    if ($additionalPrompt) {
        $prompt .= ". " . $additionalPrompt;
    }

    $prompt .= ". Style: professional book character illustration, high quality, detailed";

    // Call Gemini API
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'message' => 'Gemini API request failed with status code: ' . $httpCode,
            'response' => $response
        ];
    }

    $result = json_decode($response, true);

    // Extract base64 image data from response
    // Response format: candidates[0].content.parts[N].inlineData.data
    // The image may be in any part (text is often in parts[0], image in parts[1])
    $base64Image = null;
    $mimeType = 'image/png';

    if (isset($result['candidates'][0]['content']['parts'])) {
        foreach ($result['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data'])) {
                $base64Image = $part['inlineData']['data'];
                $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
                break;
            }
        }
    }

    if (!$base64Image) {
        return [
            'success' => false,
            'message' => 'No image data in Gemini response',
            'response' => $result
        ];
    }

    // Determine file extension from mime type
    $extension = 'png';
    if (strpos($mimeType, 'jpeg') !== false || strpos($mimeType, 'jpg') !== false) {
        $extension = 'jpg';
    }

    // Save image to uploads directory
    $uploadsDir = __DIR__ . '/../uploads/characters';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    $filename = 'char_' . $character['id'] . '_' . time() . '.' . $extension;
    $filepath = $uploadsDir . '/' . $filename;

    // Decode and save
    $imageData = base64_decode($base64Image);
    if (file_put_contents($filepath, $imageData) === false) {
        return ['success' => false, 'message' => 'Failed to save image file'];
    }

    // Add to database
    $relativePath = 'uploads/characters/' . $filename;
    $imageResult = addCharacterImage($character['id'], $relativePath, $prompt);

    if (!$imageResult['success']) {
        // Clean up file if database insert failed
        unlink($filepath);
        return $imageResult;
    }

    return [
        'success' => true,
        'image_id' => $imageResult['image_id'],
        'file_path' => $relativePath,
        'prompt' => $prompt
    ];
}
?>
