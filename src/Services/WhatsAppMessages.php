<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;
use stdClass;

/**
 * Send WhatsApp template messages and read them back.
 *
 * @phpstan-type WhatsAppMessage array{
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
 * @phpstan-type WhatsAppContentSegment array{text: string|null, variable: string|null}
 * @phpstan-type WhatsAppMessageDetails array{
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
 *         header: list<WhatsAppContentSegment>|null,
 *         body: list<WhatsAppContentSegment>|null,
 *         footer: string|null,
 *         buttons: list<string>,
 *     }|null,
 * }
 */
final class WhatsAppMessages
{
    private const PATH = '/v1/messaging/whatsapp/messages';

    private const SEND_KEYS = ['sender', 'to', 'templateId', 'variables'];

    private const E164 = '/^\+[1-9]\d{6,14}$/';

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Sends an approved template from a connected number, named by its phone number.
     *
     * Acceptance by WhatsApp is not delivery: follow the message with `get()` or webhooks.
     * Without an `idempotencyKey` option, the SDK generates one and reuses it for automatic
     * retries, so a message is sent at most once.
     *
     * @param array{
     *     sender: string,
     *     to: string,
     *     templateId: string,
     *     variables?: array{header?: array<array-key, string>|null, body?: array<array-key, string>|null}|null,
     * } $params
     * @param array{idempotencyKey?: string, timeout?: float|int, maxRetries?: int} $options
     * @return WhatsAppMessage
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function send(array $params, array $options = []): array
    {
        Validate::keys($params, self::SEND_KEYS, 'WhatsApp message parameter');

        $body = [
            'sender' => self::phoneNumber($params['sender'] ?? null, 'sender'),
            'to' => self::phoneNumber($params['to'] ?? null, 'to'),
            'templateId' => Validate::nonEmpty($params['templateId'] ?? null, 'templateId'),
        ];

        $variables = $params['variables'] ?? null;

        if ($variables !== null) {
            if (!is_array($variables) || ($variables !== [] && array_is_list($variables))) {
                throw new NexiomValidationException('variables must be an array with header and body values');
            }

            Validate::keys($variables, ['header', 'body'], 'WhatsApp variables');

            $body['variables'] = [];

            foreach (['header', 'body'] as $component) {
                if (array_key_exists($component, $variables) && $variables[$component] !== null) {
                    $body['variables'][$component] = self::values($variables[$component], "variables.{$component}");
                }
            }

            if ($body['variables'] === []) {
                $body['variables'] = new stdClass();
            }
        }

        $key = $options['idempotencyKey'] ?? bin2hex(random_bytes(16));
        if (!is_string($key) || preg_match('/^[\x21-\x7e]{1,200}$/', $key) !== 1) {
            throw new NexiomValidationException(
                'idempotencyKey must be 1 to 200 printable ASCII characters without whitespace',
            );
        }

        unset($options['idempotencyKey']);

        /** @var WhatsAppMessage */
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
     * @return WhatsAppMessageDetails
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $messageId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($messageId, 'messageId');

        /** @var WhatsAppMessageDetails */
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
        return is_string($data['id'] ?? null) && ($data['channel'] ?? null) === 'whatsapp';
    }
}
