<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs the SDK against PHP's built-in web server, through the real Guzzle and cURL transport.
 */
final class HttpServerTest extends TestCase
{
    private const MAIL = [
        'from' => 'hello@example.com',
        'to' => 'user@example.com',
        'subject' => 'Hello',
        'text' => 'Hello',
    ];

    /** @var resource|null */
    private static $server = null;

    private static string $origin = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a local port');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/Support/server.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PHP built-in server');
        }

        self::$server = $process;
        self::$origin = "http://{$address}";

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1), timeout: 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException("The PHP built-in server did not start on {$address}");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function testSendCrossesTheWireWithAuthAndIdempotency(): void
    {
        $sdk = new NexiomConnect('nc_test_key', baseUrl: self::$origin . '/api');

        $result = $sdk->emails->send(self::MAIL, ['idempotencyKey' => 'order-1']);

        self::assertSame('msg_1', $result['messageId']);
        self::assertSame(
            [
                'method' => 'POST',
                'path' => '/api/v1/emails/send',
                'authorization' => 'Bearer nc_test_key',
                'idempotencyKey' => 'order-1',
                'userAgent' => 'nexiom-connect-php/' . NexiomConnect::VERSION,
                'contentType' => 'application/json',
                'body' => self::MAIL,
            ],
            $result['received'] ?? null,
        );
    }

    public function testApiErrorsKeepServerDiagnostics(): void
    {
        $sdk = new NexiomConnect('nc_test_key', baseUrl: self::$origin . '/api');

        try {
            $sdk->contacts->get('ct_missing');
            self::fail('Expected an API error');
        } catch (NexiomException $error) {
            self::assertSame(ErrorKind::Api, $error->kind);
            self::assertSame(404, $error->status);
            self::assertSame('not_found', $error->errorCode);
            self::assertSame('Route not found', $error->getMessage());
            self::assertSame('req_server', $error->requestId);
        }
    }

    public function testRedirectIsNotFollowed(): void
    {
        $sdk = new NexiomConnect('nc_test_key', baseUrl: self::$origin . '/redirect/api');

        try {
            $sdk->contacts->list();
            self::fail('Expected an API error');
        } catch (NexiomException $error) {
            self::assertSame(ErrorKind::Api, $error->kind);
            self::assertSame(307, $error->status);
            self::assertSame(['http://127.0.0.1:1/stolen'], $error->response->headers['location'] ?? null);
        }
    }

    /**
     * Runs last: the single-process server keeps sleeping after the client gives up.
     */
    public function testSlowServerTimesOut(): void
    {
        $sdk = new NexiomConnect('nc_test_key', baseUrl: self::$origin . '/slow/api', timeout: 0.3, maxRetries: 2);

        $started = microtime(true);

        try {
            $sdk->emails->send(self::MAIL);
            self::fail('Expected a timeout');
        } catch (NexiomException $error) {
            self::assertSame(ErrorKind::Timeout, $error->kind);
            self::assertNull($error->status);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $error->response->idempotencyKey);
        }

        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(1.0, $elapsed);
    }
}
