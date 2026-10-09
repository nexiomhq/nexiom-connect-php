# Failure scenarios

Every way the SDK can fail, and what it must do. Read this before changing behavior, and add a scenario before adding coverage. Each scenario names the test that covers it.

## Configuration

| Scenario | Expected | Test |
| --- | --- | --- |
| Empty API key, or a key with whitespace, control, or non-ASCII characters | `NexiomValidationException` before any request | `ValidationTest::testInvalidConfiguration` |
| Base URL is empty, relative, not HTTP(S), or has credentials, a query, or a fragment | `NexiomValidationException` | `ValidationTest::testInvalidConfiguration` |
| Base URL uses plain HTTP on a non-loopback host | `NexiomValidationException`; HTTP is allowed only on `localhost`, `127.0.0.1`, and `[::1]` | `ValidationTest::testInvalidConfiguration` |
| Timeout is zero, negative, infinite, or NaN; `maxRetries` is outside 0–10 or not an integer | `NexiomValidationException`, in the constructor and in per-request options | `ValidationTest::testInvalidConfiguration`, `ValidationTest::testInvalidArguments` |
| Unknown request option key, such as `idempotency_key` | `NexiomValidationException` naming the key | `ValidationTest::testInvalidArguments` |
| `var_dump`, `print_r`, or a stack trace of the client | The API key never appears | `TransportTest::testApiKeyIsNotExposed` |

## Arguments

| Scenario | Expected | Test |
| --- | --- | --- |
| Unknown parameter key, such as `from_name`, `orgId`, or `projectId` | `NexiomValidationException` naming the key; tenant scope is never sent | `ValidationTest::testInvalidArguments` |
| Empty, `.` or `..` resource ID | `NexiomValidationException`; other IDs are percent-encoded into one path segment | `ValidationTest::testInvalidArguments`, `ContactsTest::testContactMethods` |
| `to` missing or empty, more than 100 recipients, or a blank address | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| CC or BCC with more than one primary recipient | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| No subject, or neither `html` nor `text`, without `templateId` | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Idempotency key with whitespace, non-ASCII, or more than 128 characters | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| `scheduledAt`, `startDate`, or `endDate` that is not a `DateTimeInterface` or ISO 8601 timestamp (for example `tomorrow`) | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| `limit`, `page`, or a numeric string or float where an integer is expected | `NexiomValidationException`; pages are bounded at 10,000 | `ValidationTest::testInvalidArguments` |
| Email log cursor longer than 1,024 characters | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Contact update without `email` | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Property type other than `string`, `number`, or `date`; blank or too-long name; fallback that is not null or a string of at most 1,000 characters | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Property update with no field | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Body that cannot be encoded as JSON (invalid UTF-8, recursion) | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Empty `metadata`, `properties`, or `templateVariables` array | Sent as a JSON object `{}`, not `[]` | `EmailsTest::testEmptyMapsAreObjects` |
| Optional parameter passed as `null` | Omitted, except `userId` on contact update and `fallbackValue`, where `null` clears the value | `ContactsTest::testContactMethods`, `ContactPropertiesTest::testPropertyMethods` |

## API responses

| Scenario | Expected | Test |
| --- | --- | --- |
| HTTP 400, 401, 403, 404, 409, or 422 | `NexiomException` of kind `api` with status, code, request ID, and details; never retried | `TransportTest::testClientErrorsAreNotRetried` |
| HTTP 3xx redirect | `NexiomException` of kind `api`; never followed, so the API key is not forwarded | `TransportTest::testRedirectsAreNotFollowed`, `HttpServerTest::testRedirectIsNotFollowed` |
| HTTP error body that is not JSON | `NexiomException` keeps the HTTP status and header request ID | `TransportTest::testNonJsonErrorKeepsDiagnostics` |
| 2xx body that is empty, not JSON, `null`, a string, or `{}` | `NexiomException` of kind `protocol`; never retried | `TransportTest::testInvalidSuccessBodiesAreProtocolErrors` |
| Delivery detail that is not a JSON object | `NexiomException` of kind `protocol` | `EmailsTest::testDeliveryDetailMustBeAnObject` |
| Property collection that is not a list of objects with a string `key` | `NexiomException` of kind `protocol` | `ContactPropertiesTest::testMalformedCollectionsAreProtocolErrors` |

## Retries and deadlines

| Scenario | Expected | Test |
| --- | --- | --- |
| HTTP 408, 429, 500, 502, 503, or 504 on an email send | Retried with the same idempotency key and body | `TransportTest::testEmailSendRetriesWithSameKey` |
| Same statuses on reads and cancels | Retried up to `maxRetries` | `TransportTest::testReadsRetryBoundedTimes`, `EmailsTest::testCancelAndDeliveryReadsRetry` |
| Same statuses on contact or property mutations and reschedules | Never retried, whatever `maxRetries` is | `TransportTest::testMutationsNeverRetry` |
| Network failure | `NexiomException` of kind `network`; the transport exception is not retained, so the API key cannot leak through it | `TransportTest::testNetworkFailureIsRedacted` |
| Network failure after a retried HTTP error | Response metadata from the earlier attempt is cleared | `TransportTest::testNetworkFailureClearsStaleMetadata` |
| `Retry-After` (seconds or HTTP date) that ends after the deadline | The API error is thrown at once instead of waiting into a timeout | `TransportTest::testRetryAfterBeyondDeadlineFailsFast` |
| `Retry-After` within the deadline | Honored | `TransportTest::testRetryAfterWithinDeadlineIsHonored` |
| Server does not answer before the deadline | `NexiomException` of kind `timeout`; the deadline covers every attempt and wait | `HttpServerTest::testSlowServerTimesOut`, `TransportTest::testPerRequestOverrides` |
| Request times out after the SDK generated an idempotency key | The key is on `$e->response->idempotencyKey`, so the caller can retry safely | `HttpServerTest::testSlowServerTimesOut` |
| Per-request `timeout` or `maxRetries` | Overrides the client default for that call only | `TransportTest::testPerRequestOverrides` |
