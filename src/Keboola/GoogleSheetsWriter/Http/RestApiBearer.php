<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter\Http;

use Keboola\Google\ClientBundle\Google\RestApi;
use Monolog\Logger;
use GuzzleHttp\Psr7\Response;

class RestApiBearer extends RestApi
{
    public function __construct(string $accessToken, ?Logger $logger = null)
    {
        // Parent expects: clientId, clientSecret, accessToken, refreshToken, logger
        parent::__construct('', '', $accessToken, '', $logger);
    }

    /**
     * Keep the exact signature as the parent to avoid compatibility errors.
     */
    public function request(
        string $url,
        string $method = 'GET',
        array $addHeaders = [],
        array $options = []
    ): Response {
        // Inject Bearer token; allow caller to override if they explicitly pass Authorization
        $headers = array_merge(['Authorization' => 'Bearer ' . $this->accessToken], $addHeaders);
        return parent::request($url, $method, $headers, $options);
    }
}
