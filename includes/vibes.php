<?php
/**
 * Helpers for book vibe generation, storage, and retrieval.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/ai_context.php';
require_once __DIR__ . '/books.php';

/**
 * Get the latest saved vibe for a book.
 */
function getLatestBookVibe($bookId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM book_vibes WHERE book_id = ? LIMIT 1");
    $stmt->execute([$bookId]);
    $vibe = $stmt->fetch();

    if (!$vibe) {
        return null;
    }

    $playlist = json_decode($vibe['playlist_json'] ?? '[]', true) ?: [];

    return [
        'palette' => [
            'primary' => $vibe['color_primary'] ?? '#5b67ff',
            'secondary' => $vibe['color_secondary'] ?? '#0f172a',
            'accent' => $vibe['color_accent'] ?? '#e0f2fe'
        ],
        'songs' => $playlist,
        'milestone_label' => $vibe['milestone_label'] ?? null,
        'milestone_value' => (int)($vibe['milestone_value'] ?? 0),
        'summary' => $vibe['summary'] ?? 'Book vibe ready'
    ];
}

/**
 * Save or update a book's vibe payload.
 */
function saveBookVibe($bookId, array $data) {
    $pdo = getDBConnection();

    $palette = $data['palette'] ?? [];
    $songs = $data['songs'] ?? [];

    $stmt = $pdo->prepare("INSERT INTO book_vibes (
            book_id, summary, milestone_label, milestone_value, color_primary, color_secondary, color_accent, playlist_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            summary = VALUES(summary),
            milestone_label = VALUES(milestone_label),
            milestone_value = VALUES(milestone_value),
            color_primary = VALUES(color_primary),
            color_secondary = VALUES(color_secondary),
            color_accent = VALUES(color_accent),
            playlist_json = VALUES(playlist_json),
            updated_at = CURRENT_TIMESTAMP()");

    $stmt->execute([
        $bookId,
        $data['summary'] ?? 'Fresh vibe',
        $data['milestone_label'] ?? null,
        $data['milestone_value'] ?? 0,
        $palette['primary'] ?? '#5b67ff',
        $palette['secondary'] ?? '#0f172a',
        $palette['accent'] ?? '#e0f2fe',
        json_encode(array_values($songs))
    ]);
}

/**
 * Detects whether a new vibe milestone has been reached.
 */
function detectVibeMilestone($book, $currentWordCount) {
    $last = (int)($book['last_vibe_milestone'] ?? 0);
    $target = (int)($book['target_word_count'] ?? 0);

    $milestoneValue = null;
    $milestoneLabel = null;

    if ($target > 0) {
        $fractions = [0.25, 0.5, 0.75, 1];
        foreach ($fractions as $fraction) {
            $threshold = (int)floor($target * $fraction);
            if ($currentWordCount >= $threshold && $last < $threshold) {
                $milestoneValue = $threshold;
                $milestoneLabel = (int)($fraction * 100) . '% progress';
            }
        }
    } else {
        $step = 5000;
        $threshold = (int)floor($currentWordCount / $step) * $step;
        if ($threshold > 0 && $threshold > $last) {
            $milestoneValue = $threshold;
            $milestoneLabel = number_format($threshold) . ' words';
        }
    }

    if ($milestoneValue === null) {
        return null;
    }

    return [
        'value' => $milestoneValue,
        'label' => $milestoneLabel
    ];
}

/**
 * Generate and persist a vibe payload.
 */
function generateBookVibe($book, ?array $milestone = null) {
    if (empty(AI_API_KEY)) {
        throw new Exception('AI provider is not configured.');
    }

    $context = buildAIContext($book, null);
    $milestoneLabel = $milestone['label'] ?? 'New milestone';
    $milestoneValue = $milestone['value'] ?? ($book['current_word_count'] ?? 0);

    $prompt = $context . "\n\n" .
        "The book just hit a milestone ({$milestoneLabel}). " .
        "Generate a JSON object capturing a vibe for the book with a cohesive color palette and a short list of songs that match the tone. " .
        "Favor hex colors that will be readable in a UI. Return JSON with fields: summary (string), palette (object with primary, secondary, accent hex strings), songs (array of 3-6 objects with title and artist and optional mood_note).";

    $raw = callVibeAssistant($prompt);
    $parsed = parseVibePayload($raw);

    $palette = $parsed['palette'] ?? [];
    $songs = $parsed['songs'] ?? [];

    $songs = enrichSongsWithYouTube($songs);

    $payload = [
        'summary' => $parsed['summary'] ?? 'Mood update',
        'palette' => $palette,
        'songs' => $songs,
        'milestone_label' => $milestoneLabel,
        'milestone_value' => $milestoneValue
    ];

    saveBookVibe($book['id'], $payload);
    updateBookVibeMilestone($book['id'], $milestoneValue);

    return getLatestBookVibe($book['id']);
}

/**
 * Update the book's last vibe milestone marker.
 */
function updateBookVibeMilestone($bookId, $milestoneValue) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE books SET last_vibe_milestone = ? WHERE id = ?");
    $stmt->execute([(int)$milestoneValue, $bookId]);
}

/**
 * Call the configured AI provider for vibe output.
 */
function callVibeAssistant($prompt) {
    if (usingOpenAIProvider()) {
        $payload = [
            'model' => AI_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => 'You return strictly JSON without code fences.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.8,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'book_vibe',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['type' => 'string'],
                            'palette' => [
                                'type' => 'object',
                                'properties' => [
                                    'primary' => ['type' => 'string'],
                                    'secondary' => ['type' => 'string'],
                                    'accent' => ['type' => 'string']
                                ]
                            ],
                            'songs' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string'],
                                        'artist' => ['type' => 'string'],
                                        'mood_note' => ['type' => 'string']
                                    ],
                                    'required' => ['title', 'artist']
                                ]
                            ]
                        ],
                        'required' => ['summary', 'palette', 'songs']
                    ]
                ]
            ]
        ];

        $result = makeOpenAIRequest($payload);
        return $result['choices'][0]['message']['content'] ?? '';
    }

    $payload = [
        'model' => AI_MODEL,
        'max_tokens' => 512,
        'temperature' => 0.8,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt . "\nReturn only raw JSON in the shape: {summary, palette:{primary,secondary,accent}, songs:[{title,artist,mood_note?}]}"
            ]
        ]
    ];

    $result = makeAnthropicAPIRequest($payload);

    if (!isset($result['content'])) {
        return '';
    }

    foreach ($result['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            return $block['text'];
        }
    }

    return '';
}

/**
 * Parse JSON payload from the vibe assistant.
 */
function parseVibePayload($raw) {
    if (!is_string($raw)) {
        return [];
    }

    $trimmed = trim($raw);
    if (str_starts_with($trimmed, '```')) {
        $trimmed = preg_replace('/^```[a-zA-Z]*\n?/', '', $trimmed);
        $trimmed = preg_replace('/```$/', '', $trimmed);
    }

    $json = json_decode($trimmed, true);
    if (!$json) {
        return [];
    }

    $palette = $json['palette'] ?? [];
    $songs = $json['songs'] ?? [];

    return [
        'summary' => $json['summary'] ?? null,
        'palette' => [
            'primary' => $palette['primary'] ?? '#5b67ff',
            'secondary' => $palette['secondary'] ?? '#0f172a',
            'accent' => $palette['accent'] ?? '#e0f2fe'
        ],
        'songs' => array_map(function ($song) {
            return [
                'title' => $song['title'] ?? '',
                'artist' => $song['artist'] ?? '',
                'mood_note' => $song['mood_note'] ?? null
            ];
        }, is_array($songs) ? $songs : [])
    ];
}

/**
 * Augment songs with YouTube URLs when possible.
 */
function enrichSongsWithYouTube(array $songs) {
    $apiKey = defined('YOUTUBE_API_KEY') ? YOUTUBE_API_KEY : '';

    return array_map(function ($song) use ($apiKey) {
        if (empty($song['title'])) {
            return $song;
        }

        $query = $song['title'] . (!empty($song['artist']) ? ' ' . $song['artist'] : '');
        $youtubeUrl = $apiKey ? fetchYouTubeTopUrl($query, $apiKey) : null;

        if ($youtubeUrl) {
            $song['youtube_url'] = $youtubeUrl;
        }

        return $song;
    }, $songs);
}

/**
 * Search the YouTube Data API for the top result URL.
 */
function fetchYouTubeTopUrl($query, $apiKey) {
    if (!$apiKey || !$query) {
        return null;
    }

    $params = http_build_query([
        'part' => 'snippet',
        'type' => 'video',
        'maxResults' => 1,
        'q' => $query,
        'key' => $apiKey
    ]);

    $url = 'https://www.googleapis.com/youtube/v3/search?' . $params;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno || $httpCode !== 200) {
        error_log('YouTube API error: ' . ($curlError ?: 'HTTP ' . $httpCode));
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['items'][0]['id']['videoId'])) {
        return null;
    }

    $videoId = $data['items'][0]['id']['videoId'];
    return 'https://www.youtube.com/watch?v=' . $videoId;
}
?>
