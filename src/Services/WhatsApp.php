<?php

declare(strict_types=1);

namespace Nexiom\Connect\Services;

use Nexiom\Connect\Internal\Transport;

/**
 * WhatsApp messaging.
 */
final class WhatsApp
{
    public readonly WhatsAppMessages $messages;

    /** @internal */
    public function __construct(Transport $transport)
    {
        $this->messages = new WhatsAppMessages($transport);
    }
}
