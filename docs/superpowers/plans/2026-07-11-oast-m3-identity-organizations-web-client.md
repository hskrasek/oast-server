# oast.sh M3 Identity, Organizations, and Web Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved M3 synthesis as three independently executable work packages: the shared M3A identity/organization foundation, bearer-token compatibility in the Rust CLI, and the M3B authenticated review workspace plus self-host image.

**Architecture:** M3A lands first in `oast-server` and owns all identity, tenant, token, review-authorization, and SSE contracts. The Rust CLI then adopts the bearer contract before anonymous API access is removed from any deployed environment. M3B consumes the completed M3A contracts without reimplementing tenancy and adds the browser workspace and supervised self-host image.

**Tech Stack:** PHP 8.5, Laravel 13, Fortify 1.37, Sanctum 4.3, Pest 4, Blade, Alpine.js 3.15, native EventSource, `yaml` 2.9, Bun/Vite Plus/Vitest, Rust 2024, Reqwest/Clap, FrankenPHP, supervisord, SQLite WAL.

## Global Constraints

- The approved source is `docs/superpowers/specs/2026-07-11-oast-m3-identity-organizations-web-client-synthesis.md`; if a sub-plan conflicts with it, stop and amend the plan rather than silently weakening the spec.
- Execute the work packages in order: M3A server → Rust CLI compatibility → M3B server/UI/image. M3B may begin only after M3A's full gate passes.
- Use an isolated worktree when execution begins. This planning session does not create one.
- Preserve 100% PHP line and type coverage, PHPStan max/bleeding-edge, Pest functional style, strict types, final classes, Bun/Vite Plus, Rust 2024, and existing RFC 9457 API behavior.
- Public publication routes `/reviews` and `/reviews/{slug}` remain public and repository-backed throughout M3.
- The hosted instance remains single-tenant until M4; no cloud registration, switching, billing, or managed-key work belongs in these plans.
- The GitHub Action repository is not present locally. Do not invent file paths or block local implementation on it; create a separate plan after the repository is available.

---

## Work Package 1: M3A Identity and Organizations (`oast-server`)

**Plan:** `docs/superpowers/plans/2026-07-11-oast-m3a-identity-organizations.md`

**Repository:** `/Users/hskrasek/Documents/Projects/PHP/oast-server`

**Produces:**

- Atomic one-use self-host bootstrap, canonical Fortify identity flows, configurable verification enforcement, and operator recovery commands.
- Organization, membership, invitation, installation, and organization-scoped Sanctum token models.
- Trusted `OrganizationContext`, final-owner enforcement, invitation races, token revocation, and management screens.
- Bearer-only `api.*`, RFC 9457 401/403/429 responses, organization-scoped review lifecycle, active-review limit, and per-poll SSE reauthorization.
- Foundational session endpoints under `/app/reviews` that M3B will replace with HTML workspace controllers/views while preserving application actions and policies.

### Phase gate

- [ ] **Step 1: Execute every M3A task and commit**

Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` with the M3A plan.

- [ ] **Step 2: Run the M3A full gate**

Run from `oast-server`:

```bash
composer test
php artisan route:list --path=app
php artisan route:list --domain="$(php artisan tinker --execute='echo config("oast.api_domain");')"
```

Expected: `composer test` exits 0; `/app/*` routes are session/CSRF protected; `api.*` review routes require `auth:sanctum` plus exact review abilities; public `/reviews/*` remain unchanged.

- [ ] **Step 3: Verify the deployed-contract break is not released before CLI support**

Do not deploy the bearer-only server yet. Record the M3A commit SHA for the CLI compatibility release notes.

---

## Work Package 2: Rust CLI Bearer Compatibility (`oast-cli`)

**Plan:** `docs/superpowers/plans/2026-07-11-oast-m3-cli-token-auth.md`

**Repository:** `/Users/hskrasek/Documents/Projects/Rust/oast-cli`

**Consumes:** M3A's unchanged API paths (`POST /reviews`, `GET /reviews/{id}/events`) and RFC 9457 unauthenticated response.

**Produces:** Required `--token` / `OAST_TOKEN`, bearer headers on create and every SSE reconnect, sanitized Problem Details errors, and actionable 401 guidance.

### Phase gate

- [ ] **Step 1: Execute every CLI task and commit**

Use the CLI plan in an isolated `oast-cli` worktree. Do not edit `oast-server` from that worktree.

- [ ] **Step 2: Run the CLI full gate**

Run from `oast-cli`:

```bash
cargo fmt --check
cargo clippy --all-targets --all-features -- -D warnings
cargo test --all-targets
```

Expected: all commands exit 0; HTTP mocks prove bearer headers on POST and SSE GET; no output/error contains the token.

- [ ] **Step 3: Run a server/CLI compatibility smoke test**

With the M3A server running, create a PAT in `/app/settings/tokens`, then run:

```bash
OAST_SERVER="https://api.oast.test" OAST_TOKEN="<one-time token>" cargo run -- roast /path/to/openapi.yaml
```

Expected: review is accepted, events stream with reconnect support, and terminal findings render. Revoke the token and repeat; expected exit code 2 with RFC 9457 detail plus `/app/settings/tokens` guidance, and no plaintext token in output.

---

## Work Package 3: M3B Web Client and Self-Host Image (`oast-server`)

**Plan:** `docs/superpowers/plans/2026-07-11-oast-m3b-web-client-self-host.md`

**Repository:** `/Users/hskrasek/Documents/Projects/PHP/oast-server`

**Consumes:** The exact M3A contracts named in the M3B plan: `/app` middleware group, `OrganizationContext`, scoped review resolver, `ReviewPolicy`, `DeleteReviewAction`, same-origin SSE route, and organization-owned `CreateReviewAction`.

**Produces:** Authenticated review history/submission/live/report/deletion UI, CST-aware JSON Pointer source highlighting, and the persistent supervised self-host image.

### Phase gate

- [ ] **Step 1: Rebase M3B execution worktree onto completed M3A**

Before editing, verify the consumed M3A interfaces still have the names/signatures in the M3B plan. If implementation review changed a name, amend the M3B plan's interface block before proceeding; do not duplicate M3A behavior.

- [ ] **Step 2: Execute every M3B task and commit**

Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` with the M3B plan.

- [ ] **Step 3: Run the M3B full gate**

Run from `oast-server`:

```bash
bun run test:js
bun run build
composer test
docker build -t oast-server:m3 .
```

Expected: JavaScript source-map/workspace tests pass; production assets build; PHP quality gate exits 0; Docker image builds.

- [ ] **Step 4: Run the documented image smoke test**

Follow `docker/README.md` using a stable `APP_KEY`, high-entropy `OAST_BOOTSTRAP_SECRET`, and named `/var/lib/oast` volume. Verify `/up`, worker health, setup, login, token issuance, authenticated review streaming, container restart persistence, and public publication pages.

---

## External Work Package: GitHub Action Token Input

The approved spec also requires the GitHub Action to accept the PAT as a secret input and send it to the CLI/server. No Action repository or `action.yml` exists in the mapped project directories, so exact-file planning would be fabricated.

- [ ] **Step 1: Locate or create the Action repository before implementation**

Required discovery inputs: repository path, action runtime (composite, Docker, or JavaScript), existing server/CLI invocation, tests, and release process.

- [ ] **Step 2: Run `superpowers:writing-plans` in that repository**

The separate plan must define exact paths and test: required secret input, environment handoff as `OAST_TOKEN`, masked logs, missing-token failure, 401 Problem Details rendering, and end-to-end invocation against M3A.

This external package does not block local M3A/M3B coding, but it **does** block announcing hosted CI compatibility.

## Final M3 Release Gate

- [ ] **Step 1: Run all repository gates at their recorded commits**

```bash
# oast-server
composer test
bun run test:js
bun run build
docker build -t oast-server:m3 .

# oast-cli
cargo fmt --check
cargo clippy --all-targets --all-features -- -D warnings
cargo test --all-targets
```

Expected: all commands exit 0.

- [ ] **Step 2: Verify rollout order**

Release CLI support before or atomically with the bearer-only server. Do not deploy M3A API enforcement while the published CLI still lacks `--token` / `OAST_TOKEN`.

- [ ] **Step 3: Record residual external risk**

Until the separate GitHub Action plan is implemented and released, note that existing Action consumers cannot authenticate against the M3 API.
