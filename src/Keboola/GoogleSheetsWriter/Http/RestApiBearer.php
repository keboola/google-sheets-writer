<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter\Http;

use Keboola\Google\ClientBundle\Google\RestApi;
use Psr\Http\Message\ResponseInterface;

class RestApiBearer extends RestApi
{
    private string $accessToken;

    public function __construct(string $accessToken, ?\Psr\Log\LoggerInterface $logger = null)
    {
        // Parent expects clientId/secret/tokens; we bypass those and only reuse its request machinery.
        parent::__construct('', '', '', '', $logger);
        $this->accessToken = $accessToken;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers
     * @param array<string,mixed> $options
     */
    public function request(string $method, string $url, array $headers = [], array $options = []): ResponseInterface
    {
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        return parent::request($method, $url, $headers, $options);
    }
}
