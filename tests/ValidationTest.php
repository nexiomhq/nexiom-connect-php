<?php

declare(strict_types=1);

namespace Nexiom\Connect\Tests;

use Closure;
use DateTimeImmutable;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\NexiomConnect;
use Nexiom\Connect\Tests\Support\FakeApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    private const MAIL = [
        'from' => 'hello@example.com',
        'to' => 'user@example.com',
        'subject' => 'Hello',
        'html' => '<p>Hello</p>',
    ];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfiguration(): iterable
    {
        yield 'empty API key' => [['apiKey' => '']];
        yield 'API key with a newline' => [['apiKey' => "nc_\nsecret"]];
        yield 'API key with a space' => [['apiKey' => 'nc_ secret']];
        yield 'non-ASCII API key' => [['apiKey' => 'nc_é']];
        yield 'empty base URL' => [['baseUrl' => '']];
        yield 'relative base URL' => [['baseUrl' => '/api']];
        yield 'FTP base URL' => [['baseUrl' => 'ftp://localhost']];
        yield 'base URL with credentials' => [['baseUrl' => 'https://user:pass@example.com']];
        yield 'base URL with a query' => [['baseUrl' => 'https://example.com?a=1']];
        yield 'base URL with a fragment' => [['baseUrl' => 'https://example.com/api#x']];
        yield 'base URL with whitespace' => [['baseUrl' => "https://example.com/api\n"]];
        yield 'plain HTTP on a public host' => [['baseUrl' => 'http://example.com']];
        yield 'zero timeout' => [['timeout' => 0]];
        yield 'negative timeout' => [['timeout' => -1]];
        yield 'infinite timeout' => [['timeout' => INF]];
        yield 'NaN timeout' => [['timeout' => NAN]];
        yield 'negative retries' => [['maxRetries' => -1]];
        yield 'too many retries' => [['maxRetries' => 11]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfiguration(array $options): void
    {
        $this->expectException(NexiomValidationException::class);

        new NexiomConnect(...['apiKey' => 'nc_test_key', ...$options]);
    }

    public function testLoopbackHostsMayUsePlainHttp(): void
    {
        foreach (['http://localhost:3000/api', 'http://127.0.0.1/api', 'http://[::1]:8080/api'] as $baseUrl) {
            $api = new FakeApi(options: ['baseUrl' => $baseUrl]);
            $api->sdk->emails->send(self::MAIL);

            self::assertSame("{$baseUrl}/v1/emails/send", $api->call(0)->url);
        }
    }

    /**
     * @return iterable<string, array{Closure(NexiomConnect): mixed}>
     */
    public static function invalidArguments(): iterable
    {
        $mail = self::MAIL;

        $circular = new \stdClass();
        $circular->self = $circular;

        yield 'empty contact ID' => [static fn (NexiomConnect $sdk) => $sdk->contacts->get('')];
        yield 'dot-dot contact ID' => [static fn (NexiomConnect $sdk) => $sdk->contacts->get('..')];
        yield 'dot contact ID' => [static fn (NexiomConnect $sdk) => $sdk->contacts->delete('.')];
        yield 'contact limit above 100' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list(['limit' => 101])];
        yield 'fractional page' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list(['page' => 1.5])];
        yield 'numeric string page' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list(['page' => '2'])];
        yield 'page above 10,000' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list(['page' => 10_001])];
        yield 'tenant scope in contact list' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list(['orgId' => 'org_1'])];
        yield 'contact update without email' => [static fn (NexiomConnect $sdk) => $sdk->contacts->update('ct_1', [])];
        yield 'contact create without email' => [static fn (NexiomConnect $sdk) => $sdk->contacts->create(['firstName' => 'Ada'])];
        yield 'snake_case contact field' => [static fn (NexiomConnect $sdk) => $sdk->contacts->create(['email' => 'a@b.com', 'first_name' => 'Ada'])];
        yield 'list as contact properties' => [static fn (NexiomConnect $sdk) => $sdk->contacts->create(['email' => 'a@b.com', 'properties' => ['x']])];
        yield 'empty recipients' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'to' => []])];
        yield 'missing recipient' => [static fn (NexiomConnect $sdk) => $sdk->emails->send(['from' => 'a@b.com', 'subject' => 'Hi', 'text' => 'Hi'])];
        yield '101 recipients' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'to' => array_fill(0, 101, 'a@b.com')])];
        yield 'blank recipient' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'to' => ['a@b.com', ' ']])];
        yield 'CC with several recipients' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'to' => ['a@b.com', 'c@d.com'], 'cc' => 'e@f.com'])];
        yield 'empty body without template' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'html' => ''])];
        yield 'missing subject without template' => [static fn (NexiomConnect $sdk) => $sdk->emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'text' => 'Hi'])];
        yield 'missing sender' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'from' => ''])];
        yield 'snake_case email field' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'from_name' => 'Team'])];
        yield 'tenant scope in email' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'projectId' => 'prj_1'])];
        yield 'list as metadata' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'metadata' => ['a', 'b']])];
        yield 'idempotency key with a space' => [static fn (NexiomConnect $sdk) => $sdk->emails->send($mail, ['idempotencyKey' => 'bad key'])];
        yield 'idempotency key too long' => [static fn (NexiomConnect $sdk) => $sdk->emails->send($mail, ['idempotencyKey' => str_repeat('k', 129)])];
        yield 'unknown request option' => [static fn (NexiomConnect $sdk) => $sdk->emails->send($mail, ['idempotency_key' => 'k'])];
        yield 'zero per-request timeout' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list([], ['timeout' => 0])];
        yield 'NaN per-request timeout' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list([], ['timeout' => NAN])];
        yield 'string per-request retries' => [static fn (NexiomConnect $sdk) => $sdk->contacts->list([], ['maxRetries' => '1'])];
        yield 'natural-language schedule' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'scheduledAt' => 'tomorrow'])];
        yield 'impossible schedule date' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'scheduledAt' => '2026-02-30T10:00:00Z'])];
        yield 'numeric schedule' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'scheduledAt' => 1_790_000_000])];
        yield 'invalid UTF-8 body' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'text' => "\xB1\x31"])];
        yield 'recursive metadata' => [static fn (NexiomConnect $sdk) => $sdk->emails->send([...$mail, 'metadata' => ['self' => $circular]])];
        yield 'empty message ID on cancel' => [static fn (NexiomConnect $sdk) => $sdk->emails->cancel('')];
        yield 'reschedule without time' => [static fn (NexiomConnect $sdk) => $sdk->emails->reschedule('msg_1', [])];
        yield 'dot-dot message ID on reschedule' => [static fn (NexiomConnect $sdk) => $sdk->emails->reschedule('..', ['scheduledAt' => new DateTimeImmutable()])];
        yield 'email log limit zero' => [static fn (NexiomConnect $sdk) => $sdk->emails->list(['limit' => 0])];
        yield 'email log cursor too long' => [static fn (NexiomConnect $sdk) => $sdk->emails->list(['cursor' => str_repeat('x', 1025)])];
        yield 'empty email log start date' => [static fn (NexiomConnect $sdk) => $sdk->emails->list(['startDate' => ''])];
        yield 'empty delivery ID' => [static fn (NexiomConnect $sdk) => $sdk->emails->get('')];
        yield 'property update without fields' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->update('p', [])];
        yield 'boolean property type' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->create(['name' => 'x', 'type' => 'boolean'])];
        yield 'blank property name' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->update('p', ['name' => ' '])];
        yield 'property name too long' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->create(['name' => str_repeat('n', 256), 'type' => 'string'])];
        yield 'invalid property type on update' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->update('p', ['type' => 'boolean'])];
        yield 'numeric fallback' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->create(['name' => 'x', 'type' => 'number', 'fallbackValue' => 5])];
        yield 'fallback too long' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->update('p', ['fallbackValue' => str_repeat('f', 1001)])];
        yield 'API-style property key' => [static fn (NexiomConnect $sdk) => $sdk->contacts->properties->create(['key' => 'x', 'type' => 'string'])];
        yield 'unknown template status' => [static fn (NexiomConnect $sdk) => $sdk->templates->list(['status' => 'live'])];
        yield 'unknown template origin' => [static fn (NexiomConnect $sdk) => $sdk->templates->list(['origin' => 'shared'])];
        yield 'template page above 10,000' => [static fn (NexiomConnect $sdk) => $sdk->templates->list(['page' => 10_001])];
        yield 'tenant scope in template list' => [static fn (NexiomConnect $sdk) => $sdk->templates->list(['projectId' => 'prj_1'])];
        yield 'empty template ID' => [static fn (NexiomConnect $sdk) => $sdk->templates->get('')];
        yield 'dot-dot template ID on variables' => [static fn (NexiomConnect $sdk) => $sdk->templates->variables('..')];
        yield 'template versions before zero' => [static fn (NexiomConnect $sdk) => $sdk->templates->versions('tpl_1', ['beforeVersion' => 0])];
        yield 'template versions limit above 100' => [static fn (NexiomConnect $sdk) => $sdk->templates->versions('tpl_1', ['limit' => 101])];
        yield 'snake_case template version key' => [static fn (NexiomConnect $sdk) => $sdk->templates->versions('tpl_1', ['before_version' => 2])];
        yield 'domain create without domain' => [static fn (NexiomConnect $sdk) => $sdk->domains->create([])];
        yield 'blank domain' => [static fn (NexiomConnect $sdk) => $sdk->domains->create(['domain' => ' '])];
        yield 'string open tracking' => [static fn (NexiomConnect $sdk) => $sdk->domains->create(['domain' => 'mail.example.com', 'openTracking' => 'yes'])];
        yield 'snake_case domain field' => [static fn (NexiomConnect $sdk) => $sdk->domains->create(['domain' => 'mail.example.com', 'open_tracking' => true])];
        yield 'unknown domain status' => [static fn (NexiomConnect $sdk) => $sdk->domains->list(['status' => 'active'])];
        yield 'domain limit zero' => [static fn (NexiomConnect $sdk) => $sdk->domains->list(['limit' => 0])];
        yield 'empty domain ID on verify' => [static fn (NexiomConnect $sdk) => $sdk->domains->verify('')];
        yield 'dot domain ID on delete' => [static fn (NexiomConnect $sdk) => $sdk->domains->delete('.')];
        yield 'blank suppression search' => [static fn (NexiomConnect $sdk) => $sdk->emails->suppressions->list(['search' => '  '])];
        yield 'suppression search too long' => [static fn (NexiomConnect $sdk) => $sdk->emails->suppressions->list(['search' => str_repeat('s', 256)])];
        yield 'empty suppression cursor' => [static fn (NexiomConnect $sdk) => $sdk->emails->suppressions->list(['cursor' => ''])];
        yield 'suppression cursor too long' => [static fn (NexiomConnect $sdk) => $sdk->emails->suppressions->list(['cursor' => str_repeat('c', 401)])];
        yield 'suppression limit above 100' => [static fn (NexiomConnect $sdk) => $sdk->emails->suppressions->list(['limit' => 101])];
    }

    /**
     * @param Closure(NexiomConnect): mixed $action
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArguments(Closure $action): void
    {
        $api = new FakeApi();

        try {
            $action($api->sdk);
            self::fail('Expected a NexiomValidationException');
        } catch (NexiomValidationException) {
            self::assertSame([], $api->calls, 'No request is sent for invalid arguments');
        }
    }

    public function testUnknownKeysAreNamed(): void
    {
        $api = new FakeApi();

        $this->expectException(NexiomValidationException::class);
        $this->expectExceptionMessage('Unknown email parameter key "from_name"');

        $api->sdk->emails->send([...self::MAIL, 'from_name' => 'Team']);
    }
}
