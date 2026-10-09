# Changelog

## 0.1.0 — Unreleased

First release, with the same API coverage as the Node.js SDK 0.2.1.

- Add `NexiomConnect` with email sending, scheduled sends (`emails->reschedule`, `emails->cancel`), and email logs (`emails->list`, `emails->get`).
- Add contact CRUD and contact property management.
- Include idempotent email retries, total request deadlines, `Retry-After` handling, and typed exceptions.
- Support PHP 8.2 or later with Guzzle 7.9 or 8. Licensed under Apache-2.0.
