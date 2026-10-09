# Contributing to the Nexiom Connect PHP SDK

Thanks for contributing to **`nexiom/connect-php`**, the official PHP SDK from Nexiom Technologies.

**License:** [Apache License 2.0](./LICENSE).

For installation and usage, see the [README](./README.md) and [examples](./examples).

## Development setup

Requires **PHP 8.2 or later** with the cURL extension, and **Composer**.

Fork the repository, clone your fork, and create a branch for your change:

```sh
git clone git@github.com:YOUR_USERNAME/nexiom-connect-php.git
cd nexiom-connect-php
git switch -c fix/describe-your-change

composer install
composer check
```

| Command | Purpose |
| --- | --- |
| `composer test` | Run the PHPUnit suite |
| `composer analyse` | Run PHPStan at the maximum level over source, tests, and examples |
| `composer check` | Validate `composer.json`, then run static analysis and the tests |

CI runs `composer check` on PHP **8.2, 8.3, 8.4, and 8.5**, and the tests with the lowest supported dependencies (Guzzle 7). Tests use an in-process Guzzle handler and PHP's built-in web server; they do not require an API key or a live account.

Read [tests/failure-scenarios.md](./tests/failure-scenarios.md) before changing behavior. Add the scenario there first, then the code, then the test that covers it.

## Project layout

```text
src/
  NexiomConnect.php           # Entry point and VERSION
  ResponseMetadata.php        # Status, headers, request ID, and idempotency key of a failure
  Exceptions/
    ErrorKind.php             # api, network, timeout, protocol
    NexiomException.php       # API and transport failures
    NexiomValidationException.php
  Internal/
    Transport.php             # HTTP transport, retries, deadlines, response parsing
    Validate.php              # Shared argument validation
  Services/
    Emails.php                # Sending, scheduling, and email logs
    EmailSuppressions.php     # Suppressed addresses
    Contacts.php              # Contact CRUD and listing
    ContactProperties.php     # Contact property management
    Templates.php             # Templates, variables, and versions (read-only)
    Domains.php               # Sending domains
tests/
  failure-scenarios.md        # Every failure mode and the test that covers it
  Support/                    # Recording Guzzle handler and built-in server router
  *Test.php                   # Resource contracts, transport behavior, validation
examples/                     # Public usage examples
```

## Supported resources

Keep changes within the SDK's supported API surface unless a new feature has been agreed on in an issue.

| Resource | Methods |
| --- | --- |
| `$nexiomConnect->emails` | `send($params, $options)`, `cancel($messageId)`, `reschedule($messageId, $params)`, `list($params)`, `get($deliveryId)` |
| `$nexiomConnect->contacts` | `create($params)`, `list($params)`, `get($id)`, `update($id, $params)`, `delete($id)` |
| `$nexiomConnect->emails->suppressions` | `list($params)` |
| `$nexiomConnect->contacts->properties` | `create($params)`, `list($params)`, `update($id, $params)`, `delete($id)` |
| `$nexiomConnect->templates` | `list($params)`, `get($templateId)`, `variables($templateId)`, `versions($templateId, $params)` |
| `$nexiomConnect->domains` | `create($params)`, `list($params)`, `get($domainId)`, `verify($domainId)`, `delete($domainId)` |

Every method accepts request options (`timeout`, `maxRetries`) as its final argument. Email sending also accepts `idempotencyKey`.

## Conventions

| Topic | Convention |
| --- | --- |
| Initialization | `new NexiomConnect($apiKey, baseUrl: ..., timeout: ..., maxRetries: ..., httpClient: ...)` |
| Base URL | Defaults to `https://api-connect.nxiom.com/api`; versioned paths belong to each service |
| Authentication | Bearer API key; do not add organization or project scope parameters |
| Parameters | Arrays with camelCase keys matching the Node.js SDK; unknown keys throw |
| Results | Methods return the API's data as arrays; document shapes with `@phpstan-type` |
| Errors | API and transport failures throw `NexiomException`; invalid arguments throw `NexiomValidationException` before a request |
| Request fields | Preserve the API's field names; map property `name` to `key` and `fallbackValue` to `fallback_value` |
| Response fields | Preserve API field names, including snake_case fields; dates remain ISO 8601 strings |
| Retries | Retry reads, idempotent email sends, cancels, and domain verification only; reuse the email key across attempts |
| Secrets | Never keep the API key in a stack frame argument, exception, or debug output |
| Internals | Classes in `Internal/` and service constructors are `@internal` and may change in any release |
| Dependencies | Guzzle is the only runtime dependency |

Follow PSR-12 with `declare(strict_types=1)`, four-space indentation, and single quotes. Separate methods and logical blocks with blank lines, and use braces for conditionals and loops. Keep examples short, readable, and focused on one action.

## Releasing

1. Update `NexiomConnect::VERSION` and `CHANGELOG.md` in one pull request.
2. After it merges, create a GitHub release with the tag `v` followed by the version, such as `v0.1.0`.
3. Packagist publishes the tag. CI checks that the tag matches `NexiomConnect::VERSION`.
