<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private const VARIABLE = [
        'id' => 'var_1',
        'key' => 'first_name',
        'type' => 'string',
        'required' => true,
        'fallback_value' => null,
    ];

    private const VERSION = [
        'id' => 'ver_2',
        'template_id' => 'tpl_1',
        'version_number' => 2,
        'status' => 'published',
        'subject' => 'Welcome, {{first_name}}',
        'variables' => [self::VARIABLE],
    ];

    private const TEMPLATE = [
        'id' => 'tpl_1',
        'name' => 'Welcome',
        'alias' => 'welcome',
        'origin' => 'custom',
        'published_version_id' => 'ver_2',
        'draft_version' => null,
        'published_version' => self::VERSION,
    ];

    public function testTemplateMethods(): void
    {
        $page = ['items' => [self::TEMPLATE], 'total' => 1, 'page' => 2, 'limit' => 10];

        $api = new FakeApi(static fn (RecordedCall $call): Response => match (true) {
            str_ends_with($call->path, '/variables') => FakeApi::json(['data' => [self::VARIABLE]]),
            str_ends_with($call->path, '/versions') => FakeApi::json([
                'message' => 'Email template versions fetched',
                'data' => [self::VERSION],
            ]),
            str_ends_with($call->path, '/templates') => FakeApi::json([
                'message' => 'Email templates fetched',
                'data' => $page,
            ]),
            default => FakeApi::json(['message' => 'Email template details fetched', 'data' => self::TEMPLATE]),
        });

        $templates = $api->sdk->templates;

        $listed = $templates->list([
            'page' => 2,
            'limit' => 10,
            'status' => 'published',
            'search' => 'Welcome & co',
            'origin' => 'custom',
            'category' => null,
        ]);

        self::assertSame($page, $listed);
        self::assertSame(self::TEMPLATE, $templates->get('tpl/1 ?#'));
        self::assertSame([self::VARIABLE], $templates->variables('tpl_1'));
        self::assertSame([self::VERSION], $templates->versions('tpl_1', ['limit' => 20, 'beforeVersion' => 3]));
        self::assertSame([self::VERSION], $templates->versions('tpl_1'));

        self::assertSame(['GET', 'GET', 'GET', 'GET', 'GET'], array_map(
            static fn (RecordedCall $call): string => $call->method,
            $api->calls,
        ));

        self::assertSame(
            'https://api-connect.nxiom.com/api/v1/emails/templates?page=2&limit=10&status=published&search=Welcome%20%26%20co&origin=custom',
            $api->call(0)->url,
        );
        self::assertStringEndsWith('/v1/emails/templates/tpl%2F1%20%3F%23', $api->call(1)->url);
        self::assertStringEndsWith('/v1/emails/templates/tpl_1/variables', $api->call(2)->url);
        self::assertStringEndsWith('/v1/emails/templates/tpl_1/versions?limit=20&beforeVersion=3', $api->call(3)->url);
        self::assertStringEndsWith('/v1/emails/templates/tpl_1/versions', $api->call(4)->url);

        foreach ($api->calls as $call) {
            self::assertNull($call->rawBody);
        }
    }

    public function testListWithoutParamsSendsNoQuery(): void
    {
        $api = new FakeApi(static fn (): Response => FakeApi::json([
            'data' => ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 50],
        ]));

        $api->sdk->templates->list();

        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/templates', $api->call(0)->url);
    }

    public function testMalformedListsAreProtocolErrors(): void
    {
        foreach ([['data' => ['key' => 'first_name']], ['data' => ['first_name']]] as $body) {
            $api = new FakeApi(static fn (): Response => FakeApi::json($body));

            foreach ([
                static fn () => $api->sdk->templates->variables('tpl_1'),
                static fn () => $api->sdk->templates->versions('tpl_1'),
            ] as $action) {
                try {
                    $action();
                    self::fail('Expected a protocol error for ' . json_encode($body));
                } catch (NexiomException $error) {
                    self::assertSame(ErrorKind::Protocol, $error->kind);
                }
            }
        }
    }
}
