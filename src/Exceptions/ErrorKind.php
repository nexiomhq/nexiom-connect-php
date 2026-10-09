<?php

declare(strict_types=1);

namespace Nexiom\Connect\Exceptions;

enum ErrorKind: string
{
    /** The API answered with an HTTP error status. */
    case Api = 'api';

    /** The request could not reach the API or the connection failed. */
    case Network = 'network';

    /** The request deadline passed, including retries and waits. */
    case Timeout = 'timeout';

    /** The API answered successfully with a body the SDK cannot read. */
    case Protocol = 'protocol';
}
