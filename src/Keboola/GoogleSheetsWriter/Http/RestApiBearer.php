<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter\Http;

use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Google\RestApi;

/**
 * Lightweight wrapper to call Google APIs with a pre-built OAuth2 Bearer access token
 * (e.g., from a Service Account), while keeping the original RestApi interface/type.
 */
class RestApiBearer extends RestApi
{
    /**
     * @param string $accessToken Preissued OAuth2 access token (e.g. from a Service Account JWT flow).
     */
    public function __construct(string $accessToken)
    {
        // Parent wants: clientId, clientSecret, accessToken, refreshToken, Logger|null
        // For bearer-only we pass nulls/empty where appropriate and NEVER refresh.
        parent::__construct(null, null, $accessToken, '', null);
    }

    /**
     * Match parent signature/return type exactly to keep tools happy.
     * Parent type is GuzzleHttp\Psr7\Response (not the interface), so we keep that.
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        // Ensure Authorization header is present. Do not clobber if already set.
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }
        if (!isset($options['headers']['Authorization'])) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        return parent::request($method, $url, $options);
    }
}
