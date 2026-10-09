<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\TestCase;

final class ContactsTest extends TestCase
{
    private const CONTACT = [
        'id' => 'ct_1',
        'email' => 'user@example.com',
        'first_name' => null,
        'last_name' => null,
        'phone_number' => null,
        'email_status' => 'subscribed',
        'created_at' => '2026-09-21T00:00:00.000Z',
    ];

    public function testContactMethods(): void
    {
        $page = ['items' => [self::CONTACT], 'total' => 1, 'page' => 2, 'limit' => 10];

        $api = new FakeApi(static fn (RecordedCall $call): Response => match (true) {
            $call->method === 'DELETE' => FakeApi::json(['success' => true]),
            $call->query !== [] => FakeApi::json(['data' => $page]),
            default => FakeApi::json(['data' => self::CONTACT]),
        });

        $contacts = $api->sdk->contacts;

        $params = [
            'email' => self::CONTACT['email'],
            'firstName' => 'Ada',
            'lastName' => null,
            'userId' => 'usr_1',
            'properties' => ['score' => 0, 'company' => 'Example'],
        ];

        self::assertSame('ct_1', $contacts->create($params)['id']);

        $listed = $contacts->list([
            'page' => 2,
            'limit' => 10,
            'search' => 'Ada & Co',
            'emailStatus' => 'subscribed',
            'listId' => 'list_1',
            'segmentId' => 'seg_1',
        ]);
        self::assertSame($page, $listed);

        $contacts->get('id/with ?#');
        $contacts->update('ct_1', [...$params, 'userId' => null, 'emailStatus' => 'unsubscribed']);

        self::assertSame(['success' => true], $contacts->delete('ct_1'));

        self::assertSame(['POST', 'GET', 'GET', 'PUT', 'DELETE'], array_map(
            static fn (RecordedCall $call): string => $call->method,
            $api->calls,
        ));

        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/contacts', $api->call(0)->url);
        self::assertSame(
            [
                'email' => 'user@example.com',
                'userId' => 'usr_1',
                'firstName' => 'Ada',
                'properties' => ['score' => 0, 'company' => 'Example'],
            ],
            $api->call(0)->json(),
        );

        self::assertSame('Ada & Co', $api->call(1)->query['search']);
        self::assertStringEndsWith('/v1/emails/contacts/id%2Fwith%20%3F%23', $api->call(2)->url);

        $update = $api->call(3)->json();
        self::assertArrayHasKey('userId', $update);
        self::assertNull($update['userId']);
        self::assertSame('unsubscribed', $update['emailStatus']);
        self::assertArrayNotHasKey('lastName', $update);

        foreach ($api->calls as $call) {
            self::assertSame('Bearer nc_test_key', $call->header('authorization'));
            self::assertNull($call->header('idempotency-key'));

            foreach (['orgId', 'organizationId', 'projectId'] as $key) {
                self::assertArrayNotHasKey($key, $call->query);
                self::assertArrayNotHasKey($key, $call->json());
                self::assertNull($call->header($key));
            }
        }
    }

    public function testListWithoutParamsSendsNoQuery(): void
    {
        $api = new FakeApi(static fn (): Response => FakeApi::json([
            'data' => ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 50],
        ]));

        $api->sdk->contacts->list();

        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/contacts', $api->call(0)->url);
    }
}
