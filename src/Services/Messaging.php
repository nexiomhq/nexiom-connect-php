<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;
use stdClass;

/**
 * Send messages on any messaging channel, and read them back. `channel` selects the fields.
 *
 * @phpstan-type Message array{
 *     id: string,
 *     channel: 'whatsapp',
 *     status: 'sending'|'accepted'|'sent'|'delivered'|'read'|'failed'|'uncertain',
 *     sender: string,
 *     sender_name: string|null,
 *     recipient: string,
 *     category: 'marketing'|'utility'|'authentication'|null,
 *     template: array{id: string, name: string, language: string, version: int}|null,
 *     is_billable: bool,
 *     cost: string,
 *     cost_currency: string,
 *     failure_code: string|null,
 *     failure_reason: string|null,
 *     created_at: string,
 *     accepted_at: string|null,
 *     sent_at: string|null,
 *     delivered_at: string|null,
 *     read_at: string|null,
 *     failed_at: string|null,
 * }
 * @phpstan-type ContentSegment array{text: string|null, variable: string|null}
 * @phpstan-type MessageDetails array{
 *     id: string,
 *     channel: 'whatsapp',
 *     status: 'sending'|'accepted'|'sent'|'delivered'|'read'|'failed'|'uncertain',
 *     sender: string,
 *     sender_name: string|null,
 *     recipient: string,
 *     category: 'marketing'|'utility'|'authentication'|null,
 *     template: array{id: string, name: string, language: string, version: int}|null,
 *     is_billable: bool,
 *     cost: string,
 *     cost_currency: string,
 *     failure_code: string|null,
 *     failure_reason: string|null,
 *     created_at: string,
 *     accepted_at: string|null,
 *     sent_at: string|null,
 *     delivered_at: string|null,
 *     read_at: string|null,
 *     failed_at: string|null,
 *     content: array{
 *         header: list<ContentSegment>|null,
 *         body: list<ContentSegment>|null,
 *         footer: string|null,
 *         buttons: list<string>,
 *     }|null,
 * }
 */
final class Messaging
{
    private const PATH = '/v1/messaging/messages';

    /** Messaging channels. New channels are added here, with their own parameters. */
    private const CHANNELS = ['whatsapp'];

    private const WHATSAPP_KEYS = ['channel', 'from', 'to', 'template'];

    private const TEMPLATE_KEYS = ['id', 'variables'];

    private const E164 = '/^\+[1-9]\d{6,14}$/';

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Sends a message on the channel named by `channel`.
     *
     * WhatsApp sends an approved template from a connected number, named by its phone number in
     * `from`. Acceptance by WhatsApp is not delivery: follow the message with `get()` or webhooks.
     * Without an `idempotencyKey` option, the SDK generates one and reuses it for automatic
     * retries, so a message is sent at most once.
     *
     * @param array{
     *     channel: 'whatsapp',
     *     from: string,
     *     to: string,
     *     template: array{
     *         id: string,
     *         variables?: array{header?: array<array-key, string>|null, body?: array<array-key, string>|null}|null,
     *     },
     * } $params
     * @param array{idempotencyKey?: string, timeout?: float|int, maxRetries?: int} $options
     * @return Message
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function send(array $params, array $options = []): array
    {
        $channel = $params['channel'] ?? null;

        if (!in_array($channel, self::CHANNELS, true)) {
            throw new NexiomValidationException('channel must be one of: ' . implode(', ', self::CHANNELS));
        }

        Validate::keys($params, self::WHATSAPP_KEYS, 'WhatsApp message parameter');

        $template = $params['template'] ?? null;

        if (!is_array($template) || array_is_list($template)) {
            throw new NexiomValidationException('template must be an array with an id and variables');
        }

        Validate::keys($template, self::TEMPLATE_KEYS, 'template parameter');

        $body = [
            'channel' => $channel,
            'from' => self::phoneNumber($params['from'] ?? null, 'from'),
            'to' => self::phoneNumber($params['to'] ?? null, 'to'),
            'template' => [
                'id' => Validate::nonEmpty($template['id'] ?? null, 'template.id'),
            ],
        ];

        $variables = $template['variables'] ?? null;

        if ($variables !== null) {
            if (!is_array($variables) || ($variables !== [] && array_is_list($variables))) {
                throw new NexiomValidationException('template.variables must be an array with header and body values');
            }

            Validate::keys($variables, ['header', 'body'], 'template variables');

            $values = [];

            foreach (['header', 'body'] as $component) {
                if (array_key_exists($component, $variables) && $variables[$component] !== null) {
                    $values[$component] = self::values($variables[$component], "template.variables.{$component}");
                }
            }

            $body['template']['variables'] = $values === [] ? new stdClass() : $values;
        }

        $key = $options['idempotencyKey'] ?? bin2hex(random_bytes(16));
        if (!is_string($key) || preg_match('/^[\x21-\x7e]{1,200}$/', $key) !== 1) {
            throw new NexiomValidationException(
                'idempotencyKey must be 1 to 200 printable ASCII characters without whitespace',
            );
        }

        unset($options['idempotencyKey']);

        /** @var Message */
        return $this->transport->request(
            'POST',
            self::PATH,
            $body,
            $options,
            $key,
            accepts: self::isMessage(...),
        );
    }

    /**
     * Fetches one message with its current status, cost, and the content the recipient received.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return MessageDetails
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $messageId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($messageId, 'messageId');

        /** @var MessageDetails */
        return $this->transport->request('GET', $path, null, $options, accepts: self::isMessage(...));
    }

    private static function phoneNumber(mixed $value, string $name): string
    {
        $number = is_string($value) ? trim($value) : null;

        if ($number === null || preg_match(self::E164, $number) !== 1) {
            throw new NexiomValidationException("{$name} must be an E.164 phone number, such as +213555123456");
        }

        return $number;
    }

    /**
     * Variable values keyed by number or name. Numbered keys may be PHP integers (`[1 => 'Amina']`);
     * the map is always sent as a JSON object.
     *
     * @return array<string, string>|stdClass
     */
    private static function values(mixed $value, string $name): array|stdClass
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new NexiomValidationException("{$name} must be an array keyed by variable number or name");
        }

        $values = [];

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
                throw new NexiomValidationException("{$name} keys must be variable numbers or names");
            }

            if (!is_string($item) || Validate::length($item) > 1024) {
                throw new NexiomValidationException("{$name}.{$key} must be a string of at most 1024 characters");
            }

            $values[$key] = $item;
        }

        return $values === [] ? new stdClass() : $values;
    }

    /**
     * @param array<mixed> $data
     */
    private static function isMessage(array $data): bool
    {
        return is_string($data['id'] ?? null) && in_array($data['channel'] ?? null, self::CHANNELS, true);
    }
}
