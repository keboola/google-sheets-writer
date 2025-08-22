<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

final class RestApiBearer
{
    private string $accessToken;

    /** @var callable|null */
    private $backoff403;

    private int $retries = 3;

    private GuzzleClient $http;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
        $this->http = new GuzzleClient();
    }

    /** @param array<string,string> $headers @param array<string,mixed> $options */
    public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface
    {
        $opts = $options;
        $opts['headers'] = array_merge($headers, [
            'Authorization' => 'Bearer ' . $this->accessToken,
        ]);

        $attempt = 0;

        while (true) {
            try {
                return $this->http->request($method, $uri, $opts);
            } catch (RequestException $e) {
                $resp = $e->getResponse();
                $code = $resp ? $resp->getStatusCode() : 0;

                // Optional: caller-provided 403 backoff decision
                if ($code === 403 && $this->backoff403 !== null) {
                    $retry = (bool) call_user_func($this->backoff403, $resp);
                    if (!$retry) {
                        throw $e;
                    }
                }

                if (in_array($code, [429, 500, 502, 503, 504], true) && $attempt < $this->retries) {
                    usleep((int) (100000 * (2 ** $attempt))); // 0.1s, 0.2s, 0.4s...
                    $attempt++;
                    continue;
                }

                throw $e;
            }
        }
    }

    public function setBackoffsCount(int $count): void
    {
        $this->retries = max(0, $count);
    }

    /** @param callable $callback function(ResponseInterface $response): bool */
    public function setBackoffCallback403(callable $callback): void
    {
        $this->backoff403 = $callback;
    }

    /** Provided for compatibility; not used with bearer tokens. */
    public function setRefreshTokenCallback(callable $callback): void
    {
        // no-op
    }
}
