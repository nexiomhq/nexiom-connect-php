<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Add, verify, list, and delete sending domains. Responses keep the API's snake_case field names.
 *
 * @phpstan-type DnsRecord array{
 *     id: string,
 *     purpose: string,
 *     record_type: string,
 *     name: string,
 *     value: string,
 *     priority: int|null,
 *     status: string,
 *     required_for_sending: bool,
 *     last_checked_at?: string|null,
 *     error_code?: string|null,
 *     error_message?: string|null,
 * }
 * @phpstan-type Domain array{
 *     id: string,
 *     domain: string,
 *     status: 'pending'|'verified'|'failed',
 *     region: string,
 *     open_tracking: bool,
 *     verification_error?: string|null,
 *     verification_attempts?: int,
 *     verification_attempted_at?: string|null,
 *     dns_records?: list<DnsRecord>,
 *     last_verified_at: string|null,
 *     created_at?: string,
 *     updated_at?: string,
 * }
 * @phpstan-type ListDomainsResponse array{
 *     items: list<Domain>,
 *     total: int,
 *     page: int,
 *     limit: int,
 * }
 */
final class Domains
{
    private const PATH = '/v1/emails/domains';

    /** The API bounds page offsets; narrow larger collections with filters or search. */
    private const MAX_PAGE = 10000;

    private const STATUSES = ['pending', 'verified', 'failed'];

    private const CREATE_KEYS = ['domain', 'openTracking'];

    private const LIST_KEYS = ['page', 'limit', 'status', 'search'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Adds a sending subdomain, such as `mail.example.com`, and returns the DNS records to publish.
     * Root domains are rejected by the API.
     *
     * @param array{domain: string, openTracking?: bool|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Domain
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function create(array $params, array $options = []): array
    {
        Validate::keys($params, self::CREATE_KEYS, 'domain parameter');
        Validate::nonEmpty($params['domain'] ?? null, 'domain');

        $openTracking = $params['openTracking'] ?? null;
        if ($openTracking !== null && !is_bool($openTracking)) {
            throw new NexiomValidationException('openTracking must be a boolean');
        }

        $body = Validate::pick($params, self::CREATE_KEYS);

        /** @var Domain */
        return $this->transport->request('POST', self::PATH, $body, $options);
    }

    /**
     * Lists sending domains, 50 per page by default and at most 100.
     *
     * @param array{
     *     page?: int|null,
     *     limit?: int|null,
     *     status?: 'pending'|'verified'|'failed'|null,
     *     search?: string|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ListDomainsResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, self::LIST_KEYS, 'domain list parameter');

        $query = Validate::query([
            'page' => Validate::optionalInteger($params['page'] ?? null, 'page', 1, self::MAX_PAGE),
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'status' => Validate::optionalOneOf($params['status'] ?? null, 'status', self::STATUSES),
            'search' => Validate::optionalString($params['search'] ?? null, 'search'),
        ]);

        /** @var ListDomainsResponse */
        return $this->transport->request('GET', self::PATH . $query, null, $options);
    }

    /**
     * Gets a sending domain with its DNS records.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Domain
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $domainId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($domainId, 'domainId');

        /** @var Domain */
        return $this->transport->request('GET', $path, null, $options);
    }

    /**
     * Checks the domain's DNS records now. A successful call means the check ran; read `status`
     * before sending. Checking twice is safe, so the call is retried like a read.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Domain
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function verify(string $domainId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($domainId, 'domainId') . '/verify';

        /** @var Domain */
        return $this->transport->request('POST', $path, null, $options, retryable: true);
    }

    /**
     * Deletes a sending domain. This affects future sends that use it.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return array{success: bool}
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function delete(string $domainId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($domainId, 'domainId');

        /** @var array{success: bool} */
        return $this->transport->request('DELETE', $path, null, $options);
    }
}
