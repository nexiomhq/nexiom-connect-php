<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\TestCase;

final class DomainsTest extends TestCase
{
    private const DOMAIN = [
        'id' => 'dom_1',
        'domain' => 'mail.example.com',
        'status' => 'pending',
        'region' => 'global',
        'open_tracking' => false,
        'last_verified_at' => null,
        'dns_records' => [
            [
                'id' => 'dns_1',
                'purpose' => 'dkim',
                'record_type' => 'CNAME',
                'name' => 'selector._domainkey.mail.example.com',
                'value' => 'selector.dkim.example.net',
                'priority' => null,
                'status' => 'pending',
                'required_for_sending' => true,
            ],
        ],
    ];

    public function testDomainMethods(): void
    {
        $page = ['items' => [self::DOMAIN], 'total' => 1, 'page' => 3, 'limit' => 5];

        $api = new FakeApi(static fn (RecordedCall $call): Response => match (true) {
            $call->method === 'DELETE' => FakeApi::json(['success' => true]),
            $call->method === 'GET' && $call->query !== [] => FakeApi::json([
                'message' => 'Email domains fetched',
                'data' => $page,
            ]),
            $call->method === 'GET' => FakeApi::json(['message' => 'Email domain details fetched', 'data' => self::DOMAIN]),
            default => FakeApi::json(['data' => self::DOMAIN]),
        });

        $domains = $api->sdk->domains;

        self::assertSame(self::DOMAIN, $domains->create(['domain' => 'mail.example.com', 'openTracking' => false]));
        self::assertSame(self::DOMAIN, $domains->create(['domain' => 'mail.example.com', 'openTracking' => null]));
        self::assertSame($page, $domains->list(['page' => 3, 'limit' => 5, 'status' => 'pending', 'search' => 'mail']));
        self::assertSame(self::DOMAIN, $domains->get('dom/1'));
        self::assertSame(self::DOMAIN, $domains->verify('dom_1'));
        self::assertSame(['success' => true], $domains->delete('dom_1'));

        self::assertSame(['POST', 'POST', 'GET', 'GET', 'POST', 'DELETE'], array_map(
            static fn (RecordedCall $call): string => $call->method,
            $api->calls,
        ));

        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/domains', $api->call(0)->url);
        self::assertSame(['domain' => 'mail.example.com', 'openTracking' => false], $api->call(0)->json());
        self::assertSame(['domain' => 'mail.example.com'], $api->call(1)->json());
        self::assertSame(
            'https://api-connect.nxiom.com/api/v1/emails/domains?page=3&limit=5&status=pending&search=mail',
            $api->call(2)->url,
        );
        self::assertStringEndsWith('/v1/emails/domains/dom%2F1', $api->call(3)->url);
        self::assertStringEndsWith('/v1/emails/domains/dom_1/verify', $api->call(4)->url);
        self::assertNull($api->call(4)->rawBody);
        self::assertStringEndsWith('/v1/emails/domains/dom_1', $api->call(5)->url);
    }

    public function testVerifyRetries(): void
    {
        $api = new FakeApi(
            static fn (RecordedCall $call, int $n): Response => $n === 1
                ? FakeApi::json([], 503, ['Retry-After' => '0'])
                : FakeApi::json(['data' => self::DOMAIN]),
            ['maxRetries' => 1],
        );

        self::assertSame(self::DOMAIN, $api->sdk->domains->verify('dom_1'));
        self::assertCount(2, $api->calls);
    }
}
