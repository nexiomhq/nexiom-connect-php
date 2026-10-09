<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Create, list, update, and delete contacts. Responses keep the API's snake_case field names.
 *
 * @phpstan-type ContactListItem array{
 *     id: string,
 *     email: string,
 *     first_name: string|null,
 *     last_name: string|null,
 *     phone_number: string|null,
 *     email_status: 'subscribed'|'unsubscribed',
 *     created_at: string,
 * }
 * @phpstan-type Contact array{
 *     id: string,
 *     email: string,
 *     first_name: string|null,
 *     last_name: string|null,
 *     phone_number: string|null,
 *     email_status: 'subscribed'|'unsubscribed',
 *     created_at: string,
 *     user_id: string|null,
 *     updated_at: string,
 *     deleted_at: string|null,
 *     source: string|null,
 *     last_emailed_at: string|null,
 *     last_opened_at: string|null,
 *     last_clicked_at: string|null,
 *     total_emails_sent: int,
 *     total_emails_opened: int,
 *     total_emails_clicked: int,
 * }
 * @phpstan-type ListContactsResponse array{
 *     items: list<ContactListItem>,
 *     total: int,
 *     page: int,
 *     limit: int,
 * }
 */
final class Contacts
{
    private const PATH = '/v1/emails/contacts';

    /** The API bounds page offsets; narrow larger collections with filters or search. */
    private const MAX_PAGE = 10000;

    private const CREATE_KEYS = ['email', 'userId', 'firstName', 'lastName', 'phoneNumber', 'properties'];

    private const UPDATE_KEYS = [...self::CREATE_KEYS, 'emailStatus'];

    private const LIST_KEYS = ['page', 'limit', 'search', 'emailStatus', 'segmentId', 'listId'];

    public readonly ContactProperties $properties;

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
        $this->properties = new ContactProperties($transport);
    }

    /**
     * Creates a contact.
     *
     * @param array{
     *     email: string,
     *     userId?: string|null,
     *     firstName?: string|null,
     *     lastName?: string|null,
     *     phoneNumber?: string|null,
     *     properties?: array<string, string|int|float|null>|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Contact
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function create(array $params, array $options = []): array
    {
        Validate::keys($params, self::CREATE_KEYS, 'contact parameter');

        /** @var Contact */
        return $this->transport->request('POST', self::PATH, self::body($params, self::CREATE_KEYS), $options);
    }

    /**
     * Lists or searches contacts, 50 per page by default and at most 100.
     *
     * @param array{
     *     page?: int|null,
     *     limit?: int|null,
     *     search?: string|null,
     *     emailStatus?: 'subscribed'|'unsubscribed'|null,
     *     segmentId?: string|null,
     *     listId?: string|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ListContactsResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, self::LIST_KEYS, 'contact list parameter');

        $query = Validate::query([
            'page' => Validate::optionalInteger($params['page'] ?? null, 'page', 1, self::MAX_PAGE),
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'search' => Validate::optionalString($params['search'] ?? null, 'search'),
            'emailStatus' => Validate::optionalString($params['emailStatus'] ?? null, 'emailStatus'),
            'segmentId' => Validate::optionalString($params['segmentId'] ?? null, 'segmentId'),
            'listId' => Validate::optionalString($params['listId'] ?? null, 'listId'),
        ]);

        /** @var ListContactsResponse */
        return $this->transport->request('GET', self::PATH . $query, null, $options);
    }

    /**
     * Gets a contact with its properties and activity.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return array<string, mixed>
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $id, array $options = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->request('GET', self::PATH . '/' . Validate::resourceId($id), null, $options);
    }

    /**
     * Updates a contact. The API requires `email`; `userId: null` clears the external user ID.
     *
     * @param array{
     *     email: string,
     *     userId?: string|null,
     *     firstName?: string|null,
     *     lastName?: string|null,
     *     phoneNumber?: string|null,
     *     properties?: array<string, string|int|float|null>|null,
     *     emailStatus?: 'subscribed'|'unsubscribed'|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Contact
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function update(string $id, array $params, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($id);

        Validate::keys($params, self::UPDATE_KEYS, 'contact parameter');
        Validate::optionalString($params['emailStatus'] ?? null, 'emailStatus');

        $body = self::body($params, self::UPDATE_KEYS, nullable: ['userId']);

        /** @var Contact */
        return $this->transport->request('PUT', $path, $body, $options);
    }

    /**
     * Deletes a contact.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return array{success: bool}
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function delete(string $id, array $options = []): array
    {
        /** @var array{success: bool} */
        return $this->transport->request('DELETE', self::PATH . '/' . Validate::resourceId($id), null, $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $keys
     * @param list<string> $nullable
     * @return array<string, mixed>
     */
    private static function body(array $params, array $keys, array $nullable = []): array
    {
        Validate::nonEmpty($params['email'] ?? null, 'email');

        foreach (['userId', 'firstName', 'lastName', 'phoneNumber'] as $name) {
            Validate::optionalString($params[$name] ?? null, $name);
        }

        $body = Validate::pick($params, $keys, $nullable);

        if (isset($body['properties'])) {
            $body['properties'] = Validate::optionalMap($body['properties'], 'properties');
        }

        return $body;
    }
}
