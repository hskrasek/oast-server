# oast.sh — Design Mode (`oast design`) (Design)

> Scope: pull the "greenfield/conversational mode" ring forward from M5+ in
> `docs/oast-build-spec.md`. A terminal co-author that grows an OpenAPI spec file from
> nothing, with the existing Council available as an on-demand checkpoint. Builds on the
> M1 stream/CLI work (`docs/superpowers/specs/2026-07-04-oast-m1-stream-cli-design.md`).
> Motivation: the author needs this today for a real greenfield API; dogfooding is the
> acceptance test.

## Goals & success criteria

1. `oast design api.yaml` opens a line-oriented REPL. Each prose turn rewrites the file on
   disk and prints a short summary of what changed.
2. Hand edits made in an editor between turns are always what the model sees next.
3. `/roast` inside the loop runs the existing Council on the current file and feeds the
   findings into the next drafting turn.
4. A session is resumable: closing the terminal and re-running the command continues the
   conversation.
5. Nothing new is persisted server-side. The server stays stateless per request.

## Non-goals

Server-side design sessions or spec versioning, a web surface for design mode, streaming
the drafter's reply token-by-token, `--watch` re-review on save, a diff preview before
writing, patch/JSON-pointer edits instead of whole-spec rewrite, Council-in-the-loop
drafting on every turn, Spectral or schema validation of the drafted spec, Arazzo, and
multi-line REPL input. Each is a deliberate deferral; see "Deferred" at the end.

## Architecture

The review engine already proves the shape `(spec, request) → result` with no server
session. A design turn is the same shape with a `history` array added.

```
oast design api.yaml
  │ read api.yaml (may be empty)            api.yaml.oast-design.jsonl
  │ read session file ──────────────────────────────┘
  │ read a line from stdin
  ▼
POST /design/turns {spec, message, history}
  │                                  ┌──────────────────────────┐
  │  Laravel ────────────────────────▶ Designer agent (1 model)  │
  │                                  │ structured out {spec,reply}│
  │  parse YAML; retry once on fail  └──────────────────────────┘
  ▼
200 {spec, reply}
  │ mtime unchanged? write api.yaml : write api.yaml.oast-design.new
  │ append user + assistant lines to session file
  │ print reply
  └─ loop
```

The spec file is the state. The transcript only carries intent that the spec cannot
express ("keep loans immutable"). Losing the transcript loses nothing recoverable from
the file.

## Server

### Route

`POST /design/turns`, on the `oast.api_domain` group behind `EnsureApiEnabled`, alongside
the review routes. Synchronous. No queue, no migration, no model.

Request:

```jsonc
{
  "spec": "openapi: 3.1.0 ...",   // string, required, may be "" on turn one
  "message": "Library lending API. Books, members, loans.",  // string, required
  "history": [                     // array, optional, defaults to []
    {"role": "user",      "content": "..."},
    {"role": "assistant", "content": "..."}
  ]
}
```

Validation: `spec` present (empty allowed) and at most 1 MB; `message` required,
non-empty, at most 16 KB; `history.*.role` in `{user, assistant}`; `history.*.content`
string. Failures return the existing `ProblemType::Validation` 422.

Response `200`:

```jsonc
{ "spec": "openapi: 3.1.0 ...", "reply": "Added Book, Member and Loan. Loans hang off Member because ..." }
```

### `App\Ai\Agents\Designer`

Same shape as `Panelist`: `final readonly`, `Promptable`, instructions read from
`resources/prompts/designer.md`, no tools. Uses the SDK's structured output so the
response is `{spec: string, reply: string}` with no fence parsing.

Model comes from a new `config/oast.php` key:

```php
// Single strong drafting model for design mode; null => the judge slug.
'designer' => env('OAST_DESIGNER'),
```

### Turn handling (`App\Actions\Design\RunDesignTurnAction`)

1. Truncate `history` to the last 20 messages.
2. Build the prompt: history, then the current spec in a fenced block, then the message.
3. Call `Designer`.
4. Parse the returned `spec` as YAML and require a top-level `openapi` key. On failure,
   call once more with the parse error appended to the message. On a second failure,
   throw `InvalidDesignerOutputException` (`ProblemType::InvalidDesignerOutput`, 422,
   `https://oast.sh/problems/designer-output-invalid`).
5. Return `{spec, reply}`.

Provider errors surface through the SDK's exceptions and render as the existing
Problem Details 502 path. Timeout is `oast.timeout`.

### Prompt (`resources/prompts/designer.md`)

Under 150 lines, three parts:

- **Role**: senior API designer co-authoring an OpenAPI 3.1 document with the user.
- **House style**: the naming, pagination, error (RFC 9457), enum and polymorphism
  conventions from the `writing-openapi-specs` skill, and the dimension list from
  `docs/judge-rubric.md`. The drafter aims at what the Council grades against.
- **Output contract**:
  - Return the complete spec every turn. Never elide with `...` or "unchanged". Every
    path present in the input spec must be present in the output unless the user asked
    to remove it.
  - Make one coherent change set per turn. `reply` states what changed in three to six
    lines.
  - Ask at most one clarifying question per turn, and only when the answer changes the
    resource model. Otherwise pick a sensible default and say so in `reply`.
  - Do not invent business rules. Prefer a `description` reading "TBD: confirm with
    owner" over a guess.
  - On an empty input spec, produce `openapi`, `info`, a placeholder `servers` entry,
    and only the resources the message named. Do not scaffold auth, pagination or error
    schemas until asked or until a second resource makes them necessary.

Tuning rule: when the Council repeatedly flags something the drafter does, add one line
to this file. No rule engine.

## CLI (`oast-cli`)

### Command

```
oast design <spec> [--server URL]
```

`spec` is created empty if missing. `--server` reads `OAST_SERVER` like `roast`.

### Loop

Line-oriented REPL on stdin/stdout, prompt `› `. Each iteration:

1. Re-read the spec file from disk and record its mtime.
2. Read one line. Empty line: loop. `/quit` or EOF: exit 0.
3. `/roast`: run the existing `roast` code path on the file, print findings as today,
   then append one assistant-role message to the session containing the findings
   summary (title, severity, location per finding) so the next prose turn can act on
   them. Loop.
4. Any other line is a prose turn: `client.design_turn(spec, line, history)`.
5. On success, if the file's mtime still matches step 1, overwrite it with the returned
   spec. Otherwise write to `<spec>.oast-design.new`, print a one-line warning naming
   that path, and do not touch the original.
6. Append `{"role":"user",...}` and `{"role":"assistant",...}` to the session file.
7. Print `reply`. Loop.

Errors from the server print `error: ...` and return to the prompt; they do not exit the
loop. The session file is not written on a failed turn.

### Session file

`<spec>.oast-design.jsonl` beside the spec. One JSON object per line:
`{"role":"user"|"assistant","content":"...","at":"<RFC 3339>"}`. Missing file means
empty history. The CLI sends every line as `history`; the server truncates. Delete the
file to start over.

### Client

One new method on `api::Client`:

```rust
pub fn design_turn(&self, spec: &str, message: &str, history: &[Message]) -> anyhow::Result<Turn>
```

`Turn { spec: String, reply: String }`. Non-200 responses error with the status and
Problem Details `detail` when present, matching `create_review`.

## Testing

### Server (Pest, coverage gates apply)

- Feature: `POST /design/turns` with the `Designer` agent faked to return a fixture
  `{spec, reply}` responds 200 with that shape.
- Feature: fake returns unparseable YAML twice. Assert the second prompt contains the
  parse error and the response is 422 with the `designer-output-invalid` type.
- Feature: fake fails once then succeeds. Assert 200 and two agent calls.
- Feature: validation. Missing `message`, `spec` over 1 MB, `history` entry with an
  unknown role, each 422.
- Feature: `spec: ""` passes validation and reaches the agent.
- Feature: 25 history messages in, agent receives 20.
- Unit: `Designer::instructions()` returns the prompt file, mirroring the `Panelist` test.
- Unit: `config('oast.designer')` null resolves to the judge slug.

### CLI (`cargo test`, mock server as in `api.rs`)

- `design_turn` posts the expected JSON body and returns `Turn` on 200; errors on 422
  with the Problem Details detail in the message.
- Session file round-trip: append two messages, read back the same two. Missing file
  reads as empty.
- Clobber guard: `should_write(before: SystemTime, now: SystemTime) -> bool` is true
  when equal, false otherwise. One test each.
- `.new` fallback: temp dir, touch the spec after recording mtime, assert the original
  is unchanged and `.oast-design.new` holds the returned spec.
- The REPL loop is not unit tested. It is glue between tested pieces.

### Manual acceptance

One real session against OpenRouter: library-lending API, five prose turns, one `/roast`,
one "fix the blockers" turn. This is the dogfood run and the definition of done.

## Deferred, with the trigger to un-defer

- **Streaming replies**: when 30-second silent waits annoy the author. The SSE plumbing
  from M1 is reusable. Related ceiling: a turn is up to two calls of `oast.timeout`
  (240 s at the default), which exceeds cloudflared's 100 s edge timeout on the hosted
  path. Lower the designer's timeout, or stream, once a real turn hits it.
- **Patch-based edits**: when whole-spec rewrites become slow or lossy on large files.
  Greenfield files are small; this is an M5+ editor concern.
- **Server-side sessions**: when the web client needs design mode. That is the M3/M5
  surface, and the endpoint contract here does not change; the web client would own its
  own session store.
- **Multi-line input**: when a single line is not enough to describe a turn. A trailing
  `\` continuation is the cheapest fix.
- **`--watch`**: when the author wants review-on-save without drafting. Separate command.
