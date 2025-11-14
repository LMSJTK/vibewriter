<?php
/**
 * Shared helpers for interacting with Anthropic and OpenAI chat APIs.
 */

if (!function_exists('usingOpenAIProvider')) {
    function usingOpenAIProvider() {
        if (defined('AI_PROVIDER') && AI_PROVIDER === 'openai') {
            return true;
        }

        $model = defined('AI_MODEL') ? AI_MODEL : '';
        return strpos($model, 'gpt-') === 0;
    }
}

if (!function_exists('convertToolsForOpenAI')) {
    function convertToolsForOpenAI($tools) {
        $converted = [];

        foreach ($tools as $tool) {
            $parameters = $tool['input_schema'] ?? [
                'type' => 'object',
                'properties' => new stdClass()
            ];

            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $parameters
                ]
            ];
        }

        return $converted;
    }
}

if (!function_exists('makeOpenAIRequest')) {
    function makeOpenAIRequest($payload) {
        $apiKey = AI_API_KEY;
        $endpoint = AI_API_ENDPOINT;

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        error_log("=== OPENAI API REQUEST ===");
        error_log("Payload size: " . strlen($jsonPayload) . " bytes");
        error_log("Model: " . ($payload['model'] ?? 'unknown'));

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
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
            error_log("Failed OpenAI API request. Payload: " . substr($jsonPayload, 0, 1000));
            throw new Exception($errorDetails);
        }

        $result = json_decode($response, true);

        if (!$result) {
            throw new Exception("Invalid JSON response from OpenAI API");
        }

        error_log("=== OPENAI API RESPONSE ===");
        if (isset($result['choices'][0]['finish_reason'])) {
            error_log("Finish reason: " . $result['choices'][0]['finish_reason']);
        }

        return $result;
    }
}

if (!function_exists('encodeForAnthropicAPI')) {
    function encodeForAnthropicAPI($data) {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}

if (!function_exists('makeAnthropicAPIRequest')) {
    function makeAnthropicAPIRequest($payload) {
        $apiKey = AI_API_KEY;
        $endpoint = AI_API_ENDPOINT;

        $jsonPayload = encodeForAnthropicAPI($payload);

        error_log("=== ANTHROPIC API REQUEST ===");
        error_log("Payload size: " . strlen($jsonPayload) . " bytes");
        error_log("Model: " . ($payload['model'] ?? 'unknown'));
        error_log("Tools count: " . (isset($payload['tools']) ? count($payload['tools']) : 0));
        if (isset($payload['messages'])) {
            error_log("Messages count: " . count($payload['messages']));
            $lastMessage = end($payload['messages']);
            error_log("Last message role: " . ($lastMessage['role'] ?? 'unknown'));
        }

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
            error_log("Failed Anthropic API request. Payload: " . substr($jsonPayload, 0, 1000));
            throw new Exception($errorDetails);
        }

        $result = json_decode($response, true);

        if (!$result) {
            throw new Exception("Invalid JSON response from API");
        }

        error_log("=== ANTHROPIC API RESPONSE ===");
        error_log("Stop reason: " . ($result['stop_reason'] ?? 'unknown'));
        error_log("Content blocks: " . (isset($result['content']) ? count($result['content']) : 0));
        if (isset($result['content'])) {
            foreach ($result['content'] as $idx => $block) {
                error_log("Block $idx type: " . ($block['type'] ?? 'unknown'));
                if ($block['type'] === 'text') {
                    error_log("Text preview: " . substr($block['text'] ?? '', 0, 100));
                } elseif ($block['type'] === 'tool_use') {
                    error_log("Tool: " . ($block['name'] ?? 'unknown'));
                    error_log("Tool input: " . json_encode($block['input'] ?? []));
                }
            }
        }

        return $result;
    }
}

