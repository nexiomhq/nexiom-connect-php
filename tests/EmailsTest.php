<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EmailsTest extends TestCase
{
    private const MAIL = [
        'from' => 'hello@example.com',
        'to' => 'user@example.com',
        'subject' => 'Hello',
        'html' => '<p>Hello</p>',
    ];

    public function testSendUsesProductionHostBearerAuthAndIdempotency(): void
    {
        $api = new FakeApi();

        $result = $api->sdk->emails->send(self::MAIL);

        $call = $api->call(0);

        self::assertSame(FakeApi::ACCEPTED, $result);
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/send', $call->url);
        self::assertSame('POST', $call->method);
        self::assertSame('Bearer nc_test_key', $call->header('authorization'));
        self::assertSame('application/json', $call->header('content-type'));
        self::assertSame('application/json', $call->header('accept'));
        self::assertSame('nexiom-connect-php/' . NexiomConnect::VERSION, $call->header('user-agent'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $call->header('idempotency-key'));
        self::assertSame(self::MAIL, $call->json());
        self::assertFalse($call->options['allow_redirects']);
    }

    public function testCustomHostKeepsBasePathAndDropsTrailingSlash(): void
    {
        $api = new FakeApi(options: ['baseUrl' => 'http://localhost:3000/api/']);

        $api->sdk->emails->send(self::MAIL, ['idempotencyKey' => 'order-1']);

        self::assertSame('http://localhost:3000/api/v1/emails/send', $api->call(0)->url);
        self::assertSame('order-1', $api->call(0)->header('idempotency-key'));
    }

    public function testTemplateRecipientsAndOptionalFieldsFollowTheBackendContract(): void
    {
        $api = new FakeApi();

        $params = [
            'from' => self::MAIL['from'],
            'fromName' => 'Nexiom',
            'to' => [self::MAIL['to']],
            'cc' => ['other@example.com'],
            'bcc' => 'private@example.com',
            'replyTo' => 'reply@example.com',
            'templateId' => 'tpl_1',
            'templateVariables' => ['name' => 'Ada', 'count' => 2, 'active' => true],
            'metadata' => ['order' => 1],
        ];

        $api->sdk->emails->send($params);

        self::assertSame($params, $api->call(0)->json());
    }

    public function testNullOptionalFieldsAreOmitted(): void
    {
        $api = new FakeApi();

        $api->sdk->emails->send([...self::MAIL, 'cc' => null, 'fromName' => null, 'scheduledAt' => null]);

        self::assertSame(self::MAIL, $api->call(0)->json());
    }

    public function testEmptyMapsAreObjects(): void
    {
        $api = new FakeApi();

        $api->sdk->emails->send([...self::MAIL, 'metadata' => []]);
        $api->sdk->contacts->create(['email' => 'user@example.com', 'properties' => []]);

        self::assertInstanceOf(stdClass::class, $api->call(0)->body);
        self::assertStringContainsString('"metadata":{}', (string) $api->call(0)->rawBody);
        self::assertStringContainsString('"properties":{}', (string) $api->call(1)->rawBody);
    }

    public function testScheduledSendsCancelRescheduleAndLogsFollowTheBackendContract(): void
    {
        $canceled = [
            'messageId' => 'msg_1',
            'status' => 'canceled',
            'canceledAt' => '2026-10-04T12:00:00.000Z',
            'deliveryIds' => ['del_1'],
        ];
        $rescheduled = [
            'messageId' => 'msg_1',
            'status' => 'scheduled',
            'scheduledAt' => '2026-10-06T08:00:00.000Z',
            'deliveryIds' => ['del_1'],
        ];
        $page = ['items' => [], 'total' => null, 'limit' => 10, 'hasMore' => true, 'nextCursor' => 'next_1'];
        $delivery = ['id' => 'del_1', 'message_id' => 'msg_1', 'status' => 'scheduled', 'open_count' => 0];

        $api = new FakeApi(static function (RecordedCall $call) use ($canceled, $rescheduled, $page, $delivery): Response {
            if (str_ends_with($call->path, '/send')) {
                return FakeApi::json(
                    ['data' => [...FakeApi::ACCEPTED, 'scheduledAt' => '2026-10-05T08:00:00.000Z']],
                    202,
                );
            }
            if (str_ends_with($call->path, '/cancel')) {
                return FakeApi::json(['message' => 'Scheduled email canceled', 'data' => $canceled]);
            }
            if ($call->method === 'PATCH') {
                return FakeApi::json(['message' => 'Scheduled email rescheduled', 'data' => $rescheduled]);
            }
            if ($call->path === '/api/v1/emails/logs') {
                return FakeApi::json(['message' => 'Email logs fetched', 'data' => $page]);
            }

            // The delivery detail endpoint returns the resource without a data wrapper.
            return FakeApi::json($delivery);
        });

        $emails = $api->sdk->emails;

        $sent = $emails->send([...self::MAIL, 'scheduledAt' => '2026-10-05T09:00:00+01:00']);
        self::assertSame('2026-10-05T08:00:00.000Z', $sent['scheduledAt']);
        self::assertSame('2026-10-05T09:00:00+01:00', $api->call(0)->json()['scheduledAt']);

        $paris = new DateTimeImmutable('2026-10-05T10:00:00', new DateTimeZone('Europe/Paris'));
        $emails->send([...self::MAIL, 'scheduledAt' => $paris]);
        self::assertSame('2026-10-05T08:00:00.000Z', $api->call(1)->json()['scheduledAt']);

        self::assertSame($canceled, $emails->cancel('msg_1'));
        self::assertSame('POST', $api->call(2)->method);
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/messages/msg_1/cancel', $api->call(2)->url);
        self::assertNull($api->call(2)->rawBody);
        self::assertNull($api->call(2)->header('content-type'));

        $moved = $emails->reschedule('msg_1', ['scheduledAt' => new DateTimeImmutable('2026-10-06T08:00:00Z')]);
        self::assertSame($rescheduled, $moved);
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/messages/msg_1', $api->call(3)->url);
        self::assertSame(['scheduledAt' => '2026-10-06T08:00:00.000Z'], $api->call(3)->json());

        $listed = $emails->list([
            'limit' => 10,
            'cursor' => 'cur_1',
            'status' => 'scheduled',
            'source' => 'api',
            'recipient' => 'user@example.com',
            'contactId' => 'ct_1',
            'startDate' => new DateTimeImmutable('2026-10-01T00:00:00Z'),
            'endDate' => '2026-10-02T00:00:00Z',
        ]);
        self::assertSame($page, $listed);
        self::assertSame('/api/v1/emails/logs', $api->call(4)->path);
        self::assertSame(
            [
                'limit' => '10',
                'cursor' => 'cur_1',
                'status' => 'scheduled',
                'source' => 'api',
                'recipient' => 'user@example.com',
                'contactId' => 'ct_1',
                'startDate' => '2026-10-01T00:00:00.000Z',
                'endDate' => '2026-10-02T00:00:00Z',
            ],
            $api->call(4)->query,
        );

        self::assertSame($delivery, $emails->get('del_1'));
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/logs/del_1', $api->call(5)->url);

        foreach ($api->calls as $call) {
            foreach (['orgId', 'organizationId', 'projectId'] as $key) {
                self::assertArrayNotHasKey($key, $call->query);
                self::assertArrayNotHasKey($key, $call->json());
            }
        }
    }

    public function testCancelAndDeliveryReadsRetry(): void
    {
        $api = new FakeApi(
            static fn (RecordedCall $call, int $n): Response => match (true) {
                $n % 2 === 1 => FakeApi::json([], 503, ['Retry-After' => '0']),
                $n === 2 => FakeApi::json(['data' => ['messageId' => 'msg_1']]),
                default => FakeApi::json(['id' => 'del_1']),
            },
            ['maxRetries' => 1],
        );

        self::assertSame(['messageId' => 'msg_1'], $api->sdk->emails->cancel('msg_1'));
        self::assertSame(['id' => 'del_1'], $api->sdk->emails->get('del_1'));
        self::assertCount(4, $api->calls);
    }

    public function testDeliveryDetailMustBeAnObject(): void
    {
        foreach (['null', '[]', '"text"'] as $body) {
            $api = new FakeApi(static fn (): Response => new Response(200, [], $body));

            try {
                $api->sdk->emails->get('del_1');
                self::fail("Expected a protocol error for {$body}");
            } catch (NexiomException $error) {
                self::assertSame(ErrorKind::Protocol, $error->kind);
            }
        }
    }
}
