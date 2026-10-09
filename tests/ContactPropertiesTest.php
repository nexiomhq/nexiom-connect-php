<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use GuzzleHttp\Psr7\Response;
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Tests\Support\FakeApi;
use Nexiom\Connect\Tests\Support\RecordedCall;
use PHPUnit\Framework\TestCase;

final class ContactPropertiesTest extends TestCase
{
    private const PROPERTY = ['id' => 'prop_1', 'key' => 'company', 'type' => 'string', 'fallback_value' => null];

    public function testPropertyMethods(): void
    {
        $score = [...self::PROPERTY, 'id' => 'prop_2', 'key' => 'score', 'type' => 'number'];

        $api = new FakeApi(static fn (RecordedCall $call): Response => match ($call->method) {
            'DELETE' => FakeApi::json(['success' => true]),
            'GET' => FakeApi::json(['data' => [self::PROPERTY, $score]]),
            default => FakeApi::json(['data' => self::PROPERTY]),
        });

        $properties = $api->sdk->contacts->properties;

        $properties->create(['name' => 'company', 'type' => 'string', 'fallbackValue' => 'Unknown']);
        self::assertSame(['key' => 'company', 'type' => 'string', 'fallback_value' => 'Unknown'], $api->call(0)->json());
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/properties', $api->call(0)->url);

        self::assertSame([self::PROPERTY], $properties->list(['type' => 'string', 'search' => 'COMP']));
        self::assertSame([$score], $properties->list(['search' => 'sco']));
        self::assertSame('', $api->call(1)->request->getUri()->getQuery());

        $properties->update('prop_1', ['fallbackValue' => null]);
        self::assertSame(['fallback_value' => null], $api->call(3)->json());
        self::assertSame('PATCH', $api->call(3)->method);

        $properties->update('prop_1', ['name' => 'company_name', 'type' => 'string']);
        self::assertSame(['key' => 'company_name', 'type' => 'string'], $api->call(4)->json());
        self::assertSame('https://api-connect.nxiom.com/api/v1/emails/properties/prop_1', $api->call(4)->url);

        self::assertSame(['success' => true], $properties->delete('prop_1'));
    }

    public function testListWithoutParamsAndApiErrorsArePreserved(): void
    {
        $api = new FakeApi(static fn (): Response => FakeApi::json(['data' => [self::PROPERTY]]));
        self::assertSame([self::PROPERTY], $api->sdk->contacts->properties->list());

        $denied = new FakeApi(static fn (): Response => FakeApi::json(['message' => 'Denied'], 403));

        try {
            $denied->sdk->contacts->properties->list();
            self::fail('Expected an API error');
        } catch (NexiomException $error) {
            self::assertSame(403, $error->status);
            self::assertSame('Denied', $error->getMessage());
        }
    }

    public function testMalformedCollectionsAreProtocolErrors(): void
    {
        foreach ([['items' => []], [null], [['id' => 'bad']]] as $data) {
            $api = new FakeApi(static fn (): Response => FakeApi::json(['data' => $data], 200, ['X-Request-Id' => 'req_1']));

            try {
                $api->sdk->contacts->properties->list(['search' => 'x']);
                self::fail('Expected a protocol error');
            } catch (NexiomException $error) {
                self::assertSame(ErrorKind::Protocol, $error->kind);
                self::assertSame(200, $error->status);
                self::assertSame('req_1', $error->requestId);
            }
        }
    }
}
