# Changelog

## 0.3.0 — Unreleased

- Add `webhooks->constructEvent($payload, $headers, $secret, tolerance: 300)`. It verifies a webhook request's HMAC-SHA256 signature and timestamp, and returns the event as an array. Failures throw `NexiomWebhookVerificationException` with a `reason`. Headers may come from `getallheaders()` or Laravel's `$request->headers->all()`.
- Add `whatsapp->messages->send` to send an approved WhatsApp template from a connected number, named by its phone number, and `whatsapp->messages->get`. Sends always carry an idempotency key and retry with it; reads retry.

## 0.2.0 — 2026-10-10

- Add `templates->list`, `templates->get`, `templates->variables`, and `templates->versions` to read email templates.
- Add `domains->create`, `domains->list`, `domains->get`, `domains->verify`, and `domains->delete` to manage sending domains. Verification is retried like a read; creating and deleting are not retried.
- Add `emails->suppressions->list` to read suppressed addresses and why they are suppressed.

## 0.1.0 — 2026-10-09

First release, with the same API coverage as the Node.js SDK 0.2.1.

- Add `NexiomConnect` with email sending, scheduled sends (`emails->reschedule`, `emails->cancel`), and email logs (`emails->list`, `emails->get`).
- Add contact CRUD and contact property management.
- Include idempotent email retries, total request deadlines, `Retry-After` handling, and typed exceptions.
- Support PHP 8.2 or later with Guzzle 7.9 or 8. Licensed under Apache-2.0.
