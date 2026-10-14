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

final class WhatsAppMessagesTest extends TestCase
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
        'sender' => '+213555000111',
        'to' => '+213555123456',
        'templateId' => 'wat_1',
        'variables' => ['body' => [1 => 'Amina', 2 => 'A-1234']],
    ];

    private static function sent(): Response
    {
        return FakeApi::json(['message' => 'WhatsApp message sent', 'data' => self::MESSAGE]);
    }

    public function testSendPostsTheTemplateSendWithAnIdempotencyKey(): void
    {
        $api = new FakeApi(static fn (): Response => self::sent());

        $message = $api->sdk->whatsapp->messages->send(self::SEND);
        $call = $api->call(0);

        self::assertSame(self::MESSAGE, $message);
        self::assertSame('https://api-connect.nxiom.com/api/v1/messaging/whatsapp/messages', $call->url);
        self::assertSame('POST', $call->method);
        self::assertSame('Bearer nc_test_key', $call->header('authorization'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $call->header('idempotency-key'));

        // The phone number names the sender, and numbered variables are a JSON object.
        self::assertSame(
            '{"sender":"+213555000111","to":"+213555123456","templateId":"wat_1","variables":{"body":{"1":"Amina","2":"A-1234"}}}',
            $call->rawBody,
        );

        $api->sdk->whatsapp->messages->send(
            [
                'sender' => ' +213555000111 ',
                'to' => '+213555123456',
                'templateId' => 'wat_1',
                'variables' => ['header' => [], 'body' => ['first_name' => 'Amina']],
            ],
            ['idempotencyKey' => 'order-A-1234-shipped'],
        );
        $api->sdk->whatsapp->messages->send([...self::SEND, 'variables' => null]);
        $api->sdk->whatsapp->messages->send([...self::SEND, 'variables' => []]);

        self::assertSame('order-A-1234-shipped', $api->call(1)->header('idempotency-key'));
        self::assertSame(
            '{"sender":"+213555000111","to":"+213555123456","templateId":"wat_1","variables":{"header":{},"body":{"first_name":"Amina"}}}',
            $api->call(1)->rawBody,
        );
        self::assertSame('{"sender":"+213555000111","to":"+213555123456","templateId":"wat_1"}', $api->call(2)->rawBody);
        self::assertSame(
            '{"sender":"+213555000111","to":"+213555123456","templateId":"wat_1","variables":{}}',
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
        $api = new FakeApi(static fn (): Response => FakeApi::json(['message' => 'WhatsApp message fetched', 'data' => $details]));

        self::assertSame($details, $api->sdk->whatsapp->messages->get('msg_1'));
        self::assertSame('GET', $api->call(0)->method);
        self::assertSame('/api/v1/messaging/whatsapp/messages/msg_1', $api->call(0)->path);
        self::assertNull($api->call(0)->header('idempotency-key'));
    }

    public function testSendsAndReadsRetryTransientFailures(): void
    {
        $flaky = static fn (Response $success): Closure => static fn ($call, int $attempt): Response => $attempt === 1
            ? FakeApi::json(['error' => 'service_unavailable', 'message' => 'Busy'], 503)
            : $success;

        $api = new FakeApi($flaky(self::sent()), ['maxRetries' => 1]);
        $api->sdk->whatsapp->messages->send(self::SEND, ['idempotencyKey' => 'key-1']);

        self::assertCount(2, $api->calls);
        self::assertSame('key-1', $api->call(1)->header('idempotency-key'));
        self::assertSame($api->call(0)->rawBody, $api->call(1)->rawBody);

        $reader = new FakeApi($flaky(FakeApi::json(['data' => [...self::MESSAGE, 'content' => null]])), ['maxRetries' => 1]);
        $reader->sdk->whatsapp->messages->get('msg_1');

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
            $api->sdk->whatsapp->messages->send(self::SEND);
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
                static fn () => $api->sdk->whatsapp->messages->send(self::SEND),
                static fn () => $api->sdk->whatsapp->messages->get('msg_1'),
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
        $messages = $api->sdk->whatsapp->messages;

        $invalid = [
            'missing sender' => ['to' => '+213555123456', 'templateId' => 'wat_1'],
            'sender ID' => [...self::SEND, 'sender' => 'was_1'],
            'local sender' => [...self::SEND, 'sender' => '0555000111'],
            'local recipient' => [...self::SEND, 'to' => '0555123456'],
            'blank template' => [...self::SEND, 'templateId' => ' '],
            'old senderId field' => [...self::SEND, 'senderId' => 'was_1'],
            'tenant field' => [...self::SEND, 'orgId' => 'org_1'],
            'variables list' => [...self::SEND, 'variables' => [['Amina']]],
            'unknown component' => [...self::SEND, 'variables' => ['footer' => ['1' => 'x']]],
            'values list' => [...self::SEND, 'variables' => ['body' => ['Amina', 'A-1234']]],
            'bad key' => [...self::SEND, 'variables' => ['body' => ['first name' => 'Amina']]],
            'non-string value' => [...self::SEND, 'variables' => ['body' => [1 => 2]]],
            'value too long' => [...self::SEND, 'variables' => ['body' => [1 => str_repeat('x', 1025)]]],
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
