<?php

declare(strict_types=1);

namespace Nexiom\Connect\Exceptions;

use RuntimeException;

/**
 * A webhook request that is not a genuine, current Nexiom Connect event. Respond with 400.
 */
final class NexiomWebhookVerificationException extends RuntimeException
{
    public const MISSING_HEADERS = 'missing_headers';

    public const INVALID_TIMESTAMP = 'invalid_timestamp';

    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const INVALID_PAYLOAD = 'invalid_payload';

    /**
     * @param self::MISSING_HEADERS|self::INVALID_TIMESTAMP|self::TIMESTAMP_OUT_OF_TOLERANCE|self::INVALID_SIGNATURE|self::INVALID_PAYLOAD $reason
     */
    public function __construct(
        string $message,

        /** Which check failed, such as `invalid_signature`. */
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
