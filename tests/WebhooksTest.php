<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use Closure;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Exceptions\NexiomWebhookVerificationException;
use Nexiom\Connect\NexiomConnect;
use Nexiom\Connect\Services\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_value';

    private const EVENT = [
        'id' => '00000000-0000-4000-8000-000000000001',
        'type' => 'email.delivered',
        'schema_version' => 1,
        'data' => [
            'id' => 'delivery_1',
            'message_id' => 'message_1',
            'recipient' => 'reader@example.com',
            'occurred_at' => '2026-10-14T10:00:00.000Z',
        ],
        'created_at' => '2026-10-14T10:00:00.000Z',
    ];

    private Webhooks $webhooks;

    protected function setUp(): void
    {
        $this->webhooks = (new NexiomConnect('nc_test_key'))->webhooks;
    }

    /**
     * Signs like the backend: HMAC-SHA256 of "{timestamp}.{payload}" keyed with the full secret.
     *
     * @return array<string, string>
     */
    private static function sign(string $payload, ?int $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp ??= time();

        return [
            'x-nexiom-timestamp' => (string) $timestamp,
            'x-nexiom-signature' => hash_hmac('sha256', "{$timestamp}.{$payload}", $secret),
        ];
    }

    private static function payload(): string
    {
        return json_encode(self::EVENT, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param Closure(): mixed $verify
     */
    private static function reasonOf(Closure $verify): string
    {
        try {
            $verify();
        } catch (NexiomWebhookVerificationException $error) {
            return $error->reason;
        }

        self::fail('constructEvent accepted a request it should refuse');
    }

    public function testSignedRequestsReturnTheirEvent(): void
    {
        $payload = self::payload();

        self::assertSame(self::EVENT, $this->webhooks->constructEvent($payload, self::sign($payload), self::SECRET));

        // Event types the SDK does not know yet are still verified and returned.
        $future = json_encode([...self::EVENT, 'type' => 'email.future_event'], JSON_THROW_ON_ERROR);
        self::assertSame(
            'email.future_event',
            $this->webhooks->constructEvent($future, self::sign($future), self::SECRET)['type'],
        );

        // Unicode bodies and long secrets sign like any other.
        $unicode = json_encode([...self::EVENT, 'data' => ['recipient' => 'مرحبا 👋']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $longSecret = 'whsec_' . str_repeat('k', 200);
        self::assertSame(
            ['recipient' => 'مرحبا 👋'],
            $this->webhooks->constructEvent($unicode, self::sign($unicode, secret: $longSecret), $longSecret)['data'],
        );
    }

    public function testHeadersAreReadCaseInsensitivelyFromStringsAndLists(): void
    {
        $payload = self::payload();
        $signed = self::sign($payload);

        // getallheaders() keeps the sender's case; Laravel's $request->headers->all() returns lists.
        $variants = [
            ['X-Nexiom-Timestamp' => $signed['x-nexiom-timestamp'], 'X-Nexiom-Signature' => $signed['x-nexiom-signature']],
            ['x-nexiom-timestamp' => [$signed['x-nexiom-timestamp']], 'x-nexiom-signature' => [$signed['x-nexiom-signature']]],
            ['X-NEXIOM-TIMESTAMP' => $signed['x-nexiom-timestamp'], 'x-nexiom-signature' => strtoupper($signed['x-nexiom-signature']), 'content-type' => ['application/json']],
        ];

        foreach ($variants as $headers) {
            self::assertSame(self::EVENT['id'], $this->webhooks->constructEvent($payload, $headers, self::SECRET)['id']);
        }
    }

    public function testMissingOrMalformedHeadersAreRefused(): void
    {
        $payload = self::payload();
        $signed = self::sign($payload);

        foreach ([
            [],
            ['x-nexiom-timestamp' => $signed['x-nexiom-timestamp']],
            ['x-nexiom-signature' => $signed['x-nexiom-signature']],
            [...$signed, 'x-nexiom-signature' => '  '],
            [...$signed, 'x-nexiom-timestamp' => []],
        ] as $headers) {
            self::assertSame(
                NexiomWebhookVerificationException::MISSING_HEADERS,
                self::reasonOf(fn () => $this->webhooks->constructEvent($payload, $headers, self::SECRET)),
            );
        }

        foreach (['1e9', '-10', '12.5', 'soon', '99999999999999999999'] as $timestamp) {
            self::assertSame(
                NexiomWebhookVerificationException::INVALID_TIMESTAMP,
                self::reasonOf(fn () => $this->webhooks->constructEvent(
                    $payload,
                    [...$signed, 'x-nexiom-timestamp' => $timestamp],
                    self::SECRET,
                )),
                $timestamp,
            );
        }
    }

    public function testTimestampsOutsideTheToleranceAreRefused(): void
    {
        $payload = self::payload();

        foreach ([-301, 301] as $offset) {
            self::assertSame(
                NexiomWebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
                self::reasonOf(fn () => $this->webhooks->constructEvent($payload, self::sign($payload, time() + $offset), self::SECRET)),
            );
        }

        self::assertSame(
            self::EVENT['id'],
            $this->webhooks->constructEvent($payload, self::sign($payload, time() - 280), self::SECRET)['id'],
        );

        $older = self::sign($payload, time() - 500);
        self::assertSame(self::EVENT['id'], $this->webhooks->constructEvent($payload, $older, self::SECRET, tolerance: 600)['id']);
        self::assertSame(
            NexiomWebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
            self::reasonOf(fn () => $this->webhooks->constructEvent($payload, $older, self::SECRET, tolerance: 60)),
        );
    }

    public function testChangedRequestsAreRefused(): void
    {
        $payload = self::payload();
        $headers = self::sign($payload);

        $cases = [
            'pretty-printed JSON' => fn () => $this->webhooks->constructEvent(
                json_encode(self::EVENT, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $headers,
                self::SECRET,
            ),
            'changed body' => fn () => $this->webhooks->constructEvent(str_replace('delivered', 'bounced', $payload), $headers, self::SECRET),
            'changed timestamp' => fn () => $this->webhooks->constructEvent(
                $payload,
                [...$headers, 'x-nexiom-timestamp' => (string) ((int) $headers['x-nexiom-timestamp'] - 1)],
                self::SECRET,
            ),
            'other secret' => fn () => $this->webhooks->constructEvent($payload, $headers, 'whsec_another_secret'),
            'secret without prefix' => fn () => $this->webhooks->constructEvent($payload, $headers, 'test_secret_value'),
            'zero signature' => fn () => $this->webhooks->constructEvent($payload, [...$headers, 'x-nexiom-signature' => str_repeat('0', 64)], self::SECRET),
            'not hex' => fn () => $this->webhooks->constructEvent($payload, [...$headers, 'x-nexiom-signature' => 'not-hex'], self::SECRET),
            'short signature' => fn () => $this->webhooks->constructEvent(
                $payload,
                [...$headers, 'x-nexiom-signature' => substr($headers['x-nexiom-signature'], 0, 62)],
                self::SECRET,
            ),
        ];

        foreach ($cases as $case => $verify) {
            self::assertSame(NexiomWebhookVerificationException::INVALID_SIGNATURE, self::reasonOf($verify), $case);
        }
    }

    public function testSignedBodiesThatAreNotEventsAreRefused(): void
    {
        $bodies = [
            'not json',
            '[]',
            json_encode([...self::EVENT, 'id' => 1], JSON_THROW_ON_ERROR),
            json_encode([...self::EVENT, 'schema_version' => '1'], JSON_THROW_ON_ERROR),
            json_encode([...self::EVENT, 'data' => null], JSON_THROW_ON_ERROR),
            json_encode([...self::EVENT, 'data' => []], JSON_THROW_ON_ERROR),
            json_encode(['id' => self::EVENT['id'], 'type' => self::EVENT['type'], 'data' => self::EVENT['data']], JSON_THROW_ON_ERROR),
            "{\"id\":\"\xff\"}",
        ];

        foreach ($bodies as $payload) {
            self::assertSame(
                NexiomWebhookVerificationException::INVALID_PAYLOAD,
                self::reasonOf(fn () => $this->webhooks->constructEvent($payload, self::sign($payload), self::SECRET)),
                $payload,
            );
        }
    }

    public function testInvalidArgumentsAreProgrammingErrors(): void
    {
        $payload = self::payload();
        $headers = self::sign($payload);

        foreach ([
            'secret' => fn () => $this->webhooks->constructEvent($payload, $headers, ''),
            'tolerance' => fn () => $this->webhooks->constructEvent($payload, $headers, self::SECRET, tolerance: 0),
        ] as $argument => $call) {
            try {
                $call();
                self::fail("An invalid {$argument} was accepted");
            } catch (NexiomValidationException $error) {
                self::assertStringContainsString($argument, $error->getMessage());
            }
        }
    }
}
