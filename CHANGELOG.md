# Changelog

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
