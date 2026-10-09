<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Tests\Support\FakeApi;
use PHPUnit\Framework\TestCase;

final class EmailSuppressionsTest extends TestCase
{
    public function testListFollowsTheBackendContract(): void
    {
        $page = [
            'items' => [
                ['email' => 'gone@example.com', 'reason' => 'hard_bounce'],
                ['email' => 'reader@example.com', 'reason' => 'unsubscribed'],
            ],
            'nextCursor' => 'cmVhZGVy',
            'hasMore' => true,
        ];

        $api = new FakeApi(static fn (): Response => FakeApi::json([
            'message' => 'Email suppressions fetched',
            'data' => $page,
        ]));

        $suppressions = $api->sdk->emails->suppressions;

        self::assertSame($page, $suppressions->list(['search' => '  example.com ', 'limit' => 2]));
        $suppressions->list(['cursor' => $page['nextCursor']]);
        $suppressions->list();

        self::assertSame(
            'https://api-connect.nxiom.com/api/v1/emails/suppressions?search=example.com&limit=2',
            $api->call(0)->url,
        );
        self::assertSame('cmVhZGVy', $api->call(1)->query['cursor']);
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/suppressions', $api->call(2)->url);
        self::assertSame('GET', $api->call(0)->method);
    }
}
