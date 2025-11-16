<?php
/**
 * Helpers for integrating Google Text-to-Speech with the AI chat UI.
 */

require_once __DIR__ . '/../config/config.php';

if (!function_exists('hasGoogleTTSConfig')) {
    function hasGoogleTTSConfig() {
        $hasApiKey = defined('GOOGLE_TTS_API_KEY') && GOOGLE_TTS_API_KEY !== '';
        $hasAccessToken = defined('GOOGLE_TTS_ACCESS_TOKEN') && GOOGLE_TTS_ACCESS_TOKEN !== '';
        return $hasApiKey || $hasAccessToken;
    }
}

if (!function_exists('getGoogleTTSVoices')) {
    function getGoogleTTSVoices() {
        return defined('GOOGLE_TTS_VOICES') ? GOOGLE_TTS_VOICES : [];
    }
}

if (!function_exists('getAIChatVoiceConfig')) {
    function getAIChatVoiceConfig() {
        $voices = array_map(function ($voice) {
            return [
                'name' => $voice['name'] ?? null,
                'label' => $voice['label'] ?? ($voice['name'] ?? ''),
                'languageCode' => $voice['languageCode'] ?? (defined('GOOGLE_TTS_DEFAULT_LANGUAGE') ? GOOGLE_TTS_DEFAULT_LANGUAGE : 'en-US'),
                'model' => $voice['model'] ?? ($voice['model_name'] ?? (defined('GOOGLE_TTS_DEFAULT_MODEL') ? GOOGLE_TTS_DEFAULT_MODEL : null)),
                'prompt' => $voice['prompt'] ?? null,
                'audioEncoding' => strtoupper($voice['audioEncoding'] ?? (defined('GOOGLE_TTS_DEFAULT_AUDIO_ENCODING') ? GOOGLE_TTS_DEFAULT_AUDIO_ENCODING : 'MP3'))
            ];
        }, getGoogleTTSVoices());

        $mode = hasGoogleTTSConfig() && !empty($voices) ? 'google' : 'browser';

        return [
            'mode' => $mode,
            'voices' => array_values($voices),
            'endpoint' => '/api/text_to_speech.php',
            'defaultAudioEncoding' => defined('GOOGLE_TTS_DEFAULT_AUDIO_ENCODING') ? GOOGLE_TTS_DEFAULT_AUDIO_ENCODING : 'MP3'
        ];
    }
}

if (!function_exists('synthesizeTextToSpeech')) {
    function synthesizeTextToSpeech($text, array $options = []) {
        if (!hasGoogleTTSConfig()) {
            throw new RuntimeException('Google Text-to-Speech is not configured.');
        }

        $text = trim((string) ($text ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Text is required for speech synthesis.');
        }

        $voiceName = $options['voice'] ?? ($options['voice_name'] ?? ($options['voiceName'] ?? null));
        $languageCode = $options['languageCode'] ?? null;
        $prompt = $options['prompt'] ?? null;
        $modelName = $options['model'] ?? ($options['model_name'] ?? null);
        $audioEncoding = strtoupper($options['audioEncoding'] ?? (defined('GOOGLE_TTS_DEFAULT_AUDIO_ENCODING') ? GOOGLE_TTS_DEFAULT_AUDIO_ENCODING : 'MP3'));

        $voiceDefinition = $voiceName ? findGoogleTTSVoice($voiceName) : null;
        if ($voiceDefinition) {
            $languageCode = $languageCode ?: ($voiceDefinition['languageCode'] ?? null);
            $prompt = $prompt ?: ($voiceDefinition['prompt'] ?? null);
            if (!$modelName) {
                $modelName = $voiceDefinition['model'] ?? ($voiceDefinition['model_name'] ?? null);
            }
            if (!empty($voiceDefinition['audioEncoding'])) {
                $audioEncoding = strtoupper($voiceDefinition['audioEncoding']);
            }
        }

        $languageCode = $languageCode ?: (defined('GOOGLE_TTS_DEFAULT_LANGUAGE') ? GOOGLE_TTS_DEFAULT_LANGUAGE : 'en-US');
        $modelName = $modelName ?: (defined('GOOGLE_TTS_DEFAULT_MODEL') ? GOOGLE_TTS_DEFAULT_MODEL : null);

        $input = ['text' => $text];
        if (!empty($prompt)) {
            $input['prompt'] = $prompt;
        }

        $payload = [
            'input' => $input,
            'voice' => array_filter([
                'languageCode' => $languageCode,
                'name' => $voiceName,
                'model' => $modelName,
                'model_name' => $modelName
            ], function ($value) {
                return $value !== null && $value !== '';
            }),
            'audioConfig' => array_filter([
                'audioEncoding' => $audioEncoding,
                'speakingRate' => isset($options['speakingRate']) ? (float) $options['speakingRate'] : null
            ], function ($value) {
                return $value !== null && $value !== '';
            })
        ];

        if (!empty($options['ssml'])) {
            $payload['input'] = ['ssml' => $options['ssml']];
        }

        $response = makeGoogleTTSRequest($payload);
        if (!isset($response['audioContent'])) {
            throw new RuntimeException('Google TTS response did not include audio content.');
        }

        $mimeType = guessMimeTypeFromEncoding($audioEncoding);

        return [
            'audioContent' => $response['audioContent'],
            'audioEncoding' => $audioEncoding,
            'mimeType' => $mimeType,
            'rawResponse' => $response
        ];
    }
}

if (!function_exists('findGoogleTTSVoice')) {
    function findGoogleTTSVoice($voiceName) {
        foreach (getGoogleTTSVoices() as $voice) {
            if (($voice['name'] ?? null) === $voiceName) {
                return $voice;
            }
        }
        return null;
    }
}

if (!function_exists('makeGoogleTTSRequest')) {
    function makeGoogleTTSRequest(array $payload) {
        $endpoint = defined('GOOGLE_TTS_ENDPOINT') ? GOOGLE_TTS_ENDPOINT : 'https://texttospeech.googleapis.com/v1/text:synthesize';
        $url = $endpoint;

        if (defined('GOOGLE_TTS_API_KEY') && GOOGLE_TTS_API_KEY !== '') {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . 'key=' . urlencode(GOOGLE_TTS_API_KEY);
        }

        $headers = ['Content-Type: application/json'];
        if (defined('GOOGLE_TTS_ACCESS_TOKEN') && GOOGLE_TTS_ACCESS_TOKEN !== '') {
            $headers[] = 'Authorization: Bearer ' . GOOGLE_TTS_ACCESS_TOKEN;
        }
        if (defined('GOOGLE_TTS_USER_PROJECT') && GOOGLE_TTS_USER_PROJECT !== '') {
            $headers[] = 'x-goog-user-project: ' . GOOGLE_TTS_USER_PROJECT;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno) {
            throw new RuntimeException("Google TTS cURL error ({$curlErrno}): {$curlError}");
        }

        if ($httpCode !== 200) {
            $snippet = $response ? substr($response, 0, 500) : 'No response body';
            throw new RuntimeException("Google TTS API error ({$httpCode}): {$snippet}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Google TTS API.');
        }

        return $decoded;
    }
}

if (!function_exists('guessMimeTypeFromEncoding')) {
    function guessMimeTypeFromEncoding($encoding) {
        $encoding = strtoupper($encoding ?? '');
        switch ($encoding) {
            case 'LINEAR16':
                return 'audio/wav';
            case 'OGG_OPUS':
                return 'audio/ogg';
            case 'MP3':
            default:
                return 'audio/mpeg';
        }
    }
}
