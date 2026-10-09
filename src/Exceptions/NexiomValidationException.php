<?php

declare(strict_types=1);

namespace Nexiom\Connect\Exceptions;

use InvalidArgumentException;

/**
 * Invalid SDK configuration or arguments; thrown before making a request.
 */
final class NexiomValidationException extends InvalidArgumentException
{
}
