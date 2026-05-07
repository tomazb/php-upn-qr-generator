# Project Guide for Agents

## Overview
- Library: UPN QR generator (`src/UPNQR.php`), single-class core with PHPUnit tests.
- Encoding: ISO-8859-2; payload max 411 chars; required fields `recipientIban` and `recipientCity`.
- PNG output requires `ext-imagick`; SVG/EPS do not.

## Setup
1) Install PHP >= 8.3 with mbstring, iconv; optional imagick for PNG.
2) Install deps: `composer install`.
3) Work on the current task branch; avoid switching branches unless requested.

## Commands
- Run tests: `composer test`
- Format: `composer format`

## Development Notes
- Use fluent setters; they mark the payload as dirty to refresh cache.
- Validation: setters normalize trim/empty→null; charset guard for ISO-8859-2; dates via `Y-m-d` with `DateTimeImmutable`; payload length enforced.
- Exceptions: user input ⇒ `InvalidArgumentException`; unexpected writer/IO ⇒ `QrGenerationException`; PNG without imagick ⇒ RuntimeException message.
- QR writer can be injected via `createWriter()` for testing.

## Testing Guidance
- Tests live in `tests/Unit/UPNQRTest.php`.
- PNG-related tests skip when imagick is missing; with imagick they must pass.
- Keep added tests isolated (no leftover files in `build/`).

## Docs/Plan
- Keep `plans/code-smell-performance-analysis-2026-01-11.md` updated after each phase.
- README notes imagick as optional; keep dependency notes consistent.
- Work in focused commits; do not bundle unrelated changes.

## Demo
- The demo app lives in `demo/`; keep it using the library through Composer autoload.

## Checklist before handoff
- `composer test` green.
- No unformatted code (`composer format`).
- Update plan and docs for any new behaviors or requirements.
