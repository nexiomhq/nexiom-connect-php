<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private const MAIL = [
        'from' => 'hello@example.com',
        'to' => 'user@example.com',
        'subject' => 'Hello',
        'html' => '<p>Hello</p>',
    ];

    /**
     * @return iterable<string, array{int}>
     */
    public static function clientErrors(): iterable
    {
        foreach ([400, 401, 403, 404, 409, 422] as $status) {
            yield "HTTP {$status}" => [$status];
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            yield "HTTP {$status}" => [$status];
        }
    }

    #[DataProvider('clientErrors')]
    public function testClientErrorsAreNotRetried(int $status): void
    {
        $api = new FakeApi(
            static fn (): Response => FakeApi::json(
                [
                    'error' => 'invalid_argument',
                    'code' => 'specific_code',
                    'message' => 'Helpful message',
                    'correlationId' => 'req_1',
                    'details' => ['field' => 'email'],
                ],
                $status,
            ),
            ['maxRetries' => 2],
        );

        $error = self::catch(fn () => $api->sdk->emails->send(self::MAIL));

        self::assertSame(ErrorKind::Api, $error->kind);
        self::assertSame($status, $error->status);
        self::assertSame($status, $error->getCode());
        self::assertSame('Helpful message', $error->getMessage());
        self::assertSame('specific_code', $error->errorCode);
        self::assertSame('req_1', $error->requestId);
        self::assertSame(['field' => 'email'], $error->details);
        self::assertSame('req_1', $error->response->requestId);
        self::assertSame($api->call(0)->header('idempotency-key'), $error->response->idempotencyKey);
        self::assertCount(1, $api->calls);
    }

    public function testErrorCodeFallsBackToTheErrorField(): void
    {
        $api = new FakeApi(static fn (): Response => FakeApi::json(['error' => 'rate_limited'], 429));

        $error = self::catch(fn () => $api->sdk->contacts->list());

        self::assertSame('rate_limited', $error->errorCode);
        self::assertSame('Request failed with HTTP 429', $error->getMessage());
    }

    #[DataProvider('retryableStatuses')]
    public function testEmailSendRetriesWithSameKey(int $status): void
    {
        $api = new FakeApi(
            static fn (RecordedCall $call, int $n): Response => $n === 1
                ? FakeApi::json(['message' => 'Try later'], $status, ['Retry-After' => '0'])
                : FakeApi::json(['data' => FakeApi::ACCEPTED], 202),
            ['maxRetries' => 2],
        );

        self::assertSame(FakeApi::ACCEPTED, $api->sdk->emails->send(self::MAIL));
        self::assertCount(2, $api->calls);
        self::assertSame($api->call(0)->header('idempotency-key'), $api->call(1)->header('idempotency-key'));
        self::assertSame($api->call(0)->rawBody, $api->call(1)->rawBody);
    }

    public function testReadsRetryBoundedTimes(): void
    {
        $api = new FakeApi(
            static fn (): Response => FakeApi::json(['message' => 'Unavailable'], 503, ['Retry-After' => '0']),
            ['maxRetries' => 2],
        );

        self::assertSame(503, self::catch(fn () => $api->sdk->contacts->list())->status);
        self::assertCount(3, $api->calls);
    }

    public function testMutationsNeverRetry(): void
    {
        $api = new FakeApi(
            static fn (): Response => FakeApi::json([], 503, ['Retry-After' => '0']),
            ['maxRetries' => 10],
        );

        $sdk = $api->sdk;

        $actions = [
            fn () => $sdk->contacts->create(['email' => 'user@example.com']),
            fn () => $sdk->contacts->update('ct_1', ['email' => 'user@example.com']),
            fn () => $sdk->contacts->delete('ct_1'),
            fn () => $sdk->contacts->properties->create(['name' => 'company', 'type' => 'string']),
            fn () => $sdk->contacts->properties->update('prop_1', ['fallbackValue' => null]),
            fn () => $sdk->contacts->properties->delete('prop_1'),
            fn () => $sdk->emails->reschedule('msg_1', ['scheduledAt' => '2026-10-06T09:00:00+01:00']),
        ];

        foreach ($actions as $action) {
            self::assertSame(503, self::catch($action)->status);
        }

        self::assertCount(7, $api->calls);
    }

    public function testNetworkFailureIsRedacted(): void
    {
        $flaky = new FakeApi(
            static function (RecordedCall $call, int $n): Response {
                if ($n === 1) {
                    throw new ConnectException('secret nc_test_key', $call->request);
                }

                return FakeApi::json(['data' => FakeApi::ACCEPTED]);
            },
            ['maxRetries' => 1],
        );

        self::assertSame(FakeApi::ACCEPTED, $flaky->sdk->emails->send(self::MAIL));
        self::assertCount(2, $flaky->calls);

        $failing = new FakeApi(static function (RecordedCall $call): Response {
            throw new ConnectException('secret nc_test_key', $call->request);
        });

        $error = self::catch(fn () => $failing->sdk->contacts->create(['email' => 'user@example.com']));

        self::assertSame(ErrorKind::Network, $error->kind);
        self::assertNull($error->status);
        self::assertNull($error->getPrevious());
        self::assertStringNotContainsString('nc_test_key', (string) $error);
    }

    public function testStackTraceArgumentsNeverHoldTheApiKey(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            foreach ([503, 'network'] as $failure) {
                $api = new FakeApi(static function (RecordedCall $call) use ($failure): Response {
                    if ($failure === 'network') {
                        throw new ConnectException('connection lost', $call->request);
                    }

                    return FakeApi::json([], $failure);
                });

                $error = self::catch(fn () => $api->sdk->emails->send(self::MAIL));

                // Test frames hold the recorded requests; only the SDK's own frames matter.
                foreach ($error->getTrace() as $frame) {
                    if (str_starts_with($frame['class'] ?? '', 'Nexiom\\Connect\\Tests\\')) {
                        continue;
                    }

                    self::assertStringNotContainsString('nc_test_key', print_r($frame['args'] ?? [], true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testNetworkFailureClearsStaleMetadata(): void
    {
        $api = new FakeApi(
            static function (RecordedCall $call, int $n): Response {
                if ($n === 1) {
                    return FakeApi::json([], 503, ['Retry-After' => '0', 'X-Request-Id' => 'old']);
                }

                throw new ConnectException('connection lost', $call->request);
            },
            ['maxRetries' => 1],
        );

        $error = self::catch(fn () => $api->sdk->contacts->list());

        self::assertSame(ErrorKind::Network, $error->kind);
        self::assertNull($error->response->status);
        self::assertNull($error->response->requestId);
        self::assertSame([], $error->response->headers);
    }

    public function testRetryAfterBeyondDeadlineFailsFast(): void
    {
        foreach (['60', gmdate('D, d M Y H:i:s \G\M\T', time() + 60)] as $delay) {
            $api = new FakeApi(
                static fn (): Response => FakeApi::json(
                    ['error' => 'rate_limited', 'message' => 'Slow down'],
                    429,
                    ['Retry-After' => $delay],
                ),
                ['maxRetries' => 2, 'timeout' => 5],
            );

            $started = microtime(true);
            $error = self::catch(fn () => $api->sdk->emails->send(self::MAIL));

            self::assertSame(ErrorKind::Api, $error->kind);
            self::assertSame(429, $error->status);
            self::assertSame('rate_limited', $error->errorCode);
            self::assertSame($delay, $error->response->headers['retry-after'][0]);
            self::assertCount(1, $api->calls);
            self::assertLessThan(1.0, microtime(true) - $started);
        }
    }

    public function testRetryAfterWithinDeadlineIsHonored(): void
    {
        $api = new FakeApi(
            static fn (RecordedCall $call, int $n): Response => $n === 1
                ? FakeApi::json([], 429, ['Retry-After' => '0.05'])
                : FakeApi::json(['data' => FakeApi::ACCEPTED]),
            ['maxRetries' => 1, 'timeout' => 5],
        );

        $started = microtime(true);

        self::assertSame(FakeApi::ACCEPTED, $api->sdk->emails->send(self::MAIL));
        self::assertCount(2, $api->calls);
        self::assertGreaterThanOrEqual(0.04, microtime(true) - $started);
    }

    public function testPerRequestOverrides(): void
    {
        $api = new FakeApi(
            static fn (): Response => FakeApi::json([], 503, ['Retry-After' => '0']),
            ['maxRetries' => 2],
        );

        self::assertSame(ErrorKind::Api, self::catch(fn () => $api->sdk->contacts->list([], ['maxRetries' => 0]))->kind);
        self::assertCount(1, $api->calls);

        // Simulates a transport that gives up when the timeout Guzzle was given expires.
        $hanging = new FakeApi(static function (RecordedCall $call): Response {
            $timeout = $call->options['timeout'];
            self::assertIsFloat($timeout);
            usleep((int) ($timeout * 1e6));

            throw new ConnectException('Operation timed out', $call->request);
        });

        $started = microtime(true);
        $error = self::catch(fn () => $hanging->sdk->contacts->list([], ['timeout' => 0.05]));

        self::assertSame(ErrorKind::Timeout, $error->kind);
        self::assertSame(0.05, $hanging->call(0)->options['timeout']);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testDeadlineCoversRetries(): void
    {
        $api = new FakeApi(
            static function (RecordedCall $call): Response {
                $timeout = $call->options['timeout'];
                self::assertIsFloat($timeout);

                // Each answer takes 60 ms; a shorter transport timeout expires first.
                if ($timeout < 0.06) {
                    usleep((int) ($timeout * 1e6));

                    throw new ConnectException('Operation timed out', $call->request);
                }

                usleep(60_000);

                return FakeApi::json([], 503, ['Retry-After' => '0']);
            },
            ['maxRetries' => 10, 'timeout' => 0.2],
        );

        $started = microtime(true);
        $error = self::catch(fn () => $api->sdk->contacts->list());

        self::assertSame(ErrorKind::Timeout, $error->kind);
        self::assertLessThan(0.5, microtime(true) - $started);
        self::assertLessThanOrEqual(4, count($api->calls));

        // Each attempt gets only the time left before the shared deadline.
        $previous = 0.2;
        foreach ($api->calls as $call) {
            $timeout = $call->options['timeout'];
            self::assertIsFloat($timeout);
            self::assertLessThanOrEqual($previous, $timeout);
            $previous = $timeout;
        }
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $api = new FakeApi(static fn (): Response => new Response(307, ['Location' => 'https://evil.example']));

        $error = self::catch(fn () => $api->sdk->emails->send(self::MAIL));

        self::assertSame(ErrorKind::Api, $error->kind);
        self::assertSame(307, $error->status);
        self::assertCount(1, $api->calls);
    }

    public function testInvalidSuccessBodiesAreProtocolErrors(): void
    {
        foreach (['', '<html>gateway</html>', 'null', '"unexpected"', '{}', '{"data":null}', '{"success":"yes"}'] as $body) {
            $api = new FakeApi(static fn (): Response => new Response(200, [], $body), ['maxRetries' => 2]);

            $error = self::catch(fn () => $api->sdk->emails->send(self::MAIL));

            self::assertSame(ErrorKind::Protocol, $error->kind, "Body: {$body}");
            self::assertSame(200, $error->status);
            self::assertCount(1, $api->calls);
        }
    }

    public function testNonJsonErrorKeepsDiagnostics(): void
    {
        $api = new FakeApi(static fn (): Response => new Response(
            502,
            ['X-Request-Id' => 'req_proxy'],
            '<html>Bad gateway</html>',
        ));

        $error = self::catch(fn () => $api->sdk->contacts->list());

        self::assertSame(502, $error->status);
        self::assertSame('req_proxy', $error->requestId);
        self::assertSame('Request failed with HTTP 502', $error->getMessage());
    }

    public function testCorrelationIdHeaderIsARequestId(): void
    {
        $api = new FakeApi(static fn (): Response => FakeApi::json([], 404, ['X-Correlation-Id' => 'corr_1']));

        self::assertSame('corr_1', self::catch(fn () => $api->sdk->contacts->get('ct_1'))->requestId);
    }

    public function testApiKeyIsNotExposed(): void
    {
        $sdk = new NexiomConnect('nc_secret_key_value');

        self::assertStringNotContainsString('nc_secret_key_value', print_r($sdk, true));

        ob_start();
        var_dump($sdk);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('nc_secret_key_value', $dump);
        self::assertStringContainsString('https://api-connect.nxiom.com/api', $dump);
    }

    /**
     * @param Closure(): mixed $action
     */
    private static function catch(Closure $action): NexiomException
    {
        try {
            $action();
        } catch (NexiomException $error) {
            return $error;
        }

        self::fail('Expected a NexiomException');
    }
}
