<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Auth;

use Keboola\GoogleSheetsWriter\Exception\UserException;

class ServiceAccountTokenFactory
{
    /**
     * @param array<string,mixed> $serviceAccount
     * @param list<string> $scopes
     */
    public function getAccessToken(array $serviceAccount, array $scopes): string
    {
        $clientEmail = $this->expectString($serviceAccount['client_email'] ?? null, 'client_email');
        $privateKey  = $this->expectString($serviceAccount['private_key'] ?? null, 'private_key');
        $tokenUri    = $this->expectString($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'token_uri');

        $now = time();
        $jwtHeader   = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtPayload  = $this->b64(json_encode([
            'iss'   => $clientEmail,
            'scope' => implode(' ', $scopes),
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = $jwtHeader . '.' . $jwtPayload;
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new UserException('Failed to sign JWT with the provided private key.');
        }
        $jwt = $signingInput . '.' . $this->b64($signature);

        // Exchange JWT for access token
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init($tokenUri);
        if ($ch === false) {
            throw new UserException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
        ]);

        /** @var string|false $resp */
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new UserException('Failed to obtain access token: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var array<string,mixed>|null $json */
        $json = json_decode($resp, true);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new UserException('Failed to obtain access token: HTTP ' . $status . ' ' . $resp);
        }

        $accessToken = $json['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new UserException('Access token not found in response.');
        }

        return $accessToken;
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param mixed $v */
    private function expectString($v, string $field): string
    {
        if (!is_string($v) || $v === '') {
            throw new UserException(sprintf('Invalid or missing "%s" in service account JSON.', $field));
        }
        return $v;
    }
}
