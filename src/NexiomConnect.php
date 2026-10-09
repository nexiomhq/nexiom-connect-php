<?php

declare(strict_types=1);

namespace Nexiom\Connect;

use GuzzleHttp\ClientInterface;
use Nexiom\Connect\Exceptions\NexiomValidationException;
use Nexiom\Connect\Internal\Transport;
use Nexiom\Connect\Services\Contacts;
use Nexiom\Connect\Services\Domains;
use Nexiom\Connect\Services\Emails;
use Nexiom\Connect\Services\Templates;
use SensitiveParameter;

/**
 * The Nexiom Connect API client.
 *
 * ```php
 * $nexiomConnect = new NexiomConnect('nc_your_api_key_here');
 * ```
 */
final class NexiomConnect
{
    public const VERSION = '0.2.0';

    public readonly Emails $emails;

    public readonly Contacts $contacts;

    public readonly Templates $templates;

    public readonly Domains $domains;

    /**
     * @param string $apiKey An API key from the Nexiom Connect dashboard. Keep it on the server.
     * @param string|null $baseUrl API root without a version prefix. Default: https://api-connect.nxiom.com/api.
     * @param float|int $timeout Total request deadline in seconds, including retries. Default: 30.
     * @param int $maxRetries Additional attempts for reads, idempotent email sends, and cancels only. Default: 2.
     * @param ClientInterface|null $httpClient A Guzzle client for proxies, instrumentation, or testing.
     *
     * @throws NexiomValidationException
     */
    public function __construct(
        #[SensitiveParameter] string $apiKey,
        ?string $baseUrl = null,
        float|int $timeout = 30,
        int $maxRetries = 2,
        ?ClientInterface $httpClient = null,
    ) {
        $transport = new Transport($apiKey, $baseUrl, $timeout, $maxRetries, $httpClient);

        $this->emails = new Emails($transport);
        $this->contacts = new Contacts($transport);
        $this->templates = new Templates($transport);
        $this->domains = new Domains($transport);
    }
}
