<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Read the addresses this project cannot send to, and why.
 *
 * @phpstan-type EmailSuppression array{
 *     email: string,
 *     reason: 'hard_bounce'|'complaint'|'unsubscribed'|'invalid'|'manual'|'temporary_failure',
 * }
 * @phpstan-type ListEmailSuppressionsResponse array{
 *     items: list<EmailSuppression>,
 *     nextCursor: string|null,
 *     hasMore: bool,
 * }
 */
final class EmailSuppressions
{
    private const PATH = '/v1/emails/suppressions';

    private const LIST_KEYS = ['search', 'limit', 'cursor'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Lists suppressed addresses alphabetically, 50 per page by default and at most 100.
     * Pass `nextCursor` as `cursor` while `hasMore` is true.
     *
     * @param array{search?: string|null, limit?: int|null, cursor?: string|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ListEmailSuppressionsResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, self::LIST_KEYS, 'suppression list parameter');

        $search = Validate::optionalString($params['search'] ?? null, 'search');
        if ($search !== null) {
            $search = trim($search);

            if ($search === '' || Validate::length($search) > 255) {
                throw new NexiomValidationException('search must contain 1 to 255 characters');
            }
        }

        $cursor = Validate::optionalString($params['cursor'] ?? null, 'cursor');
        if ($cursor !== null && ($cursor === '' || strlen($cursor) > 400)) {
            throw new NexiomValidationException('cursor must contain 1 to 400 characters');
        }

        $query = Validate::query([
            'search' => $search,
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'cursor' => $cursor,
        ]);

        /** @var ListEmailSuppressionsResponse */
        return $this->transport->request('GET', self::PATH . $query, null, $options);
    }
}
