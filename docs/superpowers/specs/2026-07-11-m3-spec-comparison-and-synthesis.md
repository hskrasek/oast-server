# M3 Spec Comparison & Synthesis Recommendation

**Date:** 2026-07-11
**Purpose:** Compare the two brainstormed M3 design specs and their adversarial reviews, identify strengths/weaknesses, alignment/divergence, and recommend a path forward.

## Documents reviewed

| Label              | Spec                                                   | Author model |
| ------------------ | ------------------------------------------------------ | ------------ |
| **Spec A (Sol)**   | `2026-07-11-oast-m3a-identity-organizations-design.md` | GPT-5.6 Sol  |
| **Spec B (Fable)** | `2026-07-11-oast-m3-auth-web-client-design.md`         | Fable 5      |

| Label           | Review                                                        | Reviewer → Subject       |
| --------------- | ------------------------------------------------------------- | ------------------------ |
| **Review of A** | `2026-07-11-oast-m3a-identity-organizations-design-review.md` | Fable 5 → Sol spec       |
| **Review of B** | `2026-07-11-oast-m3-auth-web-client-design-review.md`         | GPT-5.6 Sol → Fable spec |

## Context: the original sequencing

The build spec (`docs/oast-build-spec.md`) originally planned:

- **M3 — Web client** (no auth)
- **M4 — Cloud** (auth, billing, managed keys)

Both specs independently revise this: **self-hosted installs want auth too**, so auth moves into M3. Both design the schema to be team/org-shaped from day one so M4 needs no tenancy backfill. This is the fundamental agreement.

---

## 1. Scope comparison

| Dimension             | Spec A (Sol)                                   | Spec B (Fable)                                           |
| --------------------- | ---------------------------------------------- | -------------------------------------------------------- |
| **Milestone scope**   | M3A only (identity/org/auth foundation)        | M3a + M3b (auth foundation + web client)                 |
| **Web client**        | Deferred to M3B entirely                       | Fully specified (index, new review, live view, settings) |
| **Docker/deployment** | Not addressed                                  | Addressed (but underspecified)                           |
| **Management UI**     | Specified (account, org, invitations, tokens)  | Specified (team settings, tokens)                        |
| **Depth on identity** | Very deep — explicit models, seams, invariants | Lighter — single team, admin flag, no role system        |
| **Length**            | ~180 lines, dense                              | ~95 lines, concise                                       |

**Key structural difference:** Spec A deliberately splits M3 into M3A (foundation) → M3B (web client), delivering only the foundation. Spec B covers both phases in one document. This means Spec A has much more depth on the auth/identity layer, while Spec B has the product vision Spec A lacks.

---

## 2. Architectural alignment

Both specs agree on these fundamental decisions:

1. **Open-core boundary:** Auth lives in `oast-server` (AGPL base); cloud commercialization in `oast-cloud` (private overlay).
2. **Schema is tenant-shaped from day one:** Every review belongs to a team/org; M4 needs no `team_id`/`organization_id` backfill.
3. **No anonymous API:** Both remove anonymous review API access entirely — not deferred, dropped.
4. **Laravel Sanctum for API tokens:** Both use Sanctum for personal access tokens.
5. **Invitations as shared M4 machinery:** Both design invitations to be reused by cloud per-team registration.
6. **Blade-based frontend:** No SPA framework, no Livewire. Existing Tailwind design system.
7. **Self-host bootstrap:** First user becomes admin/owner and creates the tenant.
8. **CLI password reset:** Both provide an artisan command for no-SMTP lockout recovery.
9. **RFC 9457 Problem Details:** Both maintain `application/problem+json` for API errors.
10. **Testing:** Pest feature tests, 100% line + type coverage, PHPStan level max, agent fakes for LLM interactions.
11. **SSE as shared contract:** Both treat the event stream as the same contract for browser, CLI, and CI.

---

## 3. Architectural divergence

| Dimension                         | Spec A (Sol)                                                                                                      | Spec B (Fable)                                                                                              | Assessment                                                                                                                                                                                                                                             |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Tenant primitive**              | "Organization" (canonical everywhere; "Workspace" rejected)                                                       | "Team"                                                                                                      | Terminology only; "Organization" is more precise and avoids collision with "team" = the people. **A is stronger.**                                                                                                                                     |
| **Membership schema**             | Explicit `OrganizationMembership` join table (many-to-many)                                                       | `users.team_id` FK (one team per user at schema level)                                                      | A's join table is the correct long-term shape and the spec explains why (M4 needs no redesign). B's FK is simpler but requires a schema migration for M4 multi-team. **A is stronger.**                                                                |
| **Roles**                         | `OrganizationRole` enum (`owner \| member`)                                                                       | `is_admin` boolean                                                                                          | A's enum is extensible; B's boolean is a dead end. **A is stronger.**                                                                                                                                                                                  |
| **Auth features**                 | Full Fortify: registration, login, verification, password reset, password confirmation, throttling                | Login + logout only; explicitly excludes verification, email reset, 2FA, OAuth                              | A is more complete but potentially over-scoped. B is leaner. The right answer is probably A's feature set with B's explicit-exclusion discipline.                                                                                                      |
| **Email verification**            | Always present; enforcement configurable for self-host                                                            | Excluded entirely from M3                                                                                   | A is correct that verification capability should exist; B's exclusion creates a gap (the review of A catches the unverified-bootstrap edge case, but the fix is to auto-verify the bootstrap user, not to drop verification). **A is stronger.**       |
| **Password reset**                | Email-based (when SMTP configured) + CLI                                                                          | CLI only                                                                                                    | A is more complete. B's CLI-only approach is defensible for self-host but leaves cloud without email reset. **A is stronger.**                                                                                                                         |
| **Registration policy seam**      | `RegistrationPolicy` interface (`SelfHostedRegistrationPolicy` / `CloudRegistrationPolicy`)                       | No explicit seam; `/setup` for self-host, vague on cloud                                                    | A's seam is a well-placed abstraction that isolates distribution behavior. **A is significantly stronger.**                                                                                                                                            |
| **Bootstrap mechanism**           | First registration creates org + owner membership + claims legacy reviews; registration closes                    | Fresh install (zero users) → redirect to `/setup`; creates admin + team; closes forever                     | A's registration-based flow is more uniform. B's `/setup` is better UX for the self-host case but has the security hole the review catches (remote takeover). The synthesis should use B's `/setup` UX protected by A's atomic-transaction discipline. |
| **Legacy reviews**                | Claimed by default org with null creator during bootstrap                                                         | "Not a concern" (no released installs); required FK                                                         | A is more robust and honest about real-world data. B's clean-slate assumption is risky. **A is stronger** — but B's pragmatism about no released installs is worth noting.                                                                             |
| **Token abilities**               | Custom Sanctum model with immutable org association; fixed non-destructive abilities (create/read/follow reviews) | Standard Sanctum tokens; team-scoped through user                                                           | A's fixed-abilities approach is a significantly better security default. B's standard Sanctum tokens have no ability constraints. **A is significantly stronger.**                                                                                     |
| **API/web topology**              | Explicit dual surface: `api.*` (token) + same-origin web routes (session); both call same actions/policies        | Contradictory: says all API requires token, but browser uses `EventSource` (which can't set bearer headers) | A correctly identifies and solves the EventSource/cookie/CORS problem. B has a fundamental contradiction. **A is significantly stronger.**                                                                                                             |
| **Organization context**          | Explicit `OrganizationContext` service that resolves trusted org from session or token                            | Implicit (token scoped through user's team)                                                                 | A's explicit service is a clean abstraction that prevents client-supplied org IDs. **A is stronger.**                                                                                                                                                  |
| **Password confirmation**         | Required for sensitive actions (token creation, member removal, ownership transfer, password change)              | Not mentioned                                                                                               | A's approach is correct security practice. **A is stronger.**                                                                                                                                                                                          |
| **Final-owner invariant**         | Explicitly stated: org must always retain at least one owner                                                      | Not addressed                                                                                               | **A is stronger.**                                                                                                                                                                                                                                     |
| **Frontend stack**                | Custom Blade + focused JS (no Alpine mentioned)                                                                   | Blade + Alpine + native `EventSource`                                                                       | B's Alpine addition is pragmatic and appropriate for small interactions. **B is stronger** for the web client.                                                                                                                                         |
| **Web client**                    | Deferred to M3B                                                                                                   | Fully specified with live review view, report view, settings                                                | **B is significantly stronger** — this is the actual product.                                                                                                                                                                                          |
| **Docker**                        | Not addressed                                                                                                     | Specified (single image, SQLite WAL, one-liner run)                                                         | B addresses deployment, A doesn't. But B's spec is underspecified (review catches this). **B is stronger** on intent, needs detail.                                                                                                                    |
| **SSE auth**                      | Both session and token; explicit authorization before streaming                                                   | Both session and token; but EventSource can't set headers (contradiction)                                   | A's dual-surface approach resolves this. **A is stronger.**                                                                                                                                                                                            |
| **Review deletion**               | Creator or owner; creatorless legacy owner-only; cascades through events + panel responses                        | Not specified                                                                                               | **A is stronger.**                                                                                                                                                                                                                                     |
| **Review statuses**               | Not specified (deferred to M3B)                                                                                   | Queued/running/complete (but review catches: actual impl has running/judging/complete/error)                | B attempts it but gets it wrong. Neither is adequate.                                                                                                                                                                                                  |
| **Source pointer → line mapping** | Deferred to M3B                                                                                                   | Claimed (inline spec pane with finding highlighting) but doesn't address YAML source mapping                | B attempts it but the review catches a fundamental gap (JSON Pointer ≠ YAML source lines). Neither is adequate.                                                                                                                                        |
| **Decisions locked table**        | 20+ explicit decisions                                                                                            | Implicit in prose                                                                                           | **A is significantly stronger** for implementation planning.                                                                                                                                                                                           |

---

## 4. Review analysis

### Review of Spec A (Fable reviewing Sol) — 18 findings

**Critical (4):**

1. **Hosted oast.sh lockout paradox** — Removing anonymous API before `CloudRegistrationPolicy` exists (M4) means the production oast.sh locks out all external CLI users. The spec never states this consequence.
2. **One-membership limit race** — Application-level check is insufficient; concurrent invitation acceptances can create two memberships. Needs unique index or `lockForUpdate`.
3. **Final-owner invariant race** — Two owners concurrently demoting each other both pass the check and leave zero owners. Needs row locking.
4. **SSE contradicts immediate revocation** — Membership removal claimed as immediate, but SSE stream stays open for the entire review duration once authorized.

**Important (7):** Mass-assignment not mechanized · Artisan `oast:review` ownership undefined · `organization_id` nullable forever (not just "transition") · Zero-membership users unspecified · Account deletion unaddressed · CLI compatibility hand-waved · Bootstrap user can be permanently unverified.

**Minor (7):** Email change flow · No 2FA decision recorded · Sanctum stateful-domains hazard · Invitation dual mechanism vs APP_KEY rotation · Rate limits unquantified · Bootstrap singleton lock provenance · Token `last_used_at` write amplification.

**Verdict:** "Domain model and seam placement are sound; nothing requires structural redesign." The findings are invariant-enforcement gaps and spec omissions, not architectural flaws.

### Review of Spec B (Sol reviewing Fable) — 13 blocking + hardening

**Blocking (13):**

1. **First-run setup permits remote takeover** — Zero-users check is neither auth nor proof of operator control; race-prone.
2. **Token-only API contradicts browser design** — `EventSource` cannot attach bearer headers; fundamental contradiction.
3. **Private review pages collide with public routes** — No namespace for authenticated review pages vs existing `GET /reviews` publication routes.
4. **Setup redirect conflicts with public/auth routes** — "All web routes redirect to /setup" would break public pages and create loops.
5. **Team authorization not enforced at resolution** — Implicit model binding returns unrestricted `Review`; missed policy = cross-team leak.
6. **Invite revocation absent and non-atomic** — No `revoked_at` field; concurrent accepts can succeed.
7. **Tenant/admin invariants incomplete** — Cardinality, deletion rules, final-admin invariant all undecided.
8. **Session/CSRF/credential-revocation undefined** — No middleware/HTTP method definitions for mutations.
9. **No abuse controls** — No throttling, concurrency limits, or SSE stream limits.
10. **Identity normalization unspecified** — Email canonicalization, password rules, privilege assignment.
11. **JSON Pointer ≠ YAML source lines** — Fundamental gap in the inline spec highlighting feature.
12. **Live/reconnect/report states underspecified** — Statuses, SSE replay, error states, cost display.
13. **Docker lacks operational contract** — No process model, persistence, supervision, health checks.

**Important hardening (10):** CSPRNG tokens · Referrer-Policy · Indistinguishable login failures · PAT abilities + expiry · No PATs for admin routes · Password confirmation before PAT · `Cache-Control: no-store` for token responses · Credentials excluded from URLs/logs.

**Verdict:** "Needs revision." The findings include both architectural contradictions (#2, #3, #4) and spec gaps.

### Review quality comparison

| Dimension                | Review of A (by Fable)                                                          | Review of B (by Sol)                                                                               |
| ------------------------ | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| **Finding count**        | 18 (4 critical, 7 important, 7 minor)                                           | 13 blocking + 10 hardening                                                                         |
| **Architectural issues** | None — all are enforcement/gap issues on a sound architecture                   | Several — #2 (EventSource contradiction), #3 (route collision), #4 (setup redirect) are structural |
| **Codebase grounding**   | Yes — references `ReviewEventsController`, `Review.$guarded`, `config/oast.php` | Yes — references existing public routes, `EventSource` behavior, Dockerfile                        |
| **Actionable fixes**     | Each finding has a clear fix                                                    | Each finding has a "Required correction" block                                                     |
| **Depth**                | Deeper on invariant/race conditions                                             | Deeper on web client and deployment gaps                                                           |

**Key observation:** The review of Spec A found no structural problems — only enforcement and omission gaps on a sound architecture. The review of Spec B found multiple structural contradictions that require redesign, not just filling gaps. This is the most important signal in the comparison.

---

## 5. Strengths and weaknesses summary

### Spec A (Sol) — Identity and Organizations

**Strengths:**

- Significantly deeper domain modeling (OrganizationMembership, OrganizationRole, OrganizationContext, RegistrationPolicy)
- Correct API/web topology (dual surface solves EventSource/cookie/CORS)
- Strong token security model (fixed non-destructive abilities, immutable org association, membership re-check)
- Explicit security practices (password confirmation, final-owner invariant, hashed invitation tokens)
- Thoughtful legacy review handling (claimed by org, null creator, deletion rules)
- 20+ explicit decisions locked — implementation-ready
- Comprehensive, organized testing section with named negative/race tests
- Clean abstractions that prevent client-supplied tenant IDs

**Weaknesses:**

- No web client spec (M3B entirely deferred)
- No deployment/Docker spec
- No review workspace UX (the actual product)
- Overly dense prose — harder to read than Spec B
- Several enforcement gaps caught by review (race conditions, SSE revocation, artisan ownership)
- Hosted lockout paradox unstated
- CLI compatibility hand-waved

### Spec B (Fable) — Auth Foundation + Web Client

**Strengths:**

- Complete scope covering both auth AND web client — full product vision
- Concise, well-organized, readable
- Web client detail (live review view, EventSource streaming, report view, settings)
- Docker deployment intent
- Pragmatic about legacy data (clean slate)
- Explicit feature exclusions (OAuth, 2FA, verification)
- SSE as "fourth tail -f" framing — excellent mental model
- `/setup` flow is good self-host UX
- Alpine is a pragmatic frontend choice

**Weaknesses:**

- Fundamental EventSource/auth contradiction (structural, not just a gap)
- Route namespace collision with existing public routes (structural)
- Setup redirect breaks public pages (structural)
- Setup permits remote instance takeover (critical security)
- No invite revocation field
- Shallow membership model (is_admin boolean, no final-admin invariant)
- Missing security details (no password confirmation, no token abilities, no email normalization)
- No abuse controls
- JSON Pointer → YAML source mapping gap
- Underspecified review states and SSE recovery
- Docker underspecified (no process model, persistence, supervision)
- No RegistrationPolicy seam for cloud
- Team authorization not enforced at model binding level

---

## 6. Assessment

**Neither spec is implementation-ready as-is.** Both have significant gaps caught by their adversarial reviews. However, the nature of the gaps is fundamentally different:

- **Spec A's gaps are fillable** — the architecture is sound; the review found enforcement mechanisms to specify (unique indexes, row locks, SSE re-checks) and omissions to address (artisan ownership, zero-membership users, account deletion). None require redesign.
- **Spec B's gaps include structural contradictions** — the EventSource/auth contradiction, route collisions, and setup redirect all require architectural decisions, not just gap-filling.

**Spec A has the stronger foundation; Spec B has the stronger product vision.** They are complementary:

| What Spec A does better             | What Spec B does better       |
| ----------------------------------- | ----------------------------- |
| Identity/authorization domain model | Web client product vision     |
| API/web topology                    | Deployment intent             |
| Token security model                | Concise, readable structure   |
| Registration policy seam            | Alpine for small interactions |
| Legacy review handling              | Review workspace UX           |
| Explicit decisions table            | SSE "fourth tail -f" framing  |
| Password confirmation               | `/setup` UX pattern           |
| Final-owner invariant               | Complete M3 scope (a + b)     |

---

## 7. Recommendation: Synthesize

**Pick Spec A as the structural base and synthesize Spec B's web client, deployment, and UX contributions into it.** This gives the best of both worlds: A's sound architecture + B's product vision.

### Synthesis structure

**Milestone naming:** Adopt A's M3A/M3B/M4 sequence. M3A = identity foundation, M3B = web client, M4 = cloud. Building the web client on the foundation is the correct order.

**From Spec A (keep as-is):**

- Organization/OrganizationMembership/OrganizationRole domain model
- OrganizationContext service
- RegistrationPolicy interface + SelfHostedRegistrationPolicy
- API/web dual-surface topology (`api.*` token + same-origin web routes)
- Token model (custom Sanctum, immutable org association, fixed non-destructive abilities)
- Legacy review claiming during bootstrap
- Password confirmation for sensitive actions
- Final-owner invariant
- Review deletion rules (creator or owner, creatorless legacy owner-only)
- Decisions locked table format
- Testing section structure

**From Spec B (merge in):**

- M3B web client spec (reviews index, new review, live review view, report view, settings)
- Blade + Alpine + native EventSource frontend stack
- Docker self-host image (but properly specified per review findings)
- SSE "fourth tail -f" framing
- `/setup` as the self-host bootstrap UX (protected by A's atomic transaction)
- Explicit feature exclusion discipline (record what's NOT included and why)

**From Review of A (must address in synthesis):**

- Unique index on `organization_memberships.user_id` (or `lockForUpdate`) for one-membership enforcement
- `lockForUpdate` on owner memberships for final-owner invariant
- SSE re-checks token/membership validity on each poll iteration (or document the streaming window as accepted exposure)
- Explicit hosted-lockout-paradox decision row (hosted is single-tenant until M4)
- Artisan `oast:review` ownership resolution (require `--organization` flag or use default org)
- State that `organization_id` nullability is permanently application-level (not a "transition")
- Zero-membership user experience (holding page? re-invitable? account management accessible?)
- Account deletion explicitly deferred or specified (with final-owner interaction)
- CLI compatibility in M3A scope list (bearer auth support, token config, 401 behavior)
- Auto-verify bootstrap user (or add CLI verify command)
- Mechanize mass-assignment protection (guarded columns or explicit assignment + negative test)
- Bootstrap singleton row created by migration/seed (not by first registration)

**From Review of B (must address in synthesis):**

- Protected web route namespace (`/app/reviews` or similar) — public `/reviews/*` stays publication
- Setup redirect scoped to protected routes only; list what remains reachable before setup
- Setup protected by high-entropy one-use bootstrap secret (not just zero-users check)
- Installation completion persisted independently of user count
- Invite `revoked_at` field + atomic consumption
- Session/CSRF lifecycle: POST for mutations, session regeneration after setup/login/acceptance
- Abuse controls: login throttling, per-token/per-org review limits, SSE concurrency ceiling
- Email canonicalization, password rules, `is_admin`/`is_admin` non-null default false
- JSON Pointer → YAML source range mapping strategy (or defer the inline highlighting to M3B+)
- Canonical review statuses + UI labels, SSE replay/reconnect behavior, terminal-state recovery
- Docker operational contract: supervisor process model, persistent volume, APP_KEY provisioning, health checks, worker count

### Resulting document structure

```
M3 — Identity, Organizations, and Web Client (Synthesized Design)

  Goal & milestone sequence (M3A → M3B → M4)
  Product and domain decisions (from A)
    Open-core boundary
    Canonical language (Organization)
    Membership model
    Review ownership and visibility
  Scope
    M3A deliverables (from A)
    M3B deliverables (from B, adapted to A's org model)
    Deferred to M4
  Architecture
    Fortify + Sanctum (from A)
    Identity (from A)
    Organizations and memberships (from A)
    Organization context (from A)
    Registration policy seam (from A)
    Self-host bootstrap (A's transaction + B's /setup UX + review's bootstrap secret)
    Invitations (from A + review's revoked_at)
    Personal access tokens (from A)
    Review ownership and query scoping (from A)
    Browser and API surfaces (from A — dual topology)
  M3B — Web client (from B, adapted)
    Stack: Blade + Alpine + native EventSource
    Pages: reviews index, new review, live view, report view, settings
    Route namespace: /app/* for authenticated, /reviews/* stays public
  Self-host Docker image (from B, properly specified per review)
  Data model and migration (from A)
  Data flows and security behavior (from A + review fixes)
    Race condition mechanisms (unique indexes, lockForUpdate)
    SSE re-check strategy
    Zero-membership user experience
    Abuse controls (from review of B)
  Error handling (from A + B)
  Testing (from A, extended with B's web client tests + review-specified tests)
  Decisions locked (from A, extended with all review decisions)
```

### Why synthesis over picking one + refining

1. **Spec A cannot be refined into a complete M3 spec** — it has no web client, no deployment, and adding those is not "refinement" but authoring new content that Spec B already has.
2. **Spec B cannot be refined into a sound M3 spec** — its EventSource/auth contradiction, route collisions, and setup security hole require architectural decisions that Spec A has already made correctly. Refining B means re-deriving A's architecture.
3. **The synthesis is mostly assembly** — A's foundation is sound (review confirmed no structural redesign needed), and B's web client is the right product vision. The synthesis combines them and addresses both reviews' findings. This is less work than either refining B to fix structural issues or building the web client from scratch on A's foundation.
4. **The reviews give us the punch list** — every gap, race, and omission is already identified. The synthesis can address them systematically rather than discovering them during implementation.

---

## 8. Next steps

1. **Write the synthesized spec** following the structure above, using Spec A as the base and merging Spec B's web client/deployment sections.
2. **Address every finding from both reviews** as explicit decisions or mechanisms in the spec.
3. **Cross-review the synthesis** (optional but recommended) to catch any new contradictions introduced by merging.
4. **Proceed to implementation planning** once the synthesis is clean.
