<?php

declare(strict_types=1);

namespace Nexiom\Connect\Internal;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use JsonException;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\NexiomConnect;
use Nexiom\Connect\ResponseMetadata;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;

/**
 * Shared HTTP transport: authentication, retries, deadlines, and response parsing.
 *
 * @internal
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api-connect.nxiom.com/api';

    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];

    /** Seconds; matches the Node.js SDK's 2^31 - 1 millisecond limit. */
    private const MAX_TIMEOUT = 2147483.647;

    private const MAX_RETRIES = 10;

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeout;

    private readonly int $maxRetries;

    private readonly ClientInterface $http;

    public function __construct(
        #[SensitiveParameter] string $apiKey,
        ?string $baseUrl,
        float|int $timeout,
        int $maxRetries,
        ?ClientInterface $http,
    ) {
        if (preg_match('/^[\x21-\x7e]+$/', $apiKey) !== 1) {
            throw new NexiomValidationException(
                'apiKey must be a non-empty string of printable ASCII without whitespace',
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = self::baseUrl($baseUrl ?? self::DEFAULT_BASE_URL);
        $this->timeout = self::timeout($timeout);
        $this->maxRetries = self::maxRetries($maxRetries);
        $this->http = $http ?? new Client();
    }

    /**
     * Keeps the API key out of var_dump() and print_r() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /**
     * Sends a request and returns the decoded payload.
     *
     * @param array<string, mixed>|null $body
     * @param array<mixed> $options Per-request `timeout` and `maxRetries`.
     * @param bool|null $retryable Safe to repeat. Default: GET requests and requests with an idempotency key.
     * @param bool $envelope False for endpoints that return the resource without a `data` wrapper.
     * @param (Closure(array<mixed>): bool)|null $accepts Checks the shape of a successful payload.
     * @return array<mixed>
     *
     * @throws NexiomException
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $options = [],
        ?string $idempotencyKey = null,
        ?bool $retryable = null,
        bool $envelope = true,
        ?Closure $accepts = null,
    ): array {
        Validate::keys($options, ['timeout', 'maxRetries'], 'request option');

        $timeout = self::timeout($options['timeout'] ?? $this->timeout);
        $maxRetries = self::maxRetries($options['maxRetries'] ?? $this->maxRetries);
        $serialized = $body === null ? null : self::json($body);

        // Authorization is added per attempt, so no stack frame argument holds the API key.
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'nexiom-connect-php/' . NexiomConnect::VERSION,
        ];

        if ($serialized !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $deadline = hrtime(true) + (int) round($timeout * 1e9);
        $retryable ??= $method === 'GET' || $idempotencyKey !== null;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->attempt(
                    $method,
                    $path,
                    $headers,
                    $serialized,
                    $deadline,
                    $idempotencyKey,
                    $envelope,
                    $accepts,
                );
            } catch (NexiomException $error) {
                if (!$retryable || $attempt >= $maxRetries || !self::isRetryable($error)) {
                    throw $error;
                }

                // A wait that cannot finish before the deadline would only turn this error into a timeout.
                $delay = self::retryDelay($error->response->headers, $attempt);
                if ($delay >= self::remaining($deadline)) {
                    throw $error;
                }

                usleep((int) round($delay * 1e6));
            }
        }
    }

    /**
     * @param array<string, string> $headers
     * @param (Closure(array<mixed>): bool)|null $accepts
     * @return array<mixed>
     *
     * @throws NexiomException
     */
    private function attempt(
        string $method,
        string $path,
        array $headers,
        ?string $body,
        int $deadline,
        ?string $idempotencyKey,
        bool $envelope,
        ?Closure $accepts,
    ): array {
        $remaining = self::remaining($deadline);

        if ($remaining <= 0) {
            throw new NexiomException(
                'Request timed out',
                ErrorKind::Timeout,
                new ResponseMetadata(idempotencyKey: $idempotencyKey),
            );
        }

        // Guzzle treats 0 as "no timeout", so never round the remaining time down to it.
        $seconds = max(0.001, round($remaining, 3));

        $options = [
            'headers' => ['Authorization' => "Bearer {$this->apiKey}", ...$headers],
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $seconds,
            'connect_timeout' => $seconds,
        ];

        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $raw = $this->http->request($method, $this->baseUrl . $path, $options);
            $text = (string) $raw->getBody();
        } catch (GuzzleException $error) {
            // The transport exception is not retained: it holds the request and its Authorization header.
            $metadata = new ResponseMetadata(idempotencyKey: $idempotencyKey);

            if (self::isTimeout($error, $deadline)) {
                throw new NexiomException('Request timed out', ErrorKind::Timeout, $metadata);
            }

            throw new NexiomException('Unable to complete the API request', ErrorKind::Network, $metadata);
        }

        return self::parse($raw, $text, $idempotencyKey, $envelope, $accepts);
    }

    /**
     * @param (Closure(array<mixed>): bool)|null $accepts
     * @return array<mixed>
     *
     * @throws NexiomException
     */
    private static function parse(
        ResponseInterface $raw,
        string $text,
        ?string $idempotencyKey,
        bool $envelope,
        ?Closure $accepts,
    ): array {
        $status = $raw->getStatusCode();

        $headers = [];
        foreach ($raw->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values($values);
        }

        try {
            $payload = $text === '' ? null : json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }

        /** @var array<mixed> $fields */
        $fields = $payload instanceof stdClass ? json_decode($text, true) : [];

        $requestId = $headers['x-request-id'][0] ?? $headers['x-correlation-id'][0] ?? null;
        if (is_string($fields['correlationId'] ?? null)) {
            $requestId = $fields['correlationId'];
        }

        $metadata = new ResponseMetadata($status, $headers, $requestId, $idempotencyKey);

        if ($status >= 200 && $status < 300) {
            if (!self::isValidSuccess($payload, $envelope)) {
                throw new NexiomException(
                    'API returned an empty or invalid JSON response',
                    ErrorKind::Protocol,
                    $metadata,
                );
            }

            // Resource endpoints return { data }, deletes return { success }, and a few
            // detail endpoints return the resource itself.
            /** @var array<mixed> $data */
            $data = $envelope && array_key_exists('data', $fields) ? $fields['data'] : $fields;

            if ($accepts !== null && !$accepts($data)) {
                throw new NexiomException('API returned an unexpected response', ErrorKind::Protocol, $metadata);
            }

            return $data;
        }

        $message = is_string($fields['message'] ?? null)
            ? $fields['message']
            : "Request failed with HTTP {$status}";

        $code = null;
        if (is_string($fields['code'] ?? null)) {
            $code = $fields['code'];
        } elseif (is_string($fields['error'] ?? null)) {
            $code = $fields['error'];
        }

        throw new NexiomException($message, ErrorKind::Api, $metadata, $code, $fields['details'] ?? null);
    }

    private static function isValidSuccess(mixed $payload, bool $envelope): bool
    {
        if (!$payload instanceof stdClass) {
            return false;
        }

        if (!$envelope) {
            return true;
        }

        if (property_exists($payload, 'data')) {
            return is_object($payload->data) || is_array($payload->data);
        }

        return property_exists($payload, 'success') && is_bool($payload->success);
    }

    private static function isRetryable(NexiomException $error): bool
    {
        return $error->kind === ErrorKind::Network
            || ($error->kind === ErrorKind::Api && in_array($error->status, self::RETRY_STATUSES, true));
    }

    private static function isTimeout(GuzzleException $error, int $deadline): bool
    {
        if (self::remaining($deadline) <= 0.005) {
            return true;
        }

        // Guzzle 8 timeout classes.
        if (
            $error instanceof ConnectTimeoutException
            || $error instanceof NetworkTimeoutException
            || $error instanceof ResponseTimeoutException
        ) {
            return true;
        }

        // Guzzle 7 reports cURL's CURLE_OPERATION_TIMEDOUT in the handler context.
        if (method_exists($error, 'getHandlerContext')) {
            $context = $error->getHandlerContext();

            return is_array($context) && ($context['errno'] ?? null) === 28;
        }

        return false;
    }

    /**
     * Seconds to wait before the next attempt, from Retry-After or exponential backoff with jitter.
     *
     * @param array<string, list<string>> $headers
     */
    private static function retryDelay(array $headers, int $attempt): float
    {
        $value = trim($headers['retry-after'][0] ?? '');

        if ($value !== '') {
            $delay = null;

            if (is_numeric($value)) {
                $delay = (float) $value;
            } else {
                $time = strtotime($value);
                if ($time !== false) {
                    $delay = $time - microtime(true);
                }
            }

            if ($delay !== null && is_finite($delay)) {
                return min(self::MAX_TIMEOUT, max(0.0, $delay));
            }
        }

        $jitter = 0.75 + (mt_rand() / mt_getrandmax()) * 0.25;

        return min(8.0, 0.5 * 2 ** $attempt) * $jitter;
    }

    /**
     * Seconds left before the deadline, from the monotonic clock.
     */
    private static function remaining(int $deadline): float
    {
        return ($deadline - hrtime(true)) / 1e9;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body): string
    {
        try {
            return json_encode($body, self::JSON_FLAGS);
        } catch (JsonException) {
            throw new NexiomValidationException('Request body must be JSON serializable');
        }
    }

    private static function timeout(mixed $value): float
    {
        if (
            (!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
            || $value < 0.001
            || $value > self::MAX_TIMEOUT
        ) {
            throw new NexiomValidationException(
                'timeout must be a number of seconds between 0.001 and ' . self::MAX_TIMEOUT,
            );
        }

        return (float) $value;
    }

    private static function maxRetries(mixed $value): int
    {
        return Validate::integer($value, 'maxRetries', 0, self::MAX_RETRIES);
    }

    private static function baseUrl(string $value): string
    {
        $parts = preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1 ? false : parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new NexiomValidationException('baseUrl must be an absolute HTTP(S) URL');
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($value, '?')
            || str_contains($value, '#')
        ) {
            throw new NexiomValidationException(
                'baseUrl must be an HTTP(S) URL without credentials, query, or fragment',
            );
        }

        if ($scheme === 'http' && !in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw new NexiomValidationException('baseUrl must use HTTPS except on loopback hosts');
        }

        return rtrim($value, '/');
    }
}
