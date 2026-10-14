<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use JsonException;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Exceptions\NexiomWebhookVerificationException;
use Nexiom\Connect\Internal\Validate;
use SensitiveParameter;
use stdClass;

/**
 * Verify webhook requests. Makes no network request.
 *
 * @phpstan-type WebhookEvent array{
 *     id: string,
 *     type: string,
 *     schema_version: int,
 *     data: array<string, mixed>,
 *     created_at: string,
 * }
 */
final class Webhooks
{
    private const TIMESTAMP_HEADER = 'x-nexiom-timestamp';

    private const SIGNATURE_HEADER = 'x-nexiom-signature';

    /**
     * Checks a webhook request's signature and timestamp, then returns its event.
     *
     * Pass the raw body exactly as received (`$request->getContent()` in Laravel, or
     * `file_get_contents('php://input')`), the request headers (`$request->headers->all()` or
     * `getallheaders()`), and the endpoint's signing secret (`whsec_…`).
     *
     * @param array<array-key, mixed> $headers Header values may be strings or lists of strings.
     * @param int $tolerance Allowed difference between the signature time and now, in seconds.
     * @return WebhookEvent
     *
     * @throws NexiomWebhookVerificationException When the request is not genuine or not current.
     * @throws NexiomValidationException When an argument is invalid.
     */
    public function constructEvent(
        string $payload,
        array $headers,
        #[SensitiveParameter] string $secret,
        int $tolerance = 300,
    ): array {
        Validate::nonEmpty($secret, 'secret');
        Validate::integer($tolerance, 'tolerance', 1, PHP_INT_MAX);

        $timestamp = self::header($headers, self::TIMESTAMP_HEADER);
        $signature = self::header($headers, self::SIGNATURE_HEADER);

        if ($timestamp === null || $signature === null) {
            throw new NexiomWebhookVerificationException(
                'Missing ' . self::TIMESTAMP_HEADER . ' or ' . self::SIGNATURE_HEADER . ' header',
                NexiomWebhookVerificationException::MISSING_HEADERS,
            );
        }

        // Unix seconds; 16 digits reaches past the year 300,000,000, and keeps the value an integer.
        if (preg_match('/^\d{1,16}$/', $timestamp) !== 1) {
            throw new NexiomWebhookVerificationException(
                self::TIMESTAMP_HEADER . ' must be a Unix time in seconds',
                NexiomWebhookVerificationException::INVALID_TIMESTAMP,
            );
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new NexiomWebhookVerificationException(
                'The webhook timestamp is outside the allowed tolerance',
                NexiomWebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        if (preg_match('/^[0-9a-f]{64}$/i', $signature) !== 1 || !hash_equals($expected, strtolower($signature))) {
            throw new NexiomWebhookVerificationException(
                'The webhook signature does not match the payload and secret',
                NexiomWebhookVerificationException::INVALID_SIGNATURE,
            );
        }

        return self::event($payload);
    }

    /**
     * The first value of a header, matched case-insensitively, or null when it is absent or blank.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $first = is_array($value) ? (array_values($value)[0] ?? null) : $value;

            if (!is_string($first) || trim($first) === '') {
                return null;
            }

            return trim($first);
        }

        return null;
    }

    /**
     * @return WebhookEvent
     *
     * @throws NexiomWebhookVerificationException
     */
    private static function event(string $payload): array
    {
        try {
            // Objects first, so a JSON array is not mistaken for the `data` object.
            $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (
            !$decoded instanceof stdClass
            || !is_string($decoded->id ?? null)
            || !is_string($decoded->type ?? null)
            || !is_int($decoded->schema_version ?? null)
            || !($decoded->data ?? null) instanceof stdClass
            || !is_string($decoded->created_at ?? null)
        ) {
            throw new NexiomWebhookVerificationException(
                'The webhook payload is not an event envelope',
                NexiomWebhookVerificationException::INVALID_PAYLOAD,
            );
        }

        /** @var WebhookEvent */
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
