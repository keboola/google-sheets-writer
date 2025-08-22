<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter\Auth;

use RuntimeException;

class ServiceAccountTokenFactory
{
    /** @var array<string,mixed> */
    private array $sa;

    /** @param array<string,mixed> $serviceAccountJson */
    public function __construct(array $serviceAccountJson)
    {
        $this->sa = $serviceAccountJson;
    }

    public function createAccessToken(): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => (string) $this->sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
            'aud' => (string) $this->sa['token_uri'],
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $headerB64  = $this->b64(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = $this->b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;

        $privateKey = (string) $this->sa['private_key'];
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, 'sha256')) {
            throw new RuntimeException('Failed to sign JWT for service account.');
        }
        $jwt = $signingInput . '.' . $this->b64($signature);

        // Exchange JWT for access token (simple stream-context HTTP POST)
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ], '', '&');

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post,
                'timeout' => 30,
            ],
        ]);

        $resp = file_get_contents((string) $this->sa['token_uri'], false, $ctx);
        if ($resp === false) {
            throw new RuntimeException('Failed to obtain access token from Google OAuth.');
        }

        /** @var array{access_token?: string} $json */
        $json = (array) json_decode($resp, true);
        if (empty($json['access_token'])) {
            throw new RuntimeException('OAuth response does not contain access_token.');
        }

        return (string) $json['access_token'];
    }

    private function b64(string $raw): string
    {
        $b64 = base64_encode($raw);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }
}
