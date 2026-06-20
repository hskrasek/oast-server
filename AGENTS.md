# AGENTS.md

This file provides guidance to coding agents (Claude Code, etc.) when working in this repository.

## Project

`oast-server` — the Laravel core of the oast.sh platform. Currently a fresh Laravel 13 skeleton; the intended architecture and roadmap live in `docs/` (read those before building feature work).

## Commands

JavaScript tooling uses **Bun**, not npm/pnpm (note `bun.lock` and `.npmrc`).

| Task | Command |
|------|---------|
| Run all dev services | `composer dev` (concurrently: `artisan serve`, `queue:listen`, `pail` log tailer, `vite`) |
| Run the full test suite | `composer test` (clears config cache, then `artisan test`) |
| Run tests directly | `vendor/bin/pest` |
| Run a single test file | `vendor/bin/pest tests/Feature/ExampleTest.php` |
| Run tests matching a name | `vendor/bin/pest --filter='it does something'` |
| Format code | `vendor/bin/pint` (dirty only: `vendor/bin/pint --dirty`) |
| Tail logs | `php artisan pail` |
| One-time project setup | `composer setup` |

## Architecture notes

- **Testing is Pest 4** (`tests/Pest.php`), not raw PHPUnit. `RefreshDatabase` is applied to the `Feature` suite, so feature tests migrate a fresh in-memory DB each run.
- **Tests run against in-memory SQLite** (`phpunit.xml` sets `DB_DATABASE=:memory:`); local/dev uses a SQLite file.
- **Frontend**: Vite 8 + Tailwind CSS 4 (`@tailwindcss/vite` plugin, no `tailwind.config.js` — Tailwind 4 is CSS-config-first).
- The app is otherwise stock Laravel scaffolding (`app/Models/User.php`, default providers, default migrations).

## Conventions

- Format with Pint before committing; there is no `pint.json`, so it uses the default Laravel preset.
- Write tests alongside implementation using Pest's functional style (`it(...)`, `test(...)`, `expect(...)`).
