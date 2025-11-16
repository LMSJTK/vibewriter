<?php
/**
 * Helpers for integrating third-party Text-to-Speech providers with the AI chat UI.
 */

require_once __DIR__ . '/../config/config.php';

if (!function_exists('hasGoogleTTSConfig')) {
    function hasGoogleTTSConfig() {
        $hasApiKey = defined('GOOGLE_TTS_API_KEY') && GOOGLE_TTS_API_KEY !== '';
        $hasAccessToken = defined('GOOGLE_TTS_ACCESS_TOKEN') && GOOGLE_TTS_ACCESS_TOKEN !== '';
        return $hasApiKey || $hasAccessToken;
    }
}

if (!function_exists('hasElevenLabsTTSConfig')) {
    function hasElevenLabsTTSConfig() {
        return defined('ELEVENLABS_API_KEY') && ELEVENLABS_API_KEY !== '';
    }
}

if (!function_exists('getGoogleTTSVoices')) {
    function getGoogleTTSVoices() {
        return defined('GOOGLE_TTS_VOICES') ? GOOGLE_TTS_VOICES : [];
    }
}

if (!function_exists('getElevenLabsTTSVoices')) {
    function getElevenLabsTTSVoices() {
        return defined('ELEVENLABS_TTS_VOICES') ? ELEVENLABS_TTS_VOICES : [];
    }
}

if (!function_exists('getAIChatVoiceConfig')) {
    function getAIChatVoiceConfig() {
        $mode = 'browser';
        $voices = [];
        $defaultEncoding = 'MP3';

        $googleVoices = getGoogleTTSVoices();
        if (hasElevenLabsTTSConfig()) {
            $mode = 'elevenlabs';
            $defaultEncoding = 'MP3';
            $voices = [];
            $fallbackLabelIndex = 1;
            foreach (getElevenLabsTTSVoices() as $voice) {
                $voiceId = $voice['id'] ?? ($voice['voice_id'] ?? ($voice['voiceId'] ?? null));
                $label = $voice['label'] ?? ($voice['name'] ?? ('Voice ' . $fallbackLabelIndex));
                $voices[] = [
                    'id' => $voiceId ?: ('voice_' . $fallbackLabelIndex),
                    'voiceId' => $voiceId,
                    'name' => $voice['name'] ?? $label,
                    'label' => $label,
                    'description' => $voice['description'] ?? null,
                    'model' => $voice['model'] ?? ($voice['model_id'] ?? (defined('ELEVENLABS_TTS_DEFAULT_MODEL') ? ELEVENLABS_TTS_DEFAULT_MODEL : null)),
                    'audioEncoding' => strtoupper($voice['audioEncoding'] ?? 'MP3'),
                    'outputFormat' => $voice['output_format'] ?? (defined('ELEVENLABS_TTS_DEFAULT_OUTPUT_FORMAT') ? ELEVENLABS_TTS_DEFAULT_OUTPUT_FORMAT : 'mp3_44100_128'),
                    'voiceSettings' => $voice['voice_settings'] ?? null
                ];
                $fallbackLabelIndex++;
            }
        } elseif (hasGoogleTTSConfig() && !empty($googleVoices)) {
            $mode = 'google';
            $defaultEncoding = defined('GOOGLE_TTS_DEFAULT_AUDIO_ENCODING') ? GOOGLE_TTS_DEFAULT_AUDIO_ENCODING : 'MP3';
            $voices = array_map(function ($voice) use ($defaultEncoding) {
                $voiceName = $voice['name'] ?? ($voice['label'] ?? null);
                return [
                    'id' => $voiceName,
                    'name' => $voiceName,
                    'label' => $voice['label'] ?? ($voiceName ?: ''),
                    'languageCode' => $voice['languageCode'] ?? (defined('GOOGLE_TTS_DEFAULT_LANGUAGE') ? GOOGLE_TTS_DEFAULT_LANGUAGE : 'en-US'),
                    'model' => $voice['model'] ?? ($voice['model_name'] ?? (defined('GOOGLE_TTS_DEFAULT_MODEL') ? GOOGLE_TTS_DEFAULT_MODEL : null)),
                    'prompt' => $voice['prompt'] ?? null,
                    'audioEncoding' => strtoupper($voice['audioEncoding'] ?? $defaultEncoding)
                ];
            }, $googleVoices);
        }

        return [
            'mode' => $mode,
            'voices' => array_values($voices),
            'endpoint' => '/api/text_to_speech.php',
            'defaultAudioEncoding' => $defaultEncoding
        ];
    }
}

if (!function_exists('synthesizeTextToSpeech')) {
    function synthesizeTextToSpeech($text, array $options = []) {
        $text = trim((string) ($text ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Text is required for speech synthesis.');
        }

        $requestedProvider = isset($options['provider']) ? strtolower((string) $options['provider']) : null;
        $provider = null;

        if ($requestedProvider === 'google' && hasGoogleTTSConfig()) {
            $provider = 'google';
        } elseif ($requestedProvider === 'elevenlabs' && hasElevenLabsTTSConfig()) {
            $provider = 'elevenlabs';
        } elseif (hasElevenLabsTTSConfig()) {
            $provider = 'elevenlabs';
        } elseif (hasGoogleTTSConfig()) {
            $provider = 'google';
        }

        if (!$provider) {
            throw new RuntimeException('No text-to-speech provider is configured.');
        }

        if ($provider === 'google') {
            return synthesizeWithGoogleTTS($text, $options);
        }

        return synthesizeWithElevenLabsTTS($text, $options);
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

if (!function_exists('findElevenLabsTTSVoice')) {
    function findElevenLabsTTSVoice($voiceId) {
        if (!$voiceId) {
            return null;
        }

        foreach (getElevenLabsTTSVoices() as $voice) {
            $candidate = $voice['id'] ?? ($voice['voice_id'] ?? ($voice['voiceId'] ?? null));
            if ($candidate === $voiceId) {
                return $voice;
            }
        }

        return null;
    }
}

if (!function_exists('synthesizeWithGoogleTTS')) {
    function synthesizeWithGoogleTTS($text, array $options = []) {
        if (!hasGoogleTTSConfig()) {
            throw new RuntimeException('Google Text-to-Speech is not configured.');
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
            'rawResponse' => $response,
            'provider' => 'google'
        ];
    }
}

if (!function_exists('synthesizeWithElevenLabsTTS')) {
    function synthesizeWithElevenLabsTTS($text, array $options = []) {
        if (!hasElevenLabsTTSConfig()) {
            throw new RuntimeException('ElevenLabs Text-to-Speech is not configured.');
        }

        $voiceId = $options['voiceId'] ?? ($options['voice_id'] ?? ($options['voice'] ?? null));
        $voiceDefinition = $voiceId ? findElevenLabsTTSVoice($voiceId) : null;

        if (!$voiceDefinition) {
            $availableVoices = getElevenLabsTTSVoices();
            if (!empty($availableVoices)) {
                $voiceDefinition = $availableVoices[0];
                $voiceId = $voiceDefinition['id'] ?? ($voiceDefinition['voice_id'] ?? ($voiceDefinition['voiceId'] ?? null));
            }
        }

        if (!$voiceId) {
            throw new InvalidArgumentException('An ElevenLabs voice is required for speech synthesis.');
        }

        $modelId = $options['model'] ?? ($options['model_id'] ?? ($voiceDefinition['model'] ?? ($voiceDefinition['model_id'] ?? (defined('ELEVENLABS_TTS_DEFAULT_MODEL') ? ELEVENLABS_TTS_DEFAULT_MODEL : null))));
        if (!$modelId) {
            throw new InvalidArgumentException('An ElevenLabs model_id is required for speech synthesis.');
        }

        $outputFormat = $options['output_format'] ?? ($voiceDefinition['outputFormat'] ?? ($voiceDefinition['output_format'] ?? (defined('ELEVENLABS_TTS_DEFAULT_OUTPUT_FORMAT') ? ELEVENLABS_TTS_DEFAULT_OUTPUT_FORMAT : 'mp3_44100_128')));
        $voiceSettings = $options['voice_settings'] ?? ($voiceDefinition['voiceSettings'] ?? ($voiceDefinition['voice_settings'] ?? null));

        $payload = [
            'text' => $text,
            'model_id' => $modelId
        ];

        if (is_array($voiceSettings) && !empty($voiceSettings)) {
            $payload['voice_settings'] = $voiceSettings;
        }

        $queryParams = [];
        if (!empty($outputFormat)) {
            $queryParams['output_format'] = $outputFormat;
        }

        $binaryAudio = makeElevenLabsTTSRequest($voiceId, $payload, $queryParams);
        $mimeType = guessMimeTypeFromOutputFormat($outputFormat);

        return [
            'audioContent' => base64_encode($binaryAudio),
            'audioEncoding' => 'MP3',
            'mimeType' => $mimeType,
            'rawResponse' => null,
            'provider' => 'elevenlabs',
            'outputFormat' => $outputFormat
        ];
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

if (!function_exists('makeElevenLabsTTSRequest')) {
    function makeElevenLabsTTSRequest($voiceId, array $payload, array $queryParams = []) {
        if (!hasElevenLabsTTSConfig()) {
            throw new RuntimeException('ElevenLabs Text-to-Speech is not configured.');
        }

        $endpoint = defined('ELEVENLABS_TTS_ENDPOINT') ? ELEVENLABS_TTS_ENDPOINT : 'https://api.elevenlabs.io/v1/text-to-speech';
        $url = rtrim($endpoint, '/') . '/' . rawurlencode($voiceId);

        $queryParams = array_filter($queryParams, function ($value) {
            return $value !== null && $value !== '';
        });
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Content-Type: application/json',
            'xi-api-key: ' . ELEVENLABS_API_KEY
        ];

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
            throw new RuntimeException("ElevenLabs TTS cURL error ({$curlErrno}): {$curlError}");
        }

        if ($httpCode !== 200) {
            $decodedError = json_decode($response, true);
            if (is_array($decodedError)) {
                $message = $decodedError['detail'] ?? ($decodedError['message'] ?? json_encode($decodedError));
            } else {
                $message = $response ? substr($response, 0, 500) : 'No response body';
            }
            throw new RuntimeException("ElevenLabs TTS API error ({$httpCode}): {$message}");
        }

        if ($response === '' || $response === false || $response === null) {
            throw new RuntimeException('ElevenLabs TTS API returned an empty response.');
        }

        return $response;
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

if (!function_exists('guessMimeTypeFromOutputFormat')) {
    function guessMimeTypeFromOutputFormat($format) {
        $format = strtolower((string) $format);
        if (strpos($format, 'wav') !== false || strpos($format, 'pcm') !== false || strpos($format, 'linear16') !== false) {
            return 'audio/wav';
        }
        if (strpos($format, 'ogg') !== false || strpos($format, 'opus') !== false) {
            return 'audio/ogg';
        }
        if (strpos($format, 'aac') !== false || strpos($format, 'm4a') !== false) {
            return 'audio/aac';
        }

        return 'audio/mpeg';
    }
}
