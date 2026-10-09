<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests\Support;

use Psr\Http\Message\RequestInterface;

/**
 * One request the SDK sent, as it reached the HTTP handler.
 */
final class RecordedCall
{
    public readonly string $method;

    public readonly string $url;

    public readonly string $path;

    /** @var array<string, string> */
    public readonly array $query;

    /** @var array<string, string> Header values by lowercase name. */
    public readonly array $headers;

    /** Raw request body, or null when none was sent. */
    public readonly ?string $rawBody;

    /** Decoded JSON body as nested arrays and stdClass objects. */
    public readonly mixed $body;

    /**
     * @param array<mixed> $options Guzzle request options.
     */
    public function __construct(public readonly RequestInterface $request, public readonly array $options)
    {
        $this->method = $request->getMethod();
        $this->url = (string) $request->getUri();
        $this->path = $request->getUri()->getPath();

        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, string> $query */
        $this->query = $query;

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $this->headers = $headers;

        $raw = (string) $request->getBody();
        $this->rawBody = $raw === '' ? null : $raw;
        $this->body = $raw === '' ? null : json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The decoded body as an associative array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        /** @var array<string, mixed> */
        return $this->rawBody === null ? [] : json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
