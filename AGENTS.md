# AGENTS.md

This file provides guidance to coding agents (Claude Code, etc.) when working in this repository.

## Project

`oast-server` — the Laravel core of the oast.sh platform: a spec-first API design review platform whose first feature is the multi-model **Council** that reviews OpenAPI specs. The M0 review engine (orchestrator, panel/judge agents, finding validation, persistence) is partially built. Read the design and plan in `docs/` before feature work — `docs/superpowers/specs/` and `docs/superpowers/plans/`, plus the source `docs/oast-build-spec.md` and `docs/judge-rubric.md`.

## Commands

JavaScript tooling uses **Bun**, not npm/pnpm (note `bun.lock`, `.npmrc`). Frontend build/dev/format run through **Vite Plus** (the `vp` CLI).

| Task | Command |
|------|---------|
| Run all dev services | `composer dev` (concurrently: `artisan serve`, `queue:listen`, `pail`, `vp dev`) |
| Run the full check suite | `composer test` (type-coverage → unit+coverage → lint check → static analysis) |
| Unit tests only | `composer test:unit` (Pest, parallel, **100% line coverage required**) |
| Type coverage | `composer test:type-coverage` (Pest, `--min=100`) |
| Static analysis | `composer test:types` (PHPStan) |
| Lint check (no writes) | `composer test:lint` (Pint `--test`, Rector `--dry-run`, `vp fmt --check`) |
| Auto-fix lint | `composer lint` (Rector, Pint, `vp fmt`) |
| Run one test file | `vendor/bin/pest tests/Unit/Council/ReviewTest.php` |
| Run tests matching a name | `vendor/bin/pest --filter='it does something'` |
| Tail logs | `php artisan pail` |
| One-time project setup | `composer setup` |

Composer appends trailing args to single-command scripts, so `vendor/bin/pest <path>` (or any single-command `composer` script) can be scoped to a file. **`composer test` gates on 100% line and type coverage** — expect it to fail until coverage is complete.

## Architecture notes

- **PHP 8.5**, Laravel 13.
- **Council engine** lives in `app/Council` (the `CouncilOrchestrator`, value objects, `FindingValidator`, exceptions) with Laravel AI SDK agents in `app/Ai/Agents` (`Panelist`, `Judge`). API errors are **RFC 9457 Problem Details** — domain exceptions implement `Responsable` and self-render `application/problem+json`; types are in `app/Http/Problems/ProblemType`.
- **Models reach LLMs via the Laravel AI SDK** (`laravel/ai`) using OpenRouter (`Lab::OpenRouter`, single-key BYOK); the panel/judge roster lives in `config/oast.php`.
- **Testing is Pest 4** (`tests/Pest.php`), not raw PHPUnit. `RefreshDatabase` applies to the `Feature` suite; the `Unit` suite is also bound to `Tests\TestCase` so facades/helpers work there. LLM calls are exercised with the SDK's native agent fakes — no live HTTP in the default suite.
- **Tests run against in-memory SQLite** (`phpunit.xml` sets `DB_DATABASE=:memory:`); local/dev uses a SQLite file.
- **Static analysis**: PHPStan (Larastan, `level: max`, bleedingEdge) + Rector; `declare(strict_types=1)` across the codebase (`nunomaduro/essentials`).
- **Frontend**: Vite Plus (VoidZero — `vite` is aliased to `@voidzero-dev/vite-plus-core`, CLI `vp`) + Tailwind CSS 4 (`@tailwindcss/vite` plugin, no `tailwind.config.js` — Tailwind 4 is CSS-config-first).

## Conventions

- Format/lint with `composer lint` (Rector + Pint + `vp fmt`) before committing; Pint uses a committed `pint.json` (PER preset). `composer test:lint` is the non-mutating check.
- Write tests alongside implementation using Pest's functional style (`it(...)`, `test(...)`, `expect(...)`). New code must keep line and type coverage at 100% — `composer test` enforces it.
