# oast.sh M3 — Identity, Organizations, and Web Client (Synthesized Design)

> **Synthesis of two brainstormed specs** (`2026-07-11-oast-m3a-identity-organizations-design.md` and `2026-07-11-oast-m3-auth-web-client-design.md`) and their adversarial reviews. This document supersedes both.

## Goal

Establish one secure ownership model for self-hosted and hosted oast, then build the authenticated review workspace on top of it.

Every retained specification and review belongs to an organization. Every browser user has an authenticated identity. Every CLI/CI credential is permanently scoped to an organization. No anonymous review API access survives M3A.

The milestone sequence is:

1. **M3A — Shared identity and organization foundation.** Auth, organizations, memberships, invitations, tokens, management screens. No review workspace yet.
2. **M3B — Authenticated Web client.** Review dashboard, submission, live progress, inline spec view, deletion — built on M3A's contracts.
3. **M4 — Cloud tenancy and commercialization.** Multiple organizations, switching, billing, managed keys.

Building the Web client before M3A is explicitly rejected. Retrofitting ownership afterward would require changing review creation, history, numeric resource lookup, SSE authorization, retained-spec access, deletion, and the CLI API simultaneously.

### Why the original build-spec sequencing changed

The build spec planned M3 (web client, no auth) → M4 (cloud: auth, billing). Both brainstormed specs independently revised this: **self-hosted installs want auth too.** Moving auth into the open-core base means the web client is born knowing who's looking at it, and the schema is organization-shaped from day one so M4 needs no `organization_id` backfill.

## Product and domain decisions

### Open-core boundary

`oast-server` (AGPL) owns the capabilities needed by both distributions:

- Email/password authentication with verification and reset
- Organizations and memberships
- First-user self-host bootstrap
- Invitations
- Organization-owned reviews
- Organization-scoped personal access tokens
- Account, organization, invitation, member, and token management screens
- Authorization for browser and API review operations
- The authenticated Web client (M3B)
- Self-host Docker image (M3B)

`oast-cloud` (private overlay) imports that foundation and later adds:

- Public cloud registration policy
- Multiple-organization creation and switching
- Plans, quotas, metering, and billing
- Managed model credentials
- Cloud abuse controls and operations

Authentication is not a cloud-only feature. Tenancy commercialization is.

### Canonical language

**Organization** is the canonical term in code, database schema, API, authorization, and UI. "Workspace" is not an alias. A team describes the people in an organization, not a separate domain object. This avoids the collision where "team" means both the tenant and the people in it.

### Membership model

Users and organizations have a many-to-many relationship represented by an explicit `OrganizationMembership` model. M3A supports two string-backed roles via an `OrganizationRole` enum:

- `owner`
- `member`

The schema permits a user to have multiple memberships so M4 does not require a redesign. M3A enforces an application-level limit of one membership per user, backed by a **unique index on `organization_memberships.user_id`** (dropped in M4 when org switching ships). A user who already belongs to an organization cannot accept another invitation until M4.

An organization must always retain at least one owner. Removing, demoting, or allowing the final owner to leave is rejected. This invariant is enforced with `lockForUpdate` over the organization's owner memberships inside the demotion/removal transaction — a plain re-`SELECT` inside a transaction does not serialize concurrent demotions on SQLite-WAL or MySQL READ COMMITTED.

The initial design does not add an `admin` role or configurable RBAC. 2FA is explicitly excluded from M3A and recorded in the decisions table so it does not become an ambient assumption.

### Review ownership and visibility

Every new review belongs to an organization and records its creator. Reviews are visible to all current organization members; the creator is audit metadata, not a privacy boundary.

Members may delete reviews they created. Owners may delete any review in their organization. Creatorless legacy reviews may be deleted only by an owner. Public review publications (`site:publish`) remain a separate, explicit publishing flow and do not weaken organization access.

Each review retains its immutable submitted specification snapshot until the review is explicitly deleted. Findings therefore always resolve against the exact reviewed content. M3A/M3B do not introduce projects, reusable specifications, versions, or an editor model.

## Scope

### M3A deliverables

- Registration (invitation-gated after bootstrap), login, logout, email verification, password reset, and password confirmation
- First-user self-host bootstrap via a protected `/setup` flow
- Organizations and owner/member memberships
- Expiring, single-use, revocable invitations
- Organization ownership and creator attribution on reviews
- Policies for review creation, lookup, SSE, and deletion
- Organization-scoped personal access tokens with fixed non-destructive abilities
- Account, organization, invitation, member, and token management screens
- Owner/admin CLI password reset and email-verification commands for installations without outbound mail
- Removal of anonymous review API access; removal of the `OAST_API_ENABLED` config flag
- CLI bearer-auth support (the `oast roast` contract change belongs in M3A's scope even if implemented in the CLI repo)

### M3B deliverables

- Organization review dashboard and history
- Upload/paste review submission experience
- Live review progress interface (SSE streaming)
- Inline specification viewer with JSON Pointer → source-range highlighting
- Review deletion UI
- Self-host Docker image (single image, supervised, persistent volume)

### Deferred to M4

- Multiple memberships in active use
- Organization creation and switching
- Additional roles or configurable RBAC
- Plans, quotas, metering, billing, and managed credentials
- Configurable or ephemeral review retention
- OAuth/SSO and 2FA

## Architecture

### Laravel Fortify and Sanctum

Neither Fortify nor Sanctum is installed today. M3A adds both via `composer require`.

**Laravel Fortify** supplies the backend browser-authentication flows:

- Registration (invitation-gated after bootstrap)
- Login/logout
- Email verification
- Forgot/reset password
- Password confirmation
- Authentication throttling

The application supplies custom Blade views built with the existing Tailwind design system. No starter-kit frontend, SPA framework, or Livewire dependency is introduced.

**Laravel Sanctum** supplies bearer-token parsing, authentication, and abilities. A custom Sanctum token model (or `personal_access_tokens` migration customization) adds the immutable organization association. Application services still own bootstrap, invitations, memberships, and organization context; those rules do not live inside Fortify or Sanctum.

**Sanctum stateful-domains hazard:** with the browser on the apex domain and the API on `api.*`, Sanctum's `stateful` domain config must exclude the API subdomain. Otherwise cookie-authenticated API requests silently bypass the bearer-token requirement, undermining "the API never trusts a session." One explicit config decision.

### Identity

`User` implements Laravel's `MustVerifyEmail` contract (the import is currently commented out in `app/Models/User.php`). Authentication establishes identity only; it does not grant organization access on its own.

Email verification capability is always present. Cloud always enforces verification. Self-host enforcement is configurable (`oast.enforce_email_verification`, default `false`) so an installation without SMTP remains usable. The bootstrap user is **auto-verified** at creation time — without this, an operator who later enables verification enforcement is locked out of product routes with no verified session to fix it from. A CLI command (`oast:user:verify`) provides a secondary recovery path.

Email canonicalization: emails are lowercased and trimmed identically during setup, invitation, login, and reset. Uniqueness is enforced on the canonical value. Password rules are defined once (minimum length, breach-list check when available) and shared by setup, invitation acceptance, and reset.

### Organizations and memberships

Core types:

- `Organization` — id, name, timestamps
- `OrganizationMembership` — id, `organization_id` (FK), `user_id` (FK), `role` (string), timestamps. **Unique index on `user_id`** for the M3A one-membership restriction.
- `OrganizationRole` enum (`owner | member`)

The explicit membership model provides a stable place for role, timestamps, and future membership metadata. Domain/application services enforce the one-membership M3A restriction and final-owner invariant rather than scattering those checks through controllers.

### Organization context

An `OrganizationContext` service resolves the trusted organization for the current request:

- **Browser session:** the authenticated user's sole organization membership.
- **API request:** the organization permanently attached to the authenticated token.
- **Artisan console:** resolved from a required `--organization` flag (see Console ownership below).

Controllers and actions never accept an organization ID to establish access. All organization-sensitive queries use the resolved context. Client-supplied `organization_id` or `created_by_user_id` in request payloads is never honored.

### Registration policy seam

A `RegistrationPolicy` interface isolates distribution behavior without duplicating account workflows.

`SelfHostedRegistrationPolicy` implements:

- The first registration (via `/setup`) creates the installation's default organization.
- The first user becomes its owner and is auto-verified.
- Existing legacy reviews are claimed by the default organization (with `created_by_user_id = null`).
- Public registration closes after bootstrap — persisted independently of user count.
- Subsequent registration requires a valid invitation.

The cloud overlay later binds `CloudRegistrationPolicy`:

- Uninvited public registration creates a new organization and owner membership.
- Invitation registration joins the inviting organization instead of creating another.

Account creation, password handling, verification, and invitation acceptance remain shared.

### Self-host bootstrap

**Setup is protected by a one-use bootstrap secret**, not a zero-users check. The current `routes/web.php` has no auth middleware; a publicly reachable fresh container with a "first visitor becomes admin" rule permits remote instance takeover.

Bootstrap flow:

1. Migrations/seeding create a singleton `installation` row (id, `bootstrapped_at` nullable). This row must exist before any registration attempt — the lock requires a row to exist.
2. The operator starts the container with `OAST_BOOTSTRAP_SECRET` set to a high-entropy value (or one is generated and logged at first boot).
3. An unauthenticated visitor opens `/setup`, which requires the bootstrap secret. The secret is submitted via POST form field on the first request, then stored in the session. (Query-param passing is avoided to prevent exposure in access logs and browser history.)
4. The setup form collects admin name, email, password, and organization name.
5. On submit: `lockForUpdate` on the `installation` row, confirm `bootstrapped_at IS NULL`, then transactionally create the user (auto-verified), organization, owner membership, claim legacy reviews, and mark `bootstrapped_at = now()`.
6. The application regenerates the session ID (prevents session fixation), starts the user session, and redirects to the dashboard.
7. `/setup` returns 404 for all future requests regardless of user count. Deleting all users does not reopen setup.

Concurrent first-setup attempts cannot create two organizations or split legacy review ownership — the `lockForUpdate` serializes them. Legacy reviews receive an organization but retain `created_by_user_id = null`, accurately representing their pre-auth origin. Before bootstrap completes, unowned reviews are inaccessible through product routes.

**Setup redirect scoping:** "all web routes redirect to /setup" applies only to **protected application routes** (`/app/*`). The following remain reachable before setup: `/setup` itself, `/up` (health), public site pages (`/`, `/why`, `/reviews`, `/reviews/{slug}`, `/og/*`, `/subscribe/*`), and static assets. Login and invite-acceptance routes return a 419 or redirect to `/setup` before installation is complete.

### Invitations

`OrganizationInvitation` records:

- `organization_id` (FK)
- `email` — recipient email (canonicalized)
- `invited_by_user_id` (FK) — inviting owner
- `role` — intended role (`member` in M3A)
- `token_hash` — a random 256-bit CSPRNG token stored only as a hash
- `expires_at`
- `accepted_at` (nullable)
- `revoked_at` (nullable)
- timestamps

The delivered/copied URL contains the plaintext token. A Laravel signed URL is **not** used in addition to the hashed token — the hashed random token is sufficient, and a signed URL would break on `APP_KEY` rotation while the token remains valid, producing a confusing error.

An owner can copy the invitation link even when outbound mail is unavailable. Mail delivery is attempted when configured; the copyable link is the baseline.

Acceptance verifies the token hash (constant-time comparison), expiration, invitation state (`accepted_at IS NULL AND revoked_at IS NULL`), recipient identity (the signed-in user's email must match), and the one-membership restriction. All constraints are rechecked transactionally at write time with `lockForUpdate` on the invitation row and the user's membership rows. An acceptance failure rolls back — no partial user/membership relationship.

Existing users sign in before acceptance; new users register through the invitation flow. A signed-in user with a different email cannot accept it. Duplicate pending invitations for the same organization and email are rejected or explicitly replaced rather than accumulating active links.

`Referrer-Policy: no-referrer` is set on invitation pages to avoid leaking the token in referer headers. Unknown, expired, used, and revoked invitations return the same generic "invitation not available" page — no information leak about the organization or inviter.

### Personal access tokens

Personal access tokens are permanently scoped to one organization and contain:

- `user_id` (FK)
- `organization_id` (FK) — immutable association
- `name` — human-readable name
- `token` — Sanctum's standard hashed-token column (the spec refers to this as the "hashed secret"; the column name stays as Sanctum's `token` for compatibility)
- `abilities` — fixed review-only abilities (see below)
- `expires_at` — optional expiration
- `last_used_at` — throttled to once-per-minute updates to avoid write amplification from CI polling
- `revoked_at` — nullable revocation state

Token plaintext is sent in the response with `Cache-Control: no-store, private`. Authentication verifies that the user still belongs to the token's organization, so membership removal immediately removes effective access even if the token record remains.

**Initial token abilities are deliberately non-destructive:**

- `review:create` — create reviews
- `review:read` — read review status and findings
- `review:follow` — follow review SSE events

Tokens cannot delete reviews, manage organizations or members, invite users, or manage other tokens. PATs are never accepted for administrative web routes. Selectable granular scopes and device authorization are deferred.

**Password confirmation** is required before creating or revoking a token (and before removing members, transferring ownership, or changing password). This uses Fortify's password-confirmation middleware.

### Review ownership and query scoping

The `reviews` table (currently: `id`, `spec_ref`, `spec_hash`, `spec`, `mode`, `dimension`, `panelists`, `panel_size`, `findings`, `metrics`, `status`, `error`, `timestamps`) gains:

- `organization_id` (nullable FK — see nullability note below)
- `created_by_user_id` (nullable FK)

New review creation obtains both values from trusted authentication and `OrganizationContext`; request data cannot override either. The `Review` model currently has `$guarded = []` — M3A changes this to explicitly guard `organization_id` and `created_by_user_id` (or the CreateReviewAction continues using explicit assignment only, enforced by a negative feature test: POST with `organization_id`/`created_by_user_id` in the payload and assert they're ignored). Queued jobs continue to carry only the review ID and reload the review internally, so they do not depend on a live user session.

Review reads, status, findings, event streams, and deletion first resolve the review inside the current organization. **Team authorization is enforced at resource resolution**, not just via a policy check after implicit model binding returns an unrestricted `Review`. A custom route-model resolver scopes the query by `OrganizationContext` before returning a model. Unknown and cross-organization review IDs return the same 404 response to avoid resource enumeration.

**`organization_id` nullability is permanently application-level**, not a temporary "transition." The column is nullable because migrations run before a first owner exists, and there is no generic tightening step (migrations run before bootstrap on fresh installs). Application code enforces that every new or accessible review has an organization post-bootstrap. An invariant test confirms no product path can produce or read an org-less review after bootstrap.

### Console ownership

The existing `oast:review` artisan command creates reviews via `CreateReviewAction` with no auth context. M3A adds a required `--organization` option (the organization ID or slug). The command resolves the organization and passes it to `CreateReviewAction`. Without this flag, the command errors — it does not silently fall back to a default. This preserves the invariant that every review belongs to an organization.

### Browser and API surfaces

The existing `api.*` routes (currently: `POST /reviews`, `GET /reviews/{review}`, `GET /reviews/{review}/events` on `api.oast.test` with `EnsureApiEnabled` middleware) become the **bearer-token-only, stateless** CLI/CI surface. The `EnsureApiEnabled` middleware and `OAST_API_ENABLED` config flag are removed — all API endpoints require a Sanctum token, no config flag to reopen.

Browser review routes are **same-origin, session-authenticated** web routes under a protected namespace:

```text
POST /api/reviews                        Bearer token (CLI/CI)
GET  /api/reviews/{id}                    Bearer token
GET  /api/reviews/{id}/events             Bearer token

POST /app/reviews                         Web session + CSRF
GET  /app/reviews                         Web session
GET  /app/reviews/{id}                    Web session
GET  /app/reviews/{id}/events             Web session
DELETE /app/reviews/{id}                  Web session + CSRF
```

Both entry points call the same application actions, policies, resources, and event-shaping code. The `/app/*` namespace is reserved for authenticated application routes. Public `/reviews` and `/reviews/{slug}` remain **publication pages** (served from `PublicationRepository`, not from the `reviews` table) and never resolve organization-owned review records.

This avoids cross-subdomain session cookies, CORS, CSRF exceptions, and credentialed `EventSource` configuration while preserving the CLI API contract. Native `EventSource` (used by the browser) cannot attach a bearer `Authorization` header — the session-cookie web route solves this. PATs are never accepted in query strings.

Authentication modes:

- **Browser:** session cookie, CSRF protection, verification middleware when required.
- **CLI/CI:** organization-scoped Sanctum bearer token.

The browser and API never trust a client-supplied organization ID. All mutations use POST/PATCH/DELETE with CSRF validation; logout is POST.

## M3B — Web client

**Stack: Blade + Alpine.js + native `EventSource`.** No SPA framework, no Livewire. Matches the existing Tailwind 4 / Vite Plus setup. Focused JavaScript is permitted only where it improves small interactions (copying a token/invitation URL, confirming destructive actions, SSE event rendering). If the inline spec view someday outgrows Alpine, that one page can adopt something heavier in isolation.

The browser is a fourth `tail -f` of the same event log the CLI renders — `GET /app/reviews/{id}/events` is the shared streaming contract for browser, CLI, and CI.

### Pages (all behind auth + verification middleware)

- **Reviews index** (`GET /app/reviews`) — the organization's reviews with status and cost. Loading, empty, and failed states are defined.
- **New review** (`GET /app/reviews/create`, `POST /app/reviews`) — paste or upload an OpenAPI spec, submit → `POST` returns 202, redirect to the live view.
- **Live review view** (`GET /app/reviews/{id}`) — `EventSource` on `/app/reviews/{id}/events`; panelist/judge events render as they stream. On completion it settles into the **report view**: findings grouped by severity, each finding's `location` JSON Pointer highlighting the corresponding lines in an inline spec pane.
- **Settings — organization** (`GET /app/settings/organization`) — rename organization, member list, mint/revoke/copy invitation links (owner only).
- **Settings — tokens** (`GET /app/settings/tokens`) — create/revoke personal access tokens.
- **Settings — account** (`GET /app/settings/account`) — profile, password, email change.

### Review statuses

The actual implementation uses these statuses (from `CreateReviewAction`, `RunJudge`, and `PanelFinalizer`):

| Status     | Set by                                                          | UI label |
| ---------- | --------------------------------------------------------------- | -------- |
| `queued`   | DB column default (migration) — not set in code                 | Queued   |
| `running`  | `CreateReviewAction` at creation                                | Running  |
| `judging`  | `RunJudge` at dispatch                                          | Judging  |
| `complete` | Judge completion                                                | Complete |
| `error`    | `PanelFinalizer` (quorum failure) or `RunJudge` (judge failure) | Failed   |

The UI defines states for: loading, empty (no reviews), failed (`error` status), disconnected (SSE lost), reconnecting, and terminal (complete/error).

### SSE replay and reconnection

The existing `ReviewEventsController` supports replay via `Last-Event-ID` header / `lastEventId` query param. Browser `EventSource` automatically sends `Last-Event-ID` on reconnect. On reconnect, the controller replays all events after the cursor, then resumes live polling. When opening a review after completion, the controller replays all events and immediately returns (terminal event reached). If a client missed the terminal event, it recovers from the persisted `reviews.status` column.

### SSE authorization and revocation

The current `ReviewEventsController` has **zero auth checks** — it accepts any `Review` and streams indefinitely with `set_time_limit(0)`. M3A fixes this:

1. The controller resolves the review through `OrganizationContext` (scoped query, 404 on cross-org).
2. Authorization is checked before streaming begins.
3. **Token/membership validity is re-checked on each poll iteration.** The stream loop currently polls `connection_aborted()` and sleeps 500ms — each iteration also verifies the token is not revoked, the user is still a member, and the review still belongs to the requesting organization. If any check fails, the stream terminates. This closes the gap where a revoked token or removed member keeps receiving events for the entire remaining stream.

Without per-iteration re-checks, membership removal is not "immediate" as the spec claims — it only takes effect on the next connection. Either re-check or soften the claim; this synthesis re-checks.

### JSON Pointer → source-range mapping

A JSON Pointer (`#/paths/~1orders/post`) identifies a semantic node, not a location in the original source text. Highlighting pasted YAML requires a parser that preserves concrete source ranges. Parse-and-reserialize can change formatting, comments, anchors, and line numbers.

M3B defines:

- **Supported formats:** JSON and YAML are both accepted as input. The submitted spec is parsed into a concrete-syntax-tree-aware representation that preserves original source ranges.
- **Mapping:** decoded JSON Pointer segments are resolved against the parsed tree to find the source range (start line, end line) of the target node.
- **Edge cases:** aliases and merge keys (`<<`) resolve to the target node's source range; duplicate keys highlight the last occurrence; root pointer (`#`) highlights the entire document; missing pointers highlight nothing and show a fallback message.
- **Fallback:** when exact highlighting is unavailable (unparseable spec, pointer not found), the finding renders without highlighting and the inline pane shows the raw spec.
- **Retention:** the original submitted source is retained verbatim in `reviews.spec` (already the case) and displayed as-is, never re-serialized.

If this proves too complex for M3B, the inline highlighting is deferred to a post-M3B iteration and the spec pane shows the raw spec with the pointer as text. This decision is made during M3B implementation planning, not now.

## Self-host Docker image

Ships at the end of M3B. A single image running the app + queue worker against SQLite (WAL + busy_timeout). First boot lands on `/setup`.

### Operational contract

- **Process model:** a supervisor process (s6-overlay or `supervisord`) runs FrankenPHP and the queue worker as supervised children. Both restart on failure.
- **Queue worker:** `php artisan queue:listen --tries=3 --timeout=900`, with graceful shutdown on SIGTERM. Retry and timeout settings are explicit, not defaults.
- **Persistent volume:** `/var/lib/oast` contains `database.sqlite`, `database.sqlite-wal`, `database.sqlite-shm`, and the publications directory. All are on a persistent volume; without this, all users, tokens, reviews, and sessions are lost on restart.
- **`APP_KEY`:** must be provisioned across restarts via an environment variable or a mounted file. The image refuses to boot with a missing or placeholder `APP_KEY`.
- **Migrations:** run at container startup (`php artisan migrate --force`). Startup failure (migration error) exits non-zero rather than serving a broken app.
- **Health/readiness:** the `/up` endpoint checks the database connection and that migrations are complete. A separate queue-worker health check verifies the worker process is alive.
- **Reverse proxy:** for SSE, the reverse proxy must disable buffering (`X-Accel-Buffering: no` is already set) and have a timeout of at least 600s. Documented in the image README.
- **Worker count with SQLite:** one queue worker is the supported default. More workers increase panel parallelism but contend on SQLite writes. The image documents this and allows `OAST_QUEUE_WORKERS` to scale it, with a warning about SQLite write contention.
- **Backup/restore:** documented — copy the volume directory while the worker is stopped. Upgrade = pull new image, restart, migrations run automatically.

The image binds to loopback by default. Remote exposure requires TLS and secure cookies; the README documents the reverse-proxy configuration.

## Data model and migration

### New tables

**`organizations`**

| Column       | Type       | Notes |
| ------------ | ---------- | ----- |
| `id`         | bigint, PK |       |
| `name`       | string     |       |
| `timestamps` |            |       |

**`organization_memberships`**

| Column            | Type                       | Notes                                                                         |
| ----------------- | -------------------------- | ----------------------------------------------------------------------------- |
| `id`              | bigint, PK                 |                                                                               |
| `organization_id` | bigint, FK → organizations | cascade on delete (not used in M3A — no org deletion)                         |
| `user_id`         | bigint, FK → users         | set null on delete (user deletion nulls `reviews.created_by_user_id`)         |
| `role`            | string                     | `owner` or `member`                                                           |
| `timestamps`      |                            |                                                                               |
|                   |                            | **Unique index on `user_id`** (M3A one-membership restriction; dropped in M4) |

**`organization_invitations`**

| Column               | Type                       | Notes                        |
| -------------------- | -------------------------- | ---------------------------- |
| `id`                 | bigint, PK                 |                              |
| `organization_id`    | bigint, FK → organizations | cascade on delete            |
| `email`              | string                     | canonicalized                |
| `invited_by_user_id` | bigint, FK → users         | set null on delete           |
| `role`               | string                     | `member` in M3A              |
| `token_hash`         | string, unique             | 256-bit CSPRNG token, hashed |
| `expires_at`         | timestamp                  |                              |
| `accepted_at`        | timestamp, nullable        |                              |
| `revoked_at`         | timestamp, nullable        |                              |
| `timestamps`         |                            |                              |

**`personal_access_tokens`** (customized Sanctum)

| Column                   | Type                       | Notes                                                                                         |
| ------------------------ | -------------------------- | --------------------------------------------------------------------------------------------- |
| standard Sanctum columns |                            | id, tokenable_type, tokenable_id, name, `token` (hashed), abilities, last_used_at, expires_at |
| `organization_id`        | bigint, FK → organizations | **immutable** — set at creation, never changed                                                |
| `revoked_at`             | timestamp, nullable        |                                                                                               |

**`installation`** (singleton bootstrap state)

| Column                    | Type                                 | Notes                                |
| ------------------------- | ------------------------------------ | ------------------------------------ |
| `id`                      | int, PK                              | always 1 (singleton)                 |
| `bootstrapped_at`         | timestamp, nullable                  | null until first bootstrap completes |
| `default_organization_id` | bigint, FK → organizations, nullable | set during bootstrap                 |

Created by a migration + seeder (or migration-only with a guaranteed insert) so the row exists before any registration attempt.

### Changes to `reviews`

- `organization_id` (nullable FK → organizations) — nullable permanently (see note above); application-enforced NOT NULL post-bootstrap
- `created_by_user_id` (nullable FK → users) — nullable; set null on user deletion

### Changes to `users`

- Implement `MustVerifyEmail` (uncomment the interface/contract)
- `email` uniqueness enforced on canonical (lowercased, trimmed) value
- No `team_id` FK (membership is via the join table)

### Deletion behavior

- User deletion does not delete organization reviews; `reviews.created_by_user_id` becomes null. User deletion of a final owner is rejected (final-owner invariant). Self-service account deletion is **explicitly deferred out of M3A** — the data-model section describes the _behavior_ of user deletion for referential integrity, but no M3A deliverable includes a self-service deletion flow.
- Review deletion cascades through `review_events` and `review_panel_responses` (already related via `Review::events()` and `Review::panelResponses()`).
- M3A exposes no organization-deletion workflow.

### Rollout order

1. `composer require laravel/sanctum laravel/fortify` and publish configs.
2. Apply additive migrations (organizations, memberships, invitations, customized PATs, installation singleton, reviews columns).
3. Require bearer-token auth on every API endpoint; remove `EnsureApiEnabled` middleware and `OAST_API_ENABLED` config; anonymous review mode is removed.
4. First self-host `/setup` creates the default organization and claims legacy reviews.
5. Existing CLI users create organization-scoped tokens via the management UI and configure their clients (`--token` / `OAST_TOKEN` in the CLI repo).
6. Retire API-enable assumptions.
7. Import the open-core work into `oast-cloud` and bind the cloud registration policy in M4.

No existing review data is destructively migrated. Legacy reviews are claimed, not transformed.

### CLI compatibility

This is a hard breaking change to the published API contract. The M3A scope explicitly includes:

- The CLI (`oast roast`) gains `--token` / `OAST_TOKEN` support. (Implemented in the CLI repo; the server spec fixes the contract.)
- The GitHub Action accepts a token as a secret input.
- A 401 from the new server renders as RFC 9457 `application/problem+json` with a new `Unauthenticated` problem type; a 403 renders as `Forbidden`.
- The CLI's error message for a 401 guides the user to create a token in the management UI.
- The rollout is documented in the release notes and the CLI README.

## Data flows and security behavior

### First self-host setup

1. An unauthenticated visitor opens `/setup` with the bootstrap secret.
2. The `RegistrationPolicy` confirms the installation is uninitialized (`installation.bootstrapped_at IS NULL`).
3. Bootstrap creates the user (auto-verified), organization, owner membership, and legacy assignments transactionally under `lockForUpdate` on the `installation` row.
4. The application regenerates the session ID and starts the user session.
5. It sends verification mail when mail is configured (the user is already verified; this is a courtesy notification).
6. Future `/setup` returns 404; unaffiliated registration is rejected; a valid invitation is required.

### Invitation acceptance

1. An owner creates an invitation for an email address.
2. The UI shows a copyable link and attempts email delivery when available.
3. The recipient follows the link and signs in or registers.
4. Acceptance revalidates invitation and membership constraints transactionally with `lockForUpdate` on the invitation and the user's membership rows.
5. The invitation is consumed and a member membership is created in the same transaction.

An acceptance failure leaves no partial user/membership relationship. The winner of accept-vs-revoke races is the transaction that acquires the lock first; the loser sees "invitation not available."

### Browser authorization

Authenticated product routes (`/app/*`) require:

1. A valid session
2. Email verification when deployment policy requires it (`oast.enforce_email_verification`)
3. A resolvable sole organization membership (via `OrganizationContext`)
4. Resource policy authorization

**Zero-membership users:** removing a member leaves them with an account, a session, and zero memberships. They see a **"You are not a member of any organization"** holding page on all `/app/*` routes, with a link to account/password management (`/app/settings/account`). They can be re-invited (the "already belongs to an organization" check passes since they have no membership). They cannot access any review or organization management route. This state is explicitly handled, not a 403 on every route.

Sensitive actions require recent password confirmation (Fortify middleware):

- Creating or revoking tokens
- Removing members
- Transferring ownership
- Changing password
- Changing email

### Token authorization

A bearer request:

1. Finds the token by its public identifier.
2. Constant-time compares the submitted secret.
3. Verifies `expires_at` and `revoked_at`.
4. Verifies current membership in the token's organization (query `organization_memberships` for `user_id` + `organization_id`).
5. Resolves that organization as request context via `OrganizationContext`.
6. Enforces fixed review-only abilities.

Invalid authentication returns 401 (`Unauthenticated` problem type). A valid token attempting a forbidden operation returns 403 (`Forbidden` problem type). Cross-organization or unknown review IDs return 404 (`NotFound` problem type, already in the `ProblemType` enum).

### Abuse controls

- **Login throttling:** keyed by canonical email and trustworthy client IP. `bootstrap/app.php` already configures `trustProxies` to trust `X-Forwarded-For`, `X-Forwarded-Proto`, and `X-Forwarded-Port` only — no arbitrary forwarded client-IP headers. Fortify's default rate limits apply; invitation acceptance is throttled separately (it is not a Fortify route).
- **Per-token and per-organization review limits:** a configurable ceiling on active reviews per organization (`oast.max_active_reviews`, default 10). Exceeding returns 429.
- **SSE concurrency:** a configurable ceiling on concurrent SSE streams per token/user (`oast.max_concurrent_streams`, default 5). Exceeding returns 429.
- **RFC 9457 for API 429s:** a new `RateLimited` problem type with `Retry-After` header.
- Self-host mode does not trust arbitrary forwarded client-IP headers beyond the standard `X-Forwarded-*` set already configured in `bootstrap/app.php`.

### Review lifecycle

Organization members can create and read shared reviews. SSE authorization is completed before streaming begins and re-checked on each poll iteration. Members may delete their own reviews; owners may delete any organization review. Deletion is permanent and cascades through the immutable snapshot and execution artifacts.

### Email change flow

Changing email resets `email_verified_at` to null and sends a verification email (when enforcement is enabled). Pending invitations bound to the old email are not automatically updated — an owner can revoke and re-create them. Login with the old email fails after the change.

### Hosted oast.sh lockout paradox

**Explicit decision:** the hosted oast.sh production instance runs `SelfHostedRegistrationPolicy` until M4. This means hosted oast.sh is effectively **single-tenant until M4** — the first operator claims the installation, and every external CLI user must be invited into the operator's organization to use the API. This is accepted as the M3A production posture, not an oversight. M4's `CloudRegistrationPolicy` restores public registration with per-user organization creation.

### Error handling

Browser forms use standard validation messages and safe redirect-back behavior. API failures retain RFC 9457 Problem Details (`application/problem+json`), rendered by the existing exception handler which already checks `$request->getHost() === config('oast.api_domain')`.

New problem types added to the `ProblemType` enum:

- `Unauthenticated` — 401
- `Forbidden` — 403
- `RateLimited` — 429

Explicit domain conflicts (browser):

- Bootstrap already completed → `/setup` returns 404
- Invitation expired, revoked, consumed, or email-mismatched → "invitation not available" page
- User already belongs to an organization → validation error on acceptance
- Attempt to remove, demote, or leave as the final owner → validation error
- Zero memberships → holding page (not an error)
- Expired or revoked token → 401 on API, redirect to login on web

Passwords, invitation plaintext, token secrets, and the bootstrap secret never enter logs. Login failures are indistinguishable for unknown email and incorrect password.

## M3A management UI

Blade-first screens use the existing oast design system (Tailwind 4, Vite Plus, custom Blade views):

- Register (invitation-gated), login, verify email, forgot/reset password, confirm password
- Account/profile and password management (`/app/settings/account`)
- Organization name and membership list (`/app/settings/organization`)
- Create, resend/replace, copy, and revoke invitations
- Remove members and transfer ownership
- Create, list, and revoke personal access tokens (`/app/settings/tokens`)

Focused JavaScript (Alpine.js) is permitted only where it improves small interactions such as copying a just-created token or invitation URL and confirming destructive actions. M3A does not introduce an application frontend framework.

## Testing

### Identity and bootstrap

- First setup creates exactly one user, organization, owner membership, and `installation.bootstrapped_at`.
- Legacy reviews are claimed with a null creator.
- Concurrent setup attempts cannot create two organizations (unique index / `lockForUpdate`).
- `/setup` returns 404 after bootstrap; deleting all users does not reopen it.
- Bootstrap secret is required; without it, `/setup` rejects.
- Bootstrap user is auto-verified.
- Registration closes after bootstrap unless accompanied by a valid invitation.
- Verification enforcement follows deployment policy.
- Login, logout, reset, confirmation, and throttling behavior are covered.
- Login failures are indistinguishable for unknown email and incorrect password.

### Organizations and invitations

- Owners can invite, replace/resend, revoke, and copy invitation links.
- Members cannot manage invitations or memberships.
- Invitation tokens are 256-bit CSPRNG, hashed, expire, and work only once.
- `revoked_at` is honored; a revoked invitation cannot be accepted.
- Concurrent accept/accept attempts: exactly one succeeds, the other sees "not available."
- Concurrent accept/revoke attempts: the winner is deterministic (lock order).
- Email mismatch and existing-membership acceptance fail.
- The final owner cannot leave, be removed, or be demoted (enforced with `lockForUpdate`).
- Failed acceptance rolls back without partial membership state.
- Unknown, expired, used, and revoked invitations return the same response.
- `Referrer-Policy: no-referrer` is set on invitation pages.

### Tokens and organization context

- Token plaintext appears only once.
- Token response includes `Cache-Control: no-store, private`.
- Tokens are permanently organization-scoped (`organization_id` is immutable).
- Revoked and expired tokens fail immediately (401).
- Removing membership invalidates effective token access.
- Tokens can create/read/follow reviews but cannot delete or administer.
- PATs are not accepted for administrative web routes.
- Password confirmation is required before token creation/revocation.
- Browser sessions and API tokens resolve the same organization without trusting request input.
- `last_used_at` updates are throttled (once per minute).

### Review isolation

- Every new review records organization and creator from trusted context.
- POST with `organization_id`/`created_by_user_id` in the payload is ignored (negative test).
- Organization members can read shared reviews.
- Cross-organization reads and SSE connections return 404.
- Unknown and cross-organization review IDs return the same 404.
- Members may delete their own reviews; owners may delete any organization review.
- Creatorless legacy reviews can be deleted only by an owner.
- Review deletion cascades through events and panel responses.
- Jobs continue safely without an authenticated request.
- `oast:review` requires `--organization`; without it, the command errors.

### SSE authorization

- The SSE controller resolves the review through `OrganizationContext` (404 on cross-org).
- A revoked token's stream terminates on the next poll iteration.
- A removed member's stream terminates on the next poll iteration.
- Replay after reconnect works (`Last-Event-ID`).
- Opening a completed review replays all events and returns immediately.

### Abuse controls

- Login throttling by email + IP.
- Per-org active review limit returns 429 with `Retry-After`.
- Concurrent SSE stream limit returns 429.
- API 429s render as RFC 9457 problem details.

### Web client (M3B)

- Reviews index renders with loading, empty, and failed states.
- New review submission returns 202 and redirects to live view.
- Live review view streams events via `EventSource`.
- Report view groups findings by severity.
- Inline spec pane highlights source ranges from JSON Pointer (or shows fallback).
- Settings pages render for organization, tokens, and account.
- Public `/reviews/*` pages remain unaffected by auth.

### Docker (M3B)

- Container starts FrankenPHP + queue worker via supervisor.
- Migrations run at startup; failure exits non-zero.
- `/up` checks database + migration status.
- Persistent volume survives restart.
- SSE works behind reverse proxy (no buffering).

### Quality gates

- Pest feature and unit tests maintain 100% line and type coverage.
- PHPStan remains at maximum level (bleedingEdge).
- Fortify/Sanctum integration is covered by feature tests rather than mocks of framework internals.
- A migration/bootstrap test covers a database containing legacy reviews.
- Security-sensitive flows include explicit negative-authorization and race-condition tests with named mechanisms (unique index, `lockForUpdate`, per-iteration recheck).

## Decisions locked

| Question                      | Decision                                                                                                                                                                   |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Milestone order               | M3A identity/organizations → M3B Web client → M4 cloud commercialization                                                                                                   |
| Shared implementation home    | Build in `oast-server` first, then import into `oast-cloud`                                                                                                                |
| Tenant primitive              | Organization in code, API, database, and UI ("Workspace" rejected)                                                                                                         |
| Membership cardinality        | Many-to-many schema; one membership per user enforced via unique index until M4                                                                                            |
| Roles                         | Owner and member (`OrganizationRole` enum); no `admin` or configurable RBAC                                                                                                |
| Final-owner invariant         | Enforced with `lockForUpdate` over owner memberships                                                                                                                       |
| Self-host bootstrap           | `/setup` protected by a one-use bootstrap secret; first user becomes owner, auto-verified; registration then closes; persisted independent of user count                   |
| Bootstrap singleton           | `installation` row created by migration/seed; `lockForUpdate` serializes concurrent setups                                                                                 |
| Cloud registration seam       | Shared workflow with a cloud-specific `RegistrationPolicy`                                                                                                                 |
| Browser auth                  | Email/password with verification and reset via Fortify                                                                                                                     |
| Self-host without SMTP        | Verification enforcement configurable (`oast.enforce_email_verification`, default false); CLI password reset; CLI verify; copyable invitation URLs                         |
| Bootstrap user verification   | Auto-verified at creation time                                                                                                                                             |
| CLI/CI auth                   | Organization-scoped Sanctum personal access tokens                                                                                                                         |
| Token model                   | Custom Sanctum with immutable `organization_id`, fixed non-destructive abilities, `expires_at`, `revoked_at`, throttled `last_used_at`                                     |
| Token abilities               | `review:create`, `review:read`, `review:follow` only; no deletion or administration                                                                                        |
| Token plaintext               | Shown once; response includes `Cache-Control: no-store, private`                                                                                                           |
| Password confirmation         | Required for token creation/revocation, member removal, ownership transfer, password/email change                                                                          |
| Review visibility             | Shared with every current organization member                                                                                                                              |
| Review deletion               | Creator or organization owner; creatorless legacy reviews owner-only                                                                                                       |
| Spec retention                | Immutable snapshot retained with each review until deletion                                                                                                                |
| Persistent spec/project model | Deferred; review snapshots only                                                                                                                                            |
| Invitations                   | Expiring, single-use, email-bound, owner-managed, revocable (`revoked_at`); 256-bit CSPRNG token hashed; no signed URL; same response for unknown/expired/used/revoked     |
| Invitation token              | 256-bit CSPRNG, stored as hash only; no Laravel signed URL (avoids APP_KEY rotation breakage)                                                                              |
| API topology                  | `api.*` for token clients (stateless bearer); same-origin `/app/*` web routes for browser (session + CSRF); shared actions/policies                                        |
| Protected web namespace       | `/app/*` for authenticated routes; `/reviews/*` remains publication pages                                                                                                  |
| Setup redirect scope          | Only protected `/app/*` routes redirect to `/setup`; public pages, `/up`, assets remain reachable                                                                          |
| Anonymous API                 | Removed; `EnsureApiEnabled` and `OAST_API_ENABLED` removed                                                                                                                 |
| SSE authorization             | Checked before streaming + re-checked on each poll iteration; revoked tokens/members terminate immediately                                                                 |
| SSE replay                    | `Last-Event-ID` replay; recover terminal state from `reviews.status`                                                                                                       |
| Mass-assignment protection    | `organization_id` and `created_by_user_id` guarded or explicitly assigned; negative test enforced                                                                          |
| `organization_id` nullability | Permanently application-level NOT NULL (column is nullable; no tightening migration)                                                                                       |
| Console ownership             | `oast:review` requires `--organization` flag                                                                                                                               |
| CLI compatibility             | `oast roast` gains `--token`/`OAST_TOKEN`; GitHub Action accepts token secret; 401/403 render as RFC 9457                                                                  |
| Zero-membership users         | Holding page on `/app/*`; account management accessible; re-invitable                                                                                                      |
| Account deletion              | Self-service deferred out of M3A; user deletion behavior defined for referential integrity                                                                                 |
| Hosted oast.sh posture        | Single-tenant until M4 (explicit decision, not oversight)                                                                                                                  |
| Email canonicalization        | Lowercase + trim; enforced in setup, invitation, login, reset                                                                                                              |
| Email change                  | Resets `email_verified_at`; pending invitations not auto-updated                                                                                                           |
| Frontend architecture         | Blade + Alpine.js + native EventSource; no SPA/Livewire                                                                                                                    |
| Auth features included        | Registration (invitation-gated), login, logout, verification, password reset, password confirmation, throttling                                                            |
| 2FA                           | Excluded from M3; recorded to prevent ambient assumption                                                                                                                   |
| OAuth/SSO                     | Excluded from M3; deferred to M4+                                                                                                                                          |
| Abuse controls                | Login throttling (email + IP); per-org active review limit; concurrent SSE limit; RFC 9457 for 429s                                                                        |
| Sanctum stateful domains      | API subdomain excluded from `stateful` config to prevent cookie-authenticated API requests                                                                                 |
| Review statuses               | `queued`, `running`, `judging`, `complete`, `error` (from existing implementation)                                                                                         |
| JSON Pointer → source mapping | Concrete-syntax-tree-aware parser preserves source ranges; fallback to raw spec if unavailable; deferred to M3B implementation decision                                    |
| Docker process model          | Supervisor (s6-overlay/supervisord) running FrankenPHP + queue worker; persistent volume at `/var/lib/oast`; `APP_KEY` required; migrations at startup; `/up` health check |
| Legacy reviews                | Claimed by first organization during bootstrap; creator remains null                                                                                                       |

## Out of scope (deferred)

- Multiple organizations per user, organization creation and switching → **M4 (cloud overlay)**
- Additional roles or configurable RBAC → M4+
- Plans, quotas, metering, billing, and managed credentials → M4
- OAuth/SSO, 2FA → M4+
- Self-service account deletion → post-M3A
- Configurable or ephemeral review retention → M4
- The ratatui TUI and other M5+ rings
- Selectable granular token scopes and device authorization → M4+
