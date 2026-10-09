<?php

declare(strict_types=1);

namespace Nexiom\Connect\Exceptions;

use Nexiom\Connect\ResponseMetadata;
use RuntimeException;

/**
 * An API or transport failure. Does not retain request headers or the underlying transport exception.
 */
final class NexiomException extends RuntimeException
{
    /** HTTP status, or null when no response was received. */
    public readonly ?int $status;

    /** Request ID from the response headers or body, for support requests. */
    public readonly ?string $requestId;

    public function __construct(
        string $message,
        public readonly ErrorKind $kind,
        public readonly ResponseMetadata $response,

        /** Machine-readable API error code, such as `rate_limited`. */
        public readonly ?string $errorCode = null,

        /** Additional error details from the API, such as validation failures. */
        public readonly mixed $details = null,
    ) {
        parent::__construct($message, $response->status ?? 0);

        $this->status = $response->status;
        $this->requestId = $response->requestId;
    }
}
