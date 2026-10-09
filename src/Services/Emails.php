<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Send emails, manage scheduled sends, and read email logs.
 *
 * @phpstan-type SendEmailResponse array{
 *     messageId: string,
 *     deliveryIds: list<string>,
 *     totalQueued: int,
 *     scheduledAt: string|null,
 * }
 * @phpstan-type CancelEmailResponse array{
 *     messageId: string,
 *     status: 'canceled',
 *     canceledAt: string,
 *     deliveryIds: list<string>,
 * }
 * @phpstan-type RescheduleEmailResponse array{
 *     messageId: string,
 *     status: 'scheduled',
 *     scheduledAt: string,
 *     deliveryIds: list<string>,
 * }
 * @phpstan-type EmailListItem array{
 *     id: string,
 *     created_at: string,
 *     recipient: string,
 *     subject: string,
 *     status: string,
 *     scheduled_at: string|null,
 * }
 * @phpstan-type ListEmailsResponse array{
 *     items: list<EmailListItem>,
 *     total: int|null,
 *     limit: int,
 *     hasMore: bool,
 *     nextCursor: string|null,
 * }
 */
final class Emails
{
    private const PATH = '/v1/emails';

    private const SEND_KEYS = [
        'from',
        'fromName',
        'to',
        'cc',
        'bcc',
        'replyTo',
        'subject',
        'html',
        'text',
        'templateId',
        'templateVariables',
        'metadata',
        'scheduledAt',
    ];

    private const LIST_KEYS = [
        'limit',
        'cursor',
        'status',
        'source',
        'recipient',
        'search',
        'contactId',
        'templateId',
        'startDate',
        'endDate',
    ];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Queues an email for delivery, now or at `scheduledAt`.
     *
     * Acceptance means queued or scheduled, not delivered. Without an `idempotencyKey` option,
     * the SDK generates one and reuses it for automatic retries.
     *
     * @param array<string, mixed> $params
     * @param array{idempotencyKey?: string, timeout?: float|int, maxRetries?: int} $options
     * @return SendEmailResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function send(array $params, array $options = []): array
    {
        Validate::keys($params, self::SEND_KEYS, 'email parameter');

        Validate::nonEmpty($params['from'] ?? null, 'from');
        Validate::optionalString($params['fromName'] ?? null, 'fromName');
        Validate::optionalString($params['replyTo'] ?? null, 'replyTo');

        self::addresses($params['to'] ?? null, 'to');

        foreach (['cc', 'bcc'] as $name) {
            if (isset($params[$name])) {
                self::addresses($params[$name], $name);
            }
        }

        $templateId = Validate::optionalString($params['templateId'] ?? null, 'templateId');
        $subject = Validate::optionalString($params['subject'] ?? null, 'subject');
        $html = Validate::optionalString($params['html'] ?? null, 'html');
        $text = Validate::optionalString($params['text'] ?? null, 'text');

        if ($templateId === null || $templateId === '') {
            Validate::nonEmpty($subject, 'subject');

            if (trim($html ?? '') === '' && trim($text ?? '') === '') {
                throw new NexiomValidationException('html, text, or templateId is required');
            }
        }

        if (
            is_array($params['to'])
            && count($params['to']) > 1
            && (!empty($params['cc']) || !empty($params['bcc']))
        ) {
            throw new NexiomValidationException('CC and BCC require one primary recipient');
        }

        $key = $options['idempotencyKey'] ?? bin2hex(random_bytes(16));
        if (!is_string($key) || preg_match('/^[\x21-\x7e]{1,128}$/', $key) !== 1) {
            throw new NexiomValidationException(
                'idempotencyKey must be 1 to 128 printable ASCII characters without whitespace',
            );
        }

        unset($options['idempotencyKey']);

        $body = Validate::pick($params, self::SEND_KEYS);

        foreach (['templateVariables', 'metadata'] as $name) {
            if (isset($body[$name])) {
                $body[$name] = Validate::optionalMap($body[$name], $name);
            }
        }

        if (isset($body['scheduledAt'])) {
            $body['scheduledAt'] = Validate::timestamp($body['scheduledAt'], 'scheduledAt');
        }

        /** @var SendEmailResponse */
        return $this->transport->request('POST', self::PATH . '/send', $body, $options, $key);
    }

    /**
     * Cancels every scheduled delivery of a message. Canceling twice returns the same result.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return CancelEmailResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function cancel(string $messageId, array $options = []): array
    {
        $path = self::PATH . '/messages/' . Validate::resourceId($messageId, 'messageId') . '/cancel';

        /** @var CancelEmailResponse */
        return $this->transport->request('POST', $path, null, $options, retryable: true);
    }

    /**
     * Moves every scheduled delivery of a message to a new send time.
     *
     * @param array{scheduledAt: \DateTimeInterface|string} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return RescheduleEmailResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function reschedule(string $messageId, array $params, array $options = []): array
    {
        $path = self::PATH . '/messages/' . Validate::resourceId($messageId, 'messageId');

        Validate::keys($params, ['scheduledAt'], 'reschedule parameter');

        $body = [
            'scheduledAt' => Validate::timestamp($params['scheduledAt'] ?? null, 'scheduledAt'),
        ];

        /** @var RescheduleEmailResponse */
        return $this->transport->request('PATCH', $path, $body, $options);
    }

    /**
     * Lists recipient deliveries, newest first. Pass `nextCursor` as `cursor` for the next page.
     *
     * @param array{
     *     limit?: int|null,
     *     cursor?: string|null,
     *     status?: string|null,
     *     source?: string|null,
     *     recipient?: string|null,
     *     search?: string|null,
     *     contactId?: string|null,
     *     templateId?: string|null,
     *     startDate?: \DateTimeInterface|string|null,
     *     endDate?: \DateTimeInterface|string|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ListEmailsResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, self::LIST_KEYS, 'email list parameter');

        $cursor = Validate::optionalString($params['cursor'] ?? null, 'cursor');
        if ($cursor !== null && strlen($cursor) > 1024) {
            throw new NexiomValidationException('cursor must contain at most 1024 characters');
        }

        $query = Validate::query([
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'cursor' => $cursor,
            'status' => Validate::optionalString($params['status'] ?? null, 'status'),
            'source' => Validate::optionalString($params['source'] ?? null, 'source'),
            'recipient' => Validate::optionalString($params['recipient'] ?? null, 'recipient'),
            'search' => Validate::optionalString($params['search'] ?? null, 'search'),
            'contactId' => Validate::optionalString($params['contactId'] ?? null, 'contactId'),
            'templateId' => Validate::optionalString($params['templateId'] ?? null, 'templateId'),
            'startDate' => Validate::optionalTimestamp($params['startDate'] ?? null, 'startDate'),
            'endDate' => Validate::optionalTimestamp($params['endDate'] ?? null, 'endDate'),
        ]);

        /** @var ListEmailsResponse */
        return $this->transport->request('GET', self::PATH . '/logs' . $query, null, $options);
    }

    /**
     * Fetches one recipient delivery, with its content and history, by delivery ID (not message ID).
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return array<string, mixed>
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $deliveryId, array $options = []): array
    {
        $path = self::PATH . '/logs/' . Validate::resourceId($deliveryId, 'deliveryId');

        /** @var array<string, mixed> */
        return $this->transport->request('GET', $path, null, $options, envelope: false);
    }

    private static function addresses(mixed $value, string $name): void
    {
        $addresses = is_array($value) ? $value : [$value];

        if (count($addresses) < 1 || count($addresses) > 100 || !array_is_list($addresses)) {
            throw new NexiomValidationException("{$name} must contain 1 to 100 addresses");
        }

        foreach ($addresses as $address) {
            Validate::nonEmpty($address, $name);
        }
    }
}
