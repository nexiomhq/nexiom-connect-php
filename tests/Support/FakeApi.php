<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests\Support;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\NexiomConnect;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * An SDK wired to an in-process Guzzle handler that records every request.
 */
final class FakeApi
{
    public const API_KEY = 'nc_test_key';

    public const ACCEPTED = [
        'messageId' => 'msg_1',
        'totalQueued' => 1,
        'deliveryIds' => ['del_1'],
        'scheduledAt' => null,
    ];

    public readonly NexiomConnect $sdk;

    /** @var list<RecordedCall> */
    public array $calls = [];

    /** @var Closure(RecordedCall, int): Response */
    private readonly Closure $handler;

    /**
     * @param (Closure(RecordedCall, int): Response)|null $handler Answers the nth call (1-based).
     * @param array<string, mixed> $options NexiomConnect constructor arguments.
     */
    public function __construct(?Closure $handler = null, array $options = [])
    {
        $this->handler = $handler ?? static fn (): Response => self::json(['data' => self::ACCEPTED]);

        /** @phpstan-ignore argument.type (named constructor arguments from the test) */
        $this->sdk = new NexiomConnect(...[
            'apiKey' => self::API_KEY,
            'maxRetries' => 0,
            ...$options,
            'httpClient' => new Client(['handler' => HandlerStack::create($this->dispatch(...))]),
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $value, int $status = 200, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Records the request and answers it with the test's handler.
     *
     * @param array<mixed> $options
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function dispatch(RequestInterface $request, array $options): PromiseInterface
    {
        $call = new RecordedCall($request, $options);
        $this->calls[] = $call;

        // Guzzle's promise templates are invariant, so a concrete promise never matches the handler type.
        try {
            /** @phpstan-ignore return.type */
            return new FulfilledPromise(($this->handler)($call, count($this->calls)));
        } catch (Throwable $error) {
            /** @phpstan-ignore return.type */
            return new RejectedPromise($error);
        }
    }

    public function call(int $index): RecordedCall
    {
        return $this->calls[$index];
    }
}
