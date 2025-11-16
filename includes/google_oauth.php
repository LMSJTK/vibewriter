<?php
/**
 * Simple helper to mint Google OAuth access tokens for service accounts.
 */

require_once __DIR__ . '/../config/config.php';

function google_has_service_account_credentials() {
    return defined('GOOGLE_SERVICE_ACCOUNT_EMAIL') && GOOGLE_SERVICE_ACCOUNT_EMAIL !== ''
        && defined('GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY') && GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY !== '';
}

function google_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function google_normalize_private_key($key) {
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $key = str_replace(["\r"], '', $key);
    $key = str_replace(['\\n'], "\n", $key);

    if (strpos($key, 'BEGIN PRIVATE KEY') !== false) {
        return $key;
    }

    $clean = preg_replace('/[^A-Za-z0-9\+\/\=]/', '', $key);
    $wrapped = trim(chunk_split($clean, 64, "\n"));

    return "-----BEGIN PRIVATE KEY-----\n" . $wrapped . "\n-----END PRIVATE KEY-----";
}

function get_google_service_account_token($scopes = null, $cacheKey = 'default', $forceRefresh = false) {
    static $tokenCache = [];

    if (!google_has_service_account_credentials()) {
        return ['success' => false, 'message' => 'Google service account credentials are not configured'];
    }

    if ($scopes === null) {
        $scopes = defined('GOOGLE_DEFAULT_OAUTH_SCOPES') ? GOOGLE_DEFAULT_OAUTH_SCOPES : ['https://www.googleapis.com/auth/cloud-platform'];
    }

    if (!is_array($scopes)) {
        $scopes = [$scopes];
    }

    sort($scopes);
    $scopeString = implode(' ', $scopes);
    $cacheKey = $cacheKey ?: md5($scopeString);

    if (!$forceRefresh && isset($tokenCache[$cacheKey])) {
        $cached = $tokenCache[$cacheKey];
        if ($cached['expires_at'] > (time() + 60)) {
            return ['success' => true, 'token' => $cached['token']];
        }
    }

    $tokenUri = defined('GOOGLE_OAUTH_TOKEN_URI') && GOOGLE_OAUTH_TOKEN_URI !== ''
        ? GOOGLE_OAUTH_TOKEN_URI
        : 'https://oauth2.googleapis.com/token';

    $now = time();
    $claims = [
        'iss' => GOOGLE_SERVICE_ACCOUNT_EMAIL,
        'scope' => $scopeString,
        'aud' => $tokenUri,
        'exp' => $now + 3600,
        'iat' => $now,
    ];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $signingInput = google_base64url_encode(json_encode($header)) . '.' . google_base64url_encode(json_encode($claims));
    $privateKey = google_normalize_private_key(GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY);

    $signature = '';
    $signed = openssl_sign($signingInput, $signature, $privateKey, 'sha256WithRSAEncryption');

    if (!$signed) {
        return ['success' => false, 'message' => 'Failed to sign Google service account JWT'];
    }

    $assertion = $signingInput . '.' . google_base64url_encode($signature);

    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ]);

    $ch = curl_init($tokenUri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'message' => 'Google OAuth token request failed with status code ' . $httpCode,
            'response' => $response,
        ];
    }

    $result = json_decode($response, true);
    if (!isset($result['access_token'])) {
        return ['success' => false, 'message' => 'Google OAuth response missing access token', 'response' => $result];
    }

    $tokenCache[$cacheKey] = [
        'token' => $result['access_token'],
        'expires_at' => $now + ($result['expires_in'] ?? 3600),
    ];

    return ['success' => true, 'token' => $result['access_token']];
}
