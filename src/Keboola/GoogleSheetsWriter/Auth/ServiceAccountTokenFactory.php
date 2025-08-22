<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Auth;

use GuzzleHttp\Client as GuzzleClient;
use Keboola\GoogleSheetsWriter\Exception\UserException;

final class ServiceAccountTokenFactory
{
    /** @param array<string, mixed> $serviceAccount */
    public function getAccessToken(array $serviceAccount, array $scopes): string
    {
        $this->assertSa($serviceAccount);

        $aud = isset($serviceAccount['token_uri']) && is_string($serviceAccount['token_uri'])
            ? $serviceAccount['token_uri']
            : 'https://oauth2.googleapis.com/token';

        $now = time();
        $jwtHeader  = ['alg' => 'RS256', 'typ' => 'JWT'];
        $jwtClaims  = [
            'iss'   => (string) $serviceAccount['client_email'],
            'scope' => implode(' ', $scopes),
            'aud'   => $aud,
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $jwt = $this->encodeJwt($jwtHeader, $jwtClaims, (string) $serviceAccount['private_key']);

        $http = new GuzzleClient();
        $resp = $http->post($aud, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            // phpcs:ignore SlevomatCodingStandard.Functions.TrailingCommaInCall
        ]);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) $resp->getBody(), true) ?: [];
        if (empty($json['access_token'])) {
            throw new UserException('Service Account token exchange failed: missing access_token in response.');
        }

        return (string) $json['access_token'];
    }

    /** @param array<string, mixed> $sa */
    private function assertSa(array $sa): void
    {
        foreach (['client_email', 'private_key'] as $k) {
            if (empty($sa[$k]) || !is_string($sa[$k])) {
                throw new UserException(sprintf('Invalid Service Account JSON: missing "%s".', $k));
            }
        }
    }

    /** @param array<string, mixed> $header @param array<string, mixed> $claims */
    private function encodeJwt(array $header, array $claims, string $privateKey): string
    {
        $h = $this->b64(json_encode($header) ?: '');
        $c = $this->b64(json_encode($claims) ?: '');
        $signingInput = $h . '.' . $c;

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new UserException('Failed to sign JWT with provided private_key.');
        }

        return $signingInput . '.' . $this->b64($signature);
    }

    private function b64(string $raw): string
    {
        $enc = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return $enc;
    }
}
