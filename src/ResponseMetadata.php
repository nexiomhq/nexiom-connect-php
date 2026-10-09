<?php

declare(strict_types=1);

namespace Nexiom\Connect;

/**
 * What the SDK knows about the last HTTP attempt of a failed request.
 */
final class ResponseMetadata
{
    /**
     * @param array<string, list<string>> $headers Response headers with lowercase names.
     */
    public function __construct(
        /** HTTP status, or null when no response was received. */
        public readonly ?int $status = null,
        public readonly array $headers = [],
        public readonly ?string $requestId = null,

        /** The email send idempotency key. Reuse it to retry the same email safely. */
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
