# Nexiom Connect PHP SDK

The official PHP SDK for Nexiom Connect. Send emails and manage contacts from PHP 8.2 or later.

## Install

```sh
composer require nexiom/connect-php
```

Requires PHP 8.2 or later and Guzzle 7.9 or 8. Keep your API key on the server.

## Initialize

```php
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect('nc_your_api_key_here');

// Optional custom host:
// $nexiomConnect = new NexiomConnect(
//     apiKey: 'nc_...',
//     baseUrl: 'https://api-connect.nxiom.com/api',
// );
```

## Send email

Use a verified sending domain and an API key with email-send or full-access permission.

```php
use Nexiom\Connect\Exceptions\NexiomException;

try {
    $email = $nexiomConnect->emails->send([
        'from' => 'hello@your-verified-domain.com',
        'fromName' => 'Your team',
        'to' => 'customer@example.com',
        'subject' => 'Welcome',
        'html' => '<p>Thanks for joining us.</p>',
        'text' => 'Thanks for joining us.',
    ]);

    echo $email['messageId'];
} catch (NexiomException $error) {
    error_log($error->getMessage());
}
```

Use a bare email address for `from` and `fromName` for the display name. A successful response means the email is queued and includes `messageId`, `deliveryIds`, and `totalQueued`.

You can also send a published template with `templateId` and `templateVariables`. Optional fields include `replyTo`, `cc`, `bcc`, and `metadata`. Recipients can be one address or an array of up to 100 addresses; CC and BCC require one primary recipient.

### Idempotency

For retries across separate calls or jobs, use the same key and parameters:

```php
$nexiomConnect->emails->send(
    [
        'from' => 'orders@your-verified-domain.com',
        'to' => 'customer@example.com',
        'subject' => 'Order received',
        'text' => 'We received your order.',
    ],
    ['idempotencyKey' => 'order-123-confirmation'],
);
```

Otherwise, the SDK generates a key for each call and reuses it for automatic retries. Keys contain 1–128 printable ASCII characters without whitespace. If a send fails, the key is available as `$error->response->idempotencyKey`, including after a timeout.

### Scheduled emails

Pass `scheduledAt` (a `DateTimeInterface` or an ISO 8601 timestamp with an offset, at most 30 days ahead) to send later. Use the returned `messageId` to move or cancel it before it is sent.

```php
$email = $nexiomConnect->emails->send([
    'from' => 'hello@your-verified-domain.com',
    'to' => 'customer@example.com',
    'subject' => 'Your trial ends tomorrow',
    'text' => 'Your trial ends tomorrow.',
    'scheduledAt' => new DateTimeImmutable('+1 day'),
]);

$nexiomConnect->emails->reschedule($email['messageId'], [
    'scheduledAt' => '2026-10-06T09:00:00+01:00',
]);

$nexiomConnect->emails->cancel($email['messageId']);
```

## Email logs

| Method | Description |
| --- | --- |
| `list($params = [])` | List recipient deliveries, newest first |
| `get($deliveryId)` | Get one delivery with its content and history |

Both require a full-access API key.

```php
$page = $nexiomConnect->emails->list(['status' => 'bounced', 'limit' => 50]);

foreach ($page['items'] as $delivery) {
    echo $delivery['id'], ' ', $delivery['recipient'], ' ', $delivery['status'], PHP_EOL;
}

// Next page: pass $page['nextCursor'] as 'cursor'; it is null on the last page.
```

`list()` also accepts `source`, `recipient`, `search`, `contactId`, `templateId`, `startDate`, and `endDate`. `get()` takes a delivery ID from `deliveryIds` or `list()`, not a message ID.

### Suppressions

`emails->suppressions->list()` lists the addresses this project cannot send to, alphabetically, with the reason. It requires a full-access API key.

```php
$page = $nexiomConnect->emails->suppressions->list(['search' => 'example.com', 'limit' => 50]);

foreach ($page['items'] as $suppression) {
    echo $suppression['email'], ' ', $suppression['reason'], PHP_EOL;
}

// Next page: pass $page['nextCursor'] as 'cursor' while $page['hasMore'] is true.
```

Reasons are `hard_bounce`, `complaint`, `unsubscribed`, `invalid`, `manual`, and `temporary_failure`. `search` matches part of an address.

## Contacts

Contact methods require a full-access API key.

| Method | Description |
| --- | --- |
| `create($params)` | Create a contact |
| `list($params = [])` | List or search contacts |
| `get($id)` | Get a contact |
| `update($id, $params)` | Update a contact |
| `delete($id)` | Delete a contact |

```php
$contact = $nexiomConnect->contacts->create([
    'email' => 'ada@example.com',
    'firstName' => 'Ada',
    'lastName' => 'Lovelace',
    'properties' => ['company' => 'Example'],
]);

echo $contact['id'];
```

Creation also accepts `userId` and `phoneNumber`. Updates require `email` and accept `emailStatus` (`subscribed` or `unsubscribed`); `'userId' => null` clears the external user ID.

`list()` accepts `page` (up to 10,000), `limit`, `search`, `emailStatus`, `listId`, and `segmentId`. It returns `items`, `total`, `page`, and `limit`, with a default page size of 50 and a maximum of 100.

Responses use snake_case fields such as `first_name` and `created_at`. Dates are ISO 8601 strings. `get($id)` also returns properties and activity.

## Contact properties

| Method | Description |
| --- | --- |
| `create(['name' => ..., 'type' => ..., 'fallbackValue' => ...])` | Create a property |
| `list($params = [])` | List or filter properties |
| `update($id, ['name' => ..., 'type' => ..., 'fallbackValue' => ...])` | Rename a property, change its type, or set its fallback |
| `delete($id)` | Delete a property |

```php
$property = $nexiomConnect->contacts->properties->create([
    'name' => 'company',
    'type' => 'string',
    'fallbackValue' => 'Unknown',
]);

echo $property['id'], ' ', $property['key'];
```

Types are `string`, `number`, and `date`. A type can change only while no contact has a value for the property. Fallback values are strings or null. Responses use `key` and `fallback_value`. Optional `type` and case-insensitive `search` filters are applied locally to the complete property list.

## Templates

Template methods are read-only and require a full-access API key. Create and edit templates in the dashboard.

| Method | Description |
| --- | --- |
| `list($params = [])` | List templates, most recently updated first |
| `get($templateId)` | Get a template with its published and draft versions |
| `variables($templateId)` | List the variables of the draft, or of the published version |
| `versions($templateId, $params = [])` | List versions, newest first |

```php
$templates = $nexiomConnect->templates->list(['status' => 'published', 'limit' => 50]);

$template = $nexiomConnect->templates->get($templates['items'][0]['id']);

foreach ($template['published_version']['variables'] ?? [] as $variable) {
    echo $variable['key'], $variable['required'] ? ' (required)' : '', PHP_EOL;
}
```

`list()` accepts `page`, `limit`, `status` (`draft`, `published`, `changes_in_draft`, or `archived`), `search`, `origin` (`custom` or `prebuilt`), and `category`. Sends use the published version, so `published_version.variables` lists exactly what a send needs. `versions()` accepts `limit` and `beforeVersion`: pass the last `version_number` to read older versions.

## Sending domains

Domain methods require a full-access API key.

| Method | Description |
| --- | --- |
| `create($params)` | Add a sending subdomain and get its DNS records |
| `list($params = [])` | List sending domains |
| `get($domainId)` | Get a domain with its DNS records |
| `verify($domainId)` | Check the domain's DNS records now |
| `delete($domainId)` | Delete a domain |

```php
$domain = $nexiomConnect->domains->create(['domain' => 'mail.example.com']);

foreach ($domain['dns_records'] as $record) {
    echo $record['record_type'], ' ', $record['name'], ' ', $record['value'], PHP_EOL;
}

// After publishing the records with your DNS provider:
$domain = $nexiomConnect->domains->verify($domain['id']);

echo $domain['status'];
```

Sending domains must be subdomains, such as `mail.example.com`. `create()` also accepts `openTracking` (default `true`). `list()` accepts `page`, `limit`, `status` (`pending`, `verified`, or `failed`), and `search`. A successful `verify()` means the check ran; read `status` before sending.

## Parameters

Parameters are arrays with the camelCase keys shown above. An unknown key, such as `from_name`, throws `NexiomValidationException` instead of being ignored. A `null` value is treated as not set, except `userId` on contact updates and `fallbackValue`, where `null` clears the value.

## Request options

The default base URL is `https://api-connect.nxiom.com/api`. Custom URLs exclude `/v1` and use HTTPS, except for local development on loopback hosts.

Configure `timeout` (seconds, default 30), `maxRetries` (default 2), or a Guzzle `httpClient` when creating the client. Each method also accepts `['timeout' => ..., 'maxRetries' => ...]` as its final argument.

```php
$nexiomConnect = new NexiomConnect(
    apiKey: 'nc_...',
    timeout: 10,
    maxRetries: 3,
);

$contacts = $nexiomConnect->contacts->list([], ['timeout' => 5]);
```

The timeout covers the entire request, including retries. Reads, email sends, cancels, and domain verification retry network errors and HTTP 408, 429, 500, 502, 503, and 504, honoring `Retry-After`. When the wait would outlast the timeout, the SDK throws that API error at once. Reschedules, domain creation and deletion, and contact and property mutations are not automatically retried. The SDK never follows redirects, so your API key is only sent to the configured host.

## Errors

Methods return the response data as an array. API and transport failures throw `Nexiom\Connect\Exceptions\NexiomException`:

| Property | Description |
| --- | --- |
| `kind` | `ErrorKind::Api`, `Network`, `Timeout`, or `Protocol` |
| `getMessage()` | The API's error message, or a description of the failure |
| `status` | HTTP status, or null when no response was received |
| `errorCode` | Machine-readable API error code, such as `rate_limited` |
| `requestId` | Request ID to share with support |
| `details` | Additional API error details, such as validation failures |
| `response` | Status, headers, request ID, and the email idempotency key |

Invalid SDK configuration or locally validated arguments throw `Nexiom\Connect\Exceptions\NexiomValidationException` before any request is sent.

```php
use Nexiom\Connect\Exceptions\ErrorKind;
use Nexiom\Connect\Exceptions\NexiomException;

try {
    $nexiomConnect->contacts->get('ct_123');
} catch (NexiomException $error) {
    if ($error->kind === ErrorKind::Api && $error->status === 404) {
        // The contact does not exist.
    }
}
```

## Examples

- [Send an email](./examples/send.php)
- [Create a contact](./examples/contacts.php)
- [Create a contact property](./examples/contact-properties.php)
- [List published templates](./examples/templates.php)
- [Add a sending domain](./examples/domains.php)

## Development

```sh
composer install
composer check
```

## License

[Apache License 2.0](./LICENSE). Copyright 2026 Nexiom Technologies.
