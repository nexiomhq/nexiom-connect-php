<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Read email templates, their variables, and their version history. Responses keep the API's
 * snake_case field names.
 *
 * @phpstan-type TemplateVariable array{
 *     id: string,
 *     key: string,
 *     type: 'string'|'number',
 *     required: bool,
 *     fallback_value: string|int|float|null,
 * }
 * @phpstan-type TemplateVersion array{
 *     id: string,
 *     template_id: string,
 *     version_number: int,
 *     status: 'draft'|'published'|'saved',
 *     subject: string|null,
 *     preview_text: string|null,
 *     html: string|null,
 *     text: string|null,
 *     published_at: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     variables: list<TemplateVariable>,
 * }
 * @phpstan-type Template array{
 *     id: string,
 *     name: string,
 *     alias: string,
 *     description: string|null,
 *     category: string|null,
 *     origin: 'custom'|'prebuilt',
 *     published_version_id: string|null,
 *     draft_version_id: string|null,
 *     archived_at: string|null,
 *     usage_count: int,
 *     last_used_at: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     published_version: TemplateVersion|null,
 *     draft_version: TemplateVersion|null,
 * }
 * @phpstan-type ListTemplatesResponse array{
 *     items: list<Template>,
 *     total: int,
 *     page: int,
 *     limit: int,
 * }
 */
final class Templates
{
    private const PATH = '/v1/emails/templates';

    /** The API bounds page offsets; narrow larger collections with filters or search. */
    private const MAX_PAGE = 10000;

    private const STATUSES = ['draft', 'published', 'changes_in_draft', 'archived'];

    private const ORIGINS = ['custom', 'prebuilt'];

    private const LIST_KEYS = ['page', 'limit', 'status', 'search', 'origin', 'category'];

    private const VERSION_KEYS = ['limit', 'beforeVersion'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Lists templates, most recently updated first, 50 per page by default and at most 100.
     *
     * @param array{
     *     page?: int|null,
     *     limit?: int|null,
     *     status?: 'draft'|'published'|'changes_in_draft'|'archived'|null,
     *     search?: string|null,
     *     origin?: 'custom'|'prebuilt'|null,
     *     category?: string|null,
     * } $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ListTemplatesResponse
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, self::LIST_KEYS, 'template list parameter');

        $query = Validate::query([
            'page' => Validate::optionalInteger($params['page'] ?? null, 'page', 1, self::MAX_PAGE),
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'status' => Validate::optionalOneOf($params['status'] ?? null, 'status', self::STATUSES),
            'search' => Validate::optionalString($params['search'] ?? null, 'search'),
            'origin' => Validate::optionalOneOf($params['origin'] ?? null, 'origin', self::ORIGINS),
            'category' => Validate::optionalString($params['category'] ?? null, 'category'),
        ]);

        /** @var ListTemplatesResponse */
        return $this->transport->request('GET', self::PATH . $query, null, $options);
    }

    /**
     * Gets a template with its published and draft versions.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return Template
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function get(string $templateId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($templateId, 'templateId');

        /** @var Template */
        return $this->transport->request('GET', $path, null, $options);
    }

    /**
     * Lists a template's variables, ordered by key: the draft's when the template has one,
     * otherwise the published version's. Sends use `published_version.variables` from `get()`.
     *
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return list<TemplateVariable>
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function variables(string $templateId, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($templateId, 'templateId') . '/variables';

        /** @var list<TemplateVariable> */
        return $this->transport->request('GET', $path, null, $options, accepts: self::isList(...));
    }

    /**
     * Lists a template's versions, newest first. For older versions, pass the last
     * `version_number` as `beforeVersion`; a response with fewer than `limit` versions is the last.
     *
     * @param array{limit?: int|null, beforeVersion?: int|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return list<TemplateVersion>
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function versions(string $templateId, array $params = [], array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($templateId, 'templateId') . '/versions';

        Validate::keys($params, self::VERSION_KEYS, 'template version parameter');

        $query = Validate::query([
            'limit' => Validate::optionalInteger($params['limit'] ?? null, 'limit', 1, 100),
            'beforeVersion' => Validate::optionalInteger(
                $params['beforeVersion'] ?? null,
                'beforeVersion',
                1,
                PHP_INT_MAX,
            ),
        ]);

        /** @var list<TemplateVersion> */
        return $this->transport->request('GET', $path . $query, null, $options, accepts: self::isList(...));
    }

    /**
     * @param array<mixed> $data
     */
    private static function isList(array $data): bool
    {
        if (!array_is_list($data)) {
            return false;
        }

        foreach ($data as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }
}
