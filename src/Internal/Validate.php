<?php

declare(strict_types=1);

namespace Nexiom\Connect\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use stdClass;

/**
 * Shared argument validation. Every failure throws before a request is made.
 *
 * @internal
 */
final class Validate
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?)?$/i';

    /**
     * Rejects keys the method does not accept, so typos and tenant fields fail loudly.
     *
     * @param array<mixed> $values
     * @param list<string> $allowed
     */
    public static function keys(array $values, array $allowed, string $name): void
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NexiomValidationException(
                    sprintf('Unknown %s key "%s". Accepted keys: %s', $name, $key, implode(', ', $allowed)),
                );
            }
        }
    }

    public static function nonEmpty(mixed $value, string $name): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new NexiomValidationException("{$name} must be a non-empty string");
        }

        return $value;
    }

    /**
     * Returns the string, or null when the value is absent.
     */
    public static function optionalString(mixed $value, string $name): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new NexiomValidationException("{$name} must be a string");
        }

        return $value;
    }

    public static function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new NexiomValidationException("{$name} must be an integer between {$min} and {$max}");
        }

        return $value;
    }

    public static function optionalInteger(mixed $value, string $name, int $min, int $max): ?int
    {
        return $value === null ? null : self::integer($value, $name, $min, $max);
    }

    /**
     * Encodes an ID as one URL path segment.
     */
    public static function resourceId(mixed $value, string $name = 'id'): string
    {
        $id = self::nonEmpty($value, $name);

        // URL parsers normalize dot segments, even when percent encoded.
        if ($id === '.' || $id === '..') {
            throw new NexiomValidationException("{$name} cannot be a dot segment");
        }

        return rawurlencode($id);
    }

    /**
     * Serializes a date or ISO 8601 timestamp. The API checks the offset and allowed range.
     */
    public static function timestamp(mixed $value, string $name): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        $text = self::nonEmpty($value, $name);

        if (preg_match(self::ISO_8601, $text) !== 1) {
            throw new NexiomValidationException("{$name} must be a DateTimeInterface or an ISO 8601 timestamp");
        }

        try {
            new DateTimeImmutable($text);
        } catch (Exception) {
            throw new NexiomValidationException("{$name} must be a valid date");
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && $errors['warning_count'] > 0) {
            throw new NexiomValidationException("{$name} must be a valid date");
        }

        return $text;
    }

    public static function optionalTimestamp(mixed $value, string $name): ?string
    {
        return $value === null ? null : self::timestamp($value, $name);
    }

    /**
     * Returns a string-keyed map as a JSON object, so an empty array is sent as `{}`.
     *
     * @return array<string, mixed>|stdClass|null
     */
    public static function optionalMap(mixed $value, string $name): array|stdClass|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new NexiomValidationException("{$name} must be an array keyed by name");
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new NexiomValidationException("{$name} must be an array keyed by name");
            }
        }

        /** @var array<string, mixed> $value */
        return $value === [] ? new stdClass() : $value;
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Builds a query string from present values only.
     *
     * @param array<string, string|int|null> $values
     */
    public static function query(array $values): string
    {
        $present = array_filter($values, static fn (string|int|null $value): bool => $value !== null);

        return $present === [] ? '' : '?' . http_build_query($present, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Copies the present keys of $params, dropping nulls unless the key is in $nullable.
     *
     * @param array<string, mixed> $params
     * @param list<string> $keys
     * @param list<string> $nullable
     * @return array<string, mixed>
     */
    public static function pick(array $params, array $keys, array $nullable = []): array
    {
        $body = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            if ($params[$key] === null && !in_array($key, $nullable, true)) {
                continue;
            }

            $body[$key] = $params[$key];
        }

        return $body;
    }
}
