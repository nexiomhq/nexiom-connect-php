<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Internal\Validate;

/**
 * Manage the custom properties stored on contacts.
 *
 * @phpstan-type ContactProperty array{
 *     id: string,
 *     key: string,
 *     type: 'string'|'number'|'date',
 *     fallback_value: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     deleted_at: string|null,
 * }
 */
final class ContactProperties
{
    private const PATH = '/v1/emails/properties';

    private const TYPES = ['string', 'number', 'date'];

    private const KEYS = ['name', 'type', 'fallbackValue'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Creates a property. The API stores fallback values as strings, including numbers and dates.
     *
     * @param array{name: string, type: 'string'|'number'|'date', fallbackValue?: string|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ContactProperty
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function create(array $params, array $options = []): array
    {
        Validate::keys($params, self::KEYS, 'property parameter');

        self::name($params['name'] ?? null);
        self::type($params['type'] ?? null);

        if (array_key_exists('fallbackValue', $params)) {
            self::fallback($params['fallbackValue']);
        }

        /** @var ContactProperty */
        return $this->transport->request('POST', self::PATH, self::body($params), $options);
    }

    /**
     * Lists properties. Optional `type` and case-insensitive `search` filters are applied locally
     * to the complete collection.
     *
     * @param array{type?: 'string'|'number'|'date'|null, search?: string|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return list<ContactProperty>
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function list(array $params = [], array $options = []): array
    {
        Validate::keys($params, ['type', 'search'], 'property list parameter');

        $type = Validate::optionalString($params['type'] ?? null, 'type');
        $search = Validate::optionalString($params['search'] ?? null, 'search');

        /** @var list<ContactProperty> $properties */
        $properties = $this->transport->request(
            'GET',
            self::PATH,
            null,
            $options,
            accepts: self::isCollection(...),
        );

        $needle = $search === null || $search === '' ? null : Validate::lower($search);

        $matches = array_filter(
            $properties,
            static fn (array $property): bool => ($type === null || $type === '' || $property['type'] === $type)
                && ($needle === null || str_contains(Validate::lower($property['key']), $needle)),
        );

        return array_values($matches);
    }

    /**
     * Renames a property, changes its type, or sets its fallback. Provide at least one field.
     * A type can change only while no contact has a value for the property.
     *
     * @param array{name?: string|null, type?: 'string'|'number'|'date'|null, fallbackValue?: string|null} $params
     * @param array{timeout?: float|int, maxRetries?: int} $options
     * @return ContactProperty
     *
     * @throws NexiomException
     * @throws NexiomValidationException
     */
    public function update(string $id, array $params, array $options = []): array
    {
        $path = self::PATH . '/' . Validate::resourceId($id);

        Validate::keys($params, self::KEYS, 'property parameter');

        if (!isset($params['name']) && !isset($params['type']) && !array_key_exists('fallbackValue', $params)) {
            throw new NexiomValidationException('name, type, or fallbackValue is required');
        }
        if (isset($params['name'])) {
            self::name($params['name']);
        }
        if (isset($params['type'])) {
            self::type($params['type']);
        }
        if (array_key_exists('fallbackValue', $params)) {
            self::fallback($params['fallbackValue']);
        }

        /** @var ContactProperty */
        return $this->transport->request('PATCH', $path, self::body($params), $options);
    }

    /**
     * Deletes a property.
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
     * Maps SDK names to the API's: `name` to `key` and `fallbackValue` to `fallback_value`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function body(array $params): array
    {
        $body = [];

        if (isset($params['name'])) {
            $body['key'] = $params['name'];
        }
        if (isset($params['type'])) {
            $body['type'] = $params['type'];
        }
        if (array_key_exists('fallbackValue', $params)) {
            $body['fallback_value'] = $params['fallbackValue'];
        }

        return $body;
    }

    private static function name(mixed $value): void
    {
        $name = Validate::nonEmpty($value, 'name');

        if (Validate::length(trim($name)) > 255) {
            throw new NexiomValidationException('name must contain at most 255 characters');
        }
    }

    private static function type(mixed $value): void
    {
        if (!in_array($value, self::TYPES, true)) {
            throw new NexiomValidationException('type must be string, number, or date');
        }
    }

    private static function fallback(mixed $value): void
    {
        if ($value !== null && (!is_string($value) || Validate::length($value) > 1000)) {
            throw new NexiomValidationException(
                'fallbackValue must be null or a string of at most 1000 characters',
            );
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function isCollection(array $data): bool
    {
        if (!array_is_list($data)) {
            return false;
        }

        foreach ($data as $property) {
            if (!is_array($property) || !is_string($property['key'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
