<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use Closure;
use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Tests\Support\FakeApi;
use PHPUnit\Framework\TestCase;

final class MessagingTest extends TestCase
{
    private const MESSAGE = [
        'id' => 'msg_1',
        'channel' => 'whatsapp',
        'status' => 'accepted',
        'sender' => '+213555000111',
        'sender_name' => 'Acme',
        'recipient' => '+213555123456',
        'category' => 'utility',
        'template' => ['id' => 'wat_1', 'name' => 'order_update', 'language' => 'en_US', 'version' => 1],
        'is_billable' => true,
        'cost' => '0.0000',
        'cost_currency' => 'DZD',
        'failure_code' => null,
        'failure_reason' => null,
        'created_at' => '2026-10-14T09:30:00.000Z',
        'accepted_at' => '2026-10-14T09:30:01.000Z',
        'sent_at' => null,
        'delivered_at' => null,
        'read_at' => null,
        'failed_at' => null,
    ];

    private const SEND = [
        'channel' => 'whatsapp',
        'from' => '+213555000111',
        'to' => '+213555123456',
        'template' => [
            'id' => 'wat_1',
            'variables' => ['body' => [1 => 'Amina', 2 => 'A-1234']],
        ],
    ];

    private static function sent(): Response
    {
        return FakeApi::json(['message' => 'Message sent', 'data' => self::MESSAGE]);
    }

    public function testSendPostsTheTemplateSendWithAnIdempotencyKey(): void
    {
        $api = new FakeApi(static fn (): Response => self::sent());

        $message = $api->sdk->messaging->send(self::SEND);
        $call = $api->call(0);

        self::assertSame(self::MESSAGE, $message);
        self::assertSame('https://api-connect.nxiom.com/api/v1/messaging/messages', $call->url);
        self::assertSame('POST', $call->method);
        self::assertSame('Bearer nc_test_key', $call->header('authorization'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $call->header('idempotency-key'));

        // The phone number in `from` names the sender, and numbered variables are a JSON object.
        self::assertSame(
            '{"channel":"whatsapp","from":"+213555000111","to":"+213555123456","template":{"id":"wat_1","variables":{"body":{"1":"Amina","2":"A-1234"}}}}',
            $call->rawBody,
        );

        $api->sdk->messaging->send(
            [
                'channel' => 'whatsapp',
                'from' => ' +213555000111 ',
                'to' => '+213555123456',
                'template' => [
                    'id' => 'wat_1',
                    'variables' => ['header' => [], 'body' => ['first_name' => 'Amina']],
                ],
            ],
            ['idempotencyKey' => 'order-A-1234-shipped'],
        );
        $api->sdk->messaging->send([...self::SEND, 'template' => ['id' => 'wat_1', 'variables' => null]]);
        $api->sdk->messaging->send([...self::SEND, 'template' => ['id' => 'wat_1', 'variables' => []]]);

        self::assertSame('order-A-1234-shipped', $api->call(1)->header('idempotency-key'));
        self::assertSame(
            '{"channel":"whatsapp","from":"+213555000111","to":"+213555123456","template":{"id":"wat_1","variables":{"header":{},"body":{"first_name":"Amina"}}}}',
            $api->call(1)->rawBody,
        );
        self::assertSame(
            '{"channel":"whatsapp","from":"+213555000111","to":"+213555123456","template":{"id":"wat_1"}}',
            $api->call(2)->rawBody,
        );
        self::assertSame(
            '{"channel":"whatsapp","from":"+213555000111","to":"+213555123456","template":{"id":"wat_1","variables":{}}}',
            $api->call(3)->rawBody,
        );
    }

    public function testGetReadsOneMessageWithItsContent(): void
    {
        $details = [
            ...self::MESSAGE,
            'status' => 'read',
            'content' => [
                'header' => null,
                'body' => [
                    ['text' => 'Hi ', 'variable' => null],
                    ['text' => 'Amina', 'variable' => '1'],
                ],
                'footer' => null,
                'buttons' => [],
            ],
        ];
        $api = new FakeApi(static fn (): Response => FakeApi::json(['message' => 'Message fetched', 'data' => $details]));

        self::assertSame($details, $api->sdk->messaging->get('msg_1'));
        self::assertSame('GET', $api->call(0)->method);
        self::assertSame('/api/v1/messaging/messages/msg_1', $api->call(0)->path);
        self::assertNull($api->call(0)->header('idempotency-key'));
    }

    public function testSendsAndReadsRetryTransientFailures(): void
    {
        $flaky = static fn (Response $success): Closure => static fn ($call, int $attempt): Response => $attempt === 1
            ? FakeApi::json(['error' => 'service_unavailable', 'message' => 'Busy'], 503)
            : $success;

        $api = new FakeApi($flaky(self::sent()), ['maxRetries' => 1]);
        $api->sdk->messaging->send(self::SEND, ['idempotencyKey' => 'key-1']);

        self::assertCount(2, $api->calls);
        self::assertSame('key-1', $api->call(1)->header('idempotency-key'));
        self::assertSame($api->call(0)->rawBody, $api->call(1)->rawBody);

        $reader = new FakeApi($flaky(FakeApi::json(['data' => [...self::MESSAGE, 'content' => null]])), ['maxRetries' => 1]);
        $reader->sdk->messaging->get('msg_1');

        self::assertCount(2, $reader->calls);
    }

    public function testApiRefusalsAreNotRetried(): void
    {
        $api = new FakeApi(
            static fn (): Response => FakeApi::json([
                'error' => 'unexpected_state',
                'code' => 'whatsapp_template_not_approved',
                'message' => 'This template is not approved by WhatsApp.',
                'correlationId' => 'req_1',
            ], 422),
            ['maxRetries' => 2],
        );

        try {
            $api->sdk->messaging->send(self::SEND);
            self::fail('The refused send did not throw');
        } catch (NexiomException $error) {
            self::assertSame(ErrorKind::Api, $error->kind);
            self::assertSame(422, $error->status);
            self::assertSame('whatsapp_template_not_approved', $error->errorCode);
            self::assertSame('req_1', $error->requestId);
            self::assertNotNull($error->response->idempotencyKey);
        }

        self::assertCount(1, $api->calls);
    }

    public function testMalformedMessagesAreProtocolErrors(): void
    {
        foreach ([['id' => 1], ['success' => true], ['id' => 'del_1', 'channel' => 'email']] as $data) {
            $api = new FakeApi(static fn (): Response => FakeApi::json(['data' => $data]));

            foreach ([
                static fn () => $api->sdk->messaging->send(self::SEND),
                static fn () => $api->sdk->messaging->get('msg_1'),
            ] as $call) {
                try {
                    $call();
                    self::fail('A malformed message was accepted');
                } catch (NexiomException $error) {
                    self::assertSame(ErrorKind::Protocol, $error->kind);
                }
            }
        }
    }

    public function testInvalidArgumentsFailBeforeAnyRequest(): void
    {
        $api = new FakeApi(static fn (): Response => self::sent());
        $messages = $api->sdk->messaging;

        $template = static fn (mixed $variables): array => [...self::SEND, 'template' => ['id' => 'wat_1', 'variables' => $variables]];
        $invalid = [
            'missing channel' => ['from' => '+213555000111', 'to' => '+213555123456', 'template' => ['id' => 'wat_1']],
            'channel not available yet' => [...self::SEND, 'channel' => 'sms'],
            'missing from' => ['channel' => 'whatsapp', 'to' => '+213555123456', 'template' => ['id' => 'wat_1']],
            'sender ID' => [...self::SEND, 'from' => 'was_1'],
            'local sender' => [...self::SEND, 'from' => '0555000111'],
            'local recipient' => [...self::SEND, 'to' => '0555123456'],
            'missing template' => ['channel' => 'whatsapp', 'from' => '+213555000111', 'to' => '+213555123456'],
            'blank template' => [...self::SEND, 'template' => ['id' => ' ']],
            'unknown template key' => [...self::SEND, 'template' => ['id' => 'wat_1', 'language' => 'en']],
            'old sender field' => [...self::SEND, 'sender' => '+213555000111'],
            'old templateId field' => [...self::SEND, 'templateId' => 'wat_1'],
            'tenant field' => [...self::SEND, 'orgId' => 'org_1'],
            'variables list' => $template([['Amina']]),
            'unknown component' => $template(['footer' => ['1' => 'x']]),
            'values list' => $template(['body' => ['Amina', 'A-1234']]),
            'bad key' => $template(['body' => ['first name' => 'Amina']]),
            'non-string value' => $template(['body' => [1 => 2]]),
            'value too long' => $template(['body' => [1 => str_repeat('x', 1025)]]),
        ];

        foreach ($invalid as $case => $params) {
            try {
                /** @phpstan-ignore argument.type (invalid on purpose) */
                $messages->send($params);
                self::fail("{$case} was accepted");
            } catch (NexiomValidationException $error) {
                self::assertNotSame('', $error->getMessage(), $case);
            }
        }

        foreach (['has space', str_repeat('k', 201), 'clé'] as $key) {
            try {
                $messages->send(self::SEND, ['idempotencyKey' => $key]);
                self::fail("idempotency key {$key} was accepted");
            } catch (NexiomValidationException $error) {
                self::assertStringContainsString('idempotencyKey', $error->getMessage());
            }
        }

        foreach (['', '..'] as $id) {
            try {
                $messages->get($id);
                self::fail("message ID {$id} was accepted");
            } catch (NexiomValidationException $error) {
                self::assertStringContainsString('messageId', $error->getMessage());
            }
        }

        self::assertSame([], $api->calls);
    }
}
