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
| Template `status` or `origin`, or domain `status`, outside the values the API accepts | `NexiomValidationException` listing the accepted values | `ValidationTest::testInvalidArguments` |
| Template `beforeVersion` below 1, or a version `limit` outside 1–100 | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Suppression `search` blank or longer than 255 characters, or `cursor` empty or longer than 400 characters | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
| Domain create without `domain`, or with a non-boolean `openTracking` | `NexiomValidationException` | `ValidationTest::testInvalidArguments` |
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
| Template variables or versions that are not a list of objects | `NexiomException` of kind `protocol` | `TemplatesTest::testMalformedListsAreProtocolErrors` |

## Retries and deadlines

| Scenario | Expected | Test |
| --- | --- | --- |
| HTTP 408, 429, 500, 502, 503, or 504 on an email send | Retried with the same idempotency key and body | `TransportTest::testEmailSendRetriesWithSameKey` |
| Same statuses on reads, cancels, and domain verification | Retried up to `maxRetries` | `TransportTest::testReadsRetryBoundedTimes`, `EmailsTest::testCancelAndDeliveryReadsRetry`, `DomainsTest::testVerifyRetries` |
| Same statuses on contact or property mutations, reschedules, and domain creation or deletion | Never retried, whatever `maxRetries` is | `TransportTest::testMutationsNeverRetry` |
| Network failure | `NexiomException` of kind `network`; the transport exception is not retained, so the API key cannot leak through it | `TransportTest::testNetworkFailureIsRedacted` |
| Network failure after a retried HTTP error | Response metadata from the earlier attempt is cleared | `TransportTest::testNetworkFailureClearsStaleMetadata` |
| `Retry-After` (seconds or HTTP date) that ends after the deadline | The API error is thrown at once instead of waiting into a timeout | `TransportTest::testRetryAfterBeyondDeadlineFailsFast` |
| `Retry-After` within the deadline | Honored | `TransportTest::testRetryAfterWithinDeadlineIsHonored` |
| Server does not answer before the deadline | `NexiomException` of kind `timeout`; the deadline covers every attempt and wait | `HttpServerTest::testSlowServerTimesOut`, `TransportTest::testPerRequestOverrides` |
| Request times out after the SDK generated an idempotency key | The key is on `$e->response->idempotencyKey`, so the caller can retry safely | `HttpServerTest::testSlowServerTimesOut` |
| Per-request `timeout` or `maxRetries` | Overrides the client default for that call only | `TransportTest::testPerRequestOverrides` |

## Messaging

| Scenario | Expected | Test |
| --- | --- | --- |
| `channel` missing, or a channel not available yet such as `sms` | `NexiomValidationException` listing the channels, before any request | `MessagingTest::testInvalidArgumentsFailBeforeAnyRequest` |
| `from` or `to` missing, or not an E.164 number (a local number, a sender ID such as `was_1`) | `NexiomValidationException` before any request | `MessagingTest::testInvalidArgumentsFailBeforeAnyRequest` |
| `template` missing or without an `id`; an unknown key such as `senderId`, `sender`, `templateId`, or `orgId`; a template key other than `id` or `variables`; a variables key other than `header` or `body` | `NexiomValidationException` naming the key | `MessagingTest::testInvalidArgumentsFailBeforeAnyRequest` |
| Variables given as a list, a key that is not a variable number or name, or a value that is not a string or is longer than 1,024 characters | `NexiomValidationException` | `MessagingTest::testInvalidArgumentsFailBeforeAnyRequest` |
| Numbered variables written as PHP integer keys (`[1 => 'Amina']`) | Sent as a JSON object `{"1":"Amina"}`; an empty map is sent as `{}` | `MessagingTest::testSendPostsTheTemplateSendWithAnIdempotencyKey` |
| Idempotency key with whitespace, non-ASCII, or more than 200 characters | `NexiomValidationException` | `MessagingTest::testInvalidArgumentsFailBeforeAnyRequest` |
| HTTP 408, 429, 500, 502, 503, or 504 on a send | Retried with the same idempotency key and body | `MessagingTest::testSendsAndReadsRetryTransientFailures` |
| HTTP 409 or 422 on a send, such as `whatsapp_template_not_approved` | `NexiomException` with the status and code; never retried | `MessagingTest::testApiRefusalsAreNotRetried` |
| A send or message read whose `data` is not a message of a known channel | `NexiomException` of kind `protocol` | `MessagingTest::testMalformedMessagesAreProtocolErrors` |

## Webhook verification

| Scenario | Expected | Test |
| --- | --- | --- |
| `x-nexiom-timestamp` or `x-nexiom-signature` missing or blank | `NexiomWebhookVerificationException` with reason `missing_headers` | `WebhooksTest::testMissingOrMalformedHeadersAreRefused` |
| Timestamp that is not whole Unix seconds, or too large to compare | Reason `invalid_timestamp` | `WebhooksTest::testMissingOrMalformedHeadersAreRefused` |
| Timestamp more than the tolerance (default 300 seconds) before or after now | Reason `timestamp_out_of_tolerance`; a custom `tolerance` widens or narrows it | `WebhooksTest::testTimestampsOutsideTheToleranceAreRefused` |
| Body, timestamp, secret, or signature changed, including re-encoded JSON or a secret without its `whsec_` prefix | Reason `invalid_signature`; the comparison takes constant time | `WebhooksTest::testChangedRequestsAreRefused` |
| Correctly signed body that is not JSON, or not an envelope with `id`, `type`, integer `schema_version`, object `data`, and `created_at` | Reason `invalid_payload` | `WebhooksTest::testSignedBodiesThatAreNotEventsAreRefused` |
| Headers from `getallheaders()` or Laravel's `$request->headers->all()` (lists), in any letter case | Read case-insensitively; the first value is used | `WebhooksTest::testHeadersAreReadCaseInsensitivelyFromStringsAndLists` |
| Empty secret or a tolerance below 1 | `NexiomValidationException`, not a verification failure | `WebhooksTest::testInvalidArgumentsAreProgrammingErrors` |
| Event type the SDK does not know yet | Verified and returned | `WebhooksTest::testSignedRequestsReturnTheirEvent` |
