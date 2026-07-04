# oast.sh M1 — `oast-cli` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Working directory: `~/Documents/Projects/Rust/oast-cli` (NEW repo — not oast-server).**

**Goal:** `oast roast <spec>` — POST a spec to the oast server, follow the review's SSE stream, print CI-style progress lines and a findings table, exit 0 (clean) / 1 (blockers) / 2 (error).

**Architecture:** Thin blocking client, three modules: `api` (serde types + reqwest calls), `sse` (a ~50-line SSE frame parser over any `BufRead`), `render` (event lines + findings table). `main.rs` wires clap args through them. Reconnects with `Last-Event-ID` on dropped streams.

**Tech Stack:** Rust 2024 edition, `clap` (derive), `reqwest` (blocking, json), `serde`/`serde_json`, `anyhow`; dev-dep `wiremock` is async so use `httpmock` instead (sync, simpler here).

## Global Constraints

- Rust 2024 edition; `cargo fmt` + `cargo clippy -- -D warnings` clean at every commit.
- MIT license (open-core split: permissive CLI).
- No TUI, no config file, no `--ci` flag — M2 territory.
- Server event contract (from the server spec, verbatim): events
  `review.queued, panel.model.start, panel.model.done, panel.model.failed, panel.model.late, judge.start, judge.done, review.completed, review.failed`;
  SSE frames `id: <n>\nevent: <name>\ndata: <json>\n\n`; `POST /reviews` → 202 `{data: {id, status, ...}}`; stream at `GET /reviews/{id}/events`.
- Findings carry `severity: blocker|should-fix|consider`, `confidence`, `title`, `location`.

---

### Task 1: Scaffold the repo

**Files:**
- Create: `Cargo.toml`, `src/main.rs` (hello stub), `LICENSE` (MIT), `README.md`, `.gitignore`

- [ ] **Step 1: Scaffold**

```bash
cd ~/Documents/Projects/Rust
cargo new oast-cli --edition 2024
cd oast-cli
cargo add clap --features derive
cargo add reqwest --features blocking,json
cargo add serde --features derive
cargo add serde_json anyhow
cargo add --dev httpmock
```

`README.md`: one paragraph — what it is, `oast roast ./openapi.yaml`, `OAST_SERVER` env, exit codes 0/1/2, MIT. `LICENSE`: MIT text, copyright Hunter Skrasek.

- [ ] **Step 2: Verify build** — `cargo build` → compiles; `cargo run` → prints hello stub.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "chore: Scaffold oast-cli (clap + reqwest + serde)"
```

---

### Task 2: SSE parser (`src/sse.rs`)

**Files:**
- Create: `src/sse.rs`; Modify: `src/main.rs` (`mod sse;`)

**Interfaces:**
- Produces: `pub struct SseEvent { pub id: Option<u64>, pub event: String, pub data: String }`; `pub struct SseReader<R: BufRead> { ... }` with `pub fn new(reader: R) -> Self` and `impl Iterator<Item = anyhow::Result<SseEvent>>`. Parses `id:`, `event:`, `data:` lines; a blank line terminates a frame; unknown fields and `:` comment lines are ignored; multiple `data:` lines join with `\n`.

- [ ] **Step 1: Write failing tests (same file, `#[cfg(test)]`)**

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Cursor;

    fn read_all(input: &str) -> Vec<SseEvent> {
        SseReader::new(Cursor::new(input)).map(Result::unwrap).collect()
    }

    #[test]
    fn parses_a_full_frame() {
        let events = read_all("id: 7\nevent: panel.model.done\ndata: {\"model\":\"x\"}\n\n");
        assert_eq!(events.len(), 1);
        assert_eq!(events[0].id, Some(7));
        assert_eq!(events[0].event, "panel.model.done");
        assert_eq!(events[0].data, "{\"model\":\"x\"}");
    }

    #[test]
    fn joins_multiline_data_and_skips_comments() {
        let events = read_all(": keepalive\nevent: e\ndata: a\ndata: b\n\n");
        assert_eq!(events[0].data, "a\nb");
    }

    #[test]
    fn yields_multiple_frames() {
        let events = read_all("event: a\ndata: 1\n\nevent: b\ndata: 2\n\n");
        assert_eq!(events.len(), 2);
        assert_eq!(events[1].event, "b");
    }

    #[test]
    fn ignores_trailing_partial_frame_without_blank_line() {
        let events = read_all("event: a\ndata: 1\n\nevent: dangling\n");
        assert_eq!(events.len(), 1);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `cargo test` → compile error (types missing).

- [ ] **Step 3: Implement**

```rust
// src/sse.rs
use std::io::BufRead;

#[derive(Debug, Clone, PartialEq)]
pub struct SseEvent {
    pub id: Option<u64>,
    pub event: String,
    pub data: String,
}

pub struct SseReader<R: BufRead> {
    reader: R,
}

impl<R: BufRead> SseReader<R> {
    pub fn new(reader: R) -> Self {
        Self { reader }
    }
}

impl<R: BufRead> Iterator for SseReader<R> {
    type Item = anyhow::Result<SseEvent>;

    fn next(&mut self) -> Option<Self::Item> {
        let mut id = None;
        let mut event = String::new();
        let mut data_lines: Vec<String> = Vec::new();
        let mut saw_field = false;

        loop {
            let mut line = String::new();
            match self.reader.read_line(&mut line) {
                Ok(0) => return None, // EOF: drop any partial frame
                Ok(_) => {}
                Err(e) => return Some(Err(e.into())),
            }

            let line = line.trim_end_matches(['\r', '\n']);

            if line.is_empty() {
                if saw_field {
                    return Some(Ok(SseEvent { id, event, data: data_lines.join("\n") }));
                }
                continue; // leading blank lines
            }

            if let Some(rest) = line.strip_prefix("id:") {
                id = rest.trim().parse().ok();
                saw_field = true;
            } else if let Some(rest) = line.strip_prefix("event:") {
                event = rest.trim().to_string();
                saw_field = true;
            } else if let Some(rest) = line.strip_prefix("data:") {
                data_lines.push(rest.strip_prefix(' ').unwrap_or(rest).to_string());
                saw_field = true;
            }
            // ":" comments and unknown fields fall through, ignored
        }
    }
}
```

- [ ] **Step 4: Run to verify pass** — `cargo test` → 4 passing. `cargo clippy -- -D warnings` clean.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Add SSE frame parser"`

---

### Task 3: API types + client (`src/api.rs`)

**Files:**
- Create: `src/api.rs`; Modify: `src/main.rs` (`mod api;`)

**Interfaces:**
- Produces:
  - `pub struct Client { base: String, http: reqwest::blocking::Client }`
  - `Client::new(base: &str) -> Self` (trims trailing `/`)
  - `Client::create_review(&self, spec: &str, dimension: &str, baseline: bool) -> anyhow::Result<u64>` — POST `{base}/reviews` JSON `{"spec": ..., "dimension": ..., "mode": "baseline"|"council"}`, expects 202, returns `data.id`.
  - `Client::stream_events(&self, review_id: u64, last_event_id: u64) -> anyhow::Result<reqwest::blocking::Response>` — GET `{base}/reviews/{id}/events` with `Last-Event-ID` header, `Accept: text/event-stream`, no read timeout.
  - `pub struct Finding { pub severity: String, pub confidence: String, pub title: String, pub location: String }` (serde `Deserialize`, unknown fields ignored)
  - `pub struct Completed { pub findings: Vec<Finding>, pub total_cost_usd: Option<f64> }` (serde `Deserialize`)

- [ ] **Step 1: Write failing tests (httpmock)**

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use httpmock::prelude::*;

    #[test]
    fn creates_a_review_and_returns_its_id() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(POST)
                .path("/reviews")
                .json_body_partial(r#"{"mode": "council", "dimension": "domain-modeling"}"#);
            then.status(202).json_body(serde_json::json!({"data": {"id": 42, "status": "running"}}));
        });

        let id = Client::new(&server.base_url())
            .create_review("openapi: 3.1.0", "domain-modeling", false)
            .unwrap();

        mock.assert();
        assert_eq!(id, 42);
    }

    #[test]
    fn errors_on_non_202() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST).path("/reviews");
            then.status(422).json_body(serde_json::json!({"title": "invalid spec"}));
        });

        let err = Client::new(&server.base_url())
            .create_review("nope", "domain-modeling", false)
            .unwrap_err();
        assert!(err.to_string().contains("422"));
    }

    #[test]
    fn streams_events_with_last_event_id_header() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(GET).path("/reviews/42/events").header("Last-Event-ID", "7");
            then.status(200)
                .header("Content-Type", "text/event-stream")
                .body("id: 8\nevent: review.completed\ndata: {\"findings\":[]}\n\n");
        });

        let response = Client::new(&server.base_url()).stream_events(42, 7).unwrap();
        assert!(response.status().is_success());
        mock.assert();
    }
}
```

- [ ] **Step 2: Run to verify failure** — `cargo test` → compile error.

- [ ] **Step 3: Implement**

```rust
// src/api.rs
use anyhow::{bail, Context};
use serde::Deserialize;

#[derive(Debug, Deserialize)]
pub struct Finding {
    pub severity: String,
    pub confidence: String,
    pub title: String,
    pub location: String,
}

#[derive(Debug, Deserialize)]
pub struct Completed {
    #[serde(default)]
    pub findings: Vec<Finding>,
    pub total_cost_usd: Option<f64>,
}

#[derive(Deserialize)]
struct CreatedEnvelope {
    data: Created,
}

#[derive(Deserialize)]
struct Created {
    id: u64,
}

pub struct Client {
    base: String,
    http: reqwest::blocking::Client,
}

impl Client {
    pub fn new(base: &str) -> Self {
        Self {
            base: base.trim_end_matches('/').to_string(),
            http: reqwest::blocking::Client::builder()
                .timeout(None) // SSE streams are long-lived
                .build()
                .expect("reqwest client"),
        }
    }

    pub fn create_review(&self, spec: &str, dimension: &str, baseline: bool) -> anyhow::Result<u64> {
        let response = self
            .http
            .post(format!("{}/reviews", self.base))
            .json(&serde_json::json!({
                "spec": spec,
                "dimension": dimension,
                "mode": if baseline { "baseline" } else { "council" },
            }))
            .send()
            .context("POST /reviews failed")?;

        if response.status().as_u16() != 202 {
            bail!("server returned {} creating the review", response.status());
        }

        Ok(response.json::<CreatedEnvelope>()?.data.id)
    }

    pub fn stream_events(&self, review_id: u64, last_event_id: u64) -> anyhow::Result<reqwest::blocking::Response> {
        let response = self
            .http
            .get(format!("{}/reviews/{review_id}/events", self.base))
            .header("Accept", "text/event-stream")
            .header("Last-Event-ID", last_event_id.to_string())
            .send()
            .context("GET /reviews/{id}/events failed")?;

        if !response.status().is_success() {
            bail!("server returned {} streaming events", response.status());
        }

        Ok(response)
    }
}
```

(If the server's `StoreReviewRequest` doesn't yet accept a `mode` field, that's a one-line server change tracked in the server plan Task 6 extension of `ReviewApiTest` — the CLI sends it from day one.)

- [ ] **Step 4: Run to verify pass** — `cargo test` → all passing; clippy clean.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Add oast API client and finding types"`

---

### Task 4: Rendering (`src/render.rs`)

**Files:**
- Create: `src/render.rs`; Modify: `src/main.rs` (`mod render;`)

**Interfaces:**
- Produces: `pub fn event_line(event: &str, data: &serde_json::Value) -> Option<String>` — human line per event (None = don't print); `pub fn findings_table(findings: &[api::Finding]) -> String`; `pub fn exit_code(findings: &[api::Finding]) -> i32` (1 if any `severity == "blocker"`, else 0).

- [ ] **Step 1: Write failing tests**

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use crate::api::Finding;
    use serde_json::json;

    fn finding(severity: &str) -> Finding {
        Finding {
            severity: severity.into(),
            confidence: "consensus".into(),
            title: "Order exposes DB join table".into(),
            location: "#/paths/~1orders".into(),
        }
    }

    #[test]
    fn renders_panel_done_with_timing_and_cost() {
        let line = event_line(
            "panel.model.done",
            &json!({"model": "openai/gpt-5.5", "ms": 46007, "cost_usd": 0.031}),
        )
        .unwrap();
        assert!(line.contains("openai/gpt-5.5"));
        assert!(line.contains("46.0s"));
        assert!(line.contains("$0.031"));
    }

    #[test]
    fn table_lists_each_finding_with_severity() {
        let table = findings_table(&[finding("blocker"), finding("consider")]);
        assert!(table.contains("BLOCKER"));
        assert!(table.contains("consider"));
        assert!(table.contains("#/paths/~1orders"));
    }

    #[test]
    fn blockers_drive_the_exit_code() {
        assert_eq!(exit_code(&[finding("should-fix")]), 0);
        assert_eq!(exit_code(&[finding("blocker")]), 1);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `cargo test` → compile error.

- [ ] **Step 3: Implement**

```rust
// src/render.rs
use crate::api::Finding;
use serde_json::Value;

pub fn event_line(event: &str, data: &Value) -> Option<String> {
    let model = data.get("model").and_then(Value::as_str).unwrap_or("?");
    let secs = data.get("ms").and_then(Value::as_u64).map(|ms| ms as f64 / 1000.0);
    let cost = data.get("cost_usd").and_then(Value::as_f64);

    let line = match event {
        "review.queued" => format!("▸ review queued ({})", data.get("dimension").and_then(Value::as_str).unwrap_or("?")),
        "panel.model.start" => format!("… panel  {model}"),
        "panel.model.done" => format!(
            "✓ panel  {model}  {:.1}s{}",
            secs.unwrap_or(0.0),
            cost.map(|c| format!("  ${c:.3}")).unwrap_or_default()
        ),
        "panel.model.failed" => format!("✗ panel  {model}  failed: {}", data.get("error").and_then(Value::as_str).unwrap_or("?")),
        "panel.model.late" => format!("◷ panel  {model}  finished late (excluded)"),
        "judge.start" => format!("▸ judge  {model}"),
        "judge.done" => format!("✓ judge  {model}  {:.1}s  {} findings", secs.unwrap_or(0.0), data.get("findings_count").and_then(Value::as_u64).unwrap_or(0)),
        "review.failed" => format!("✗ review failed: {}", data.get("problem").and_then(|p| p.get("title")).and_then(Value::as_str).unwrap_or("unknown")),
        _ => return None, // review.completed handled by the findings table
    };

    Some(line)
}

pub fn findings_table(findings: &[Finding]) -> String {
    let mut out = String::new();

    for f in findings {
        let severity = if f.severity == "blocker" { "BLOCKER".to_string() } else { f.severity.clone() };
        out.push_str(&format!("{severity:<10} {:<10} {:<70} {}\n", f.confidence, f.title, f.location));
    }

    out
}

pub fn exit_code(findings: &[Finding]) -> i32 {
    if findings.iter().any(|f| f.severity == "blocker") { 1 } else { 0 }
}
```

- [ ] **Step 4: Run to verify pass** — `cargo test`; clippy clean.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Add event and findings rendering"`

---

### Task 5: `main.rs` wiring + reconnect + integration test

**Files:**
- Modify: `src/main.rs`
- Create: `tests/roast.rs` (integration, httpmock)

**Interfaces:**
- Produces: binary `oast` with subcommand `roast`:
  `oast roast <spec> [--dimension domain-modeling] [--baseline] [--server URL] [--json]`.
  `--server` default: `OAST_SERVER` env, else error. Exit: 0 no blockers, 1 blockers or review.failed, 2 transport/arg errors. Reconnects the stream up to 5 times with the last seen event id.

- [ ] **Step 1: Write the failing integration test**

```rust
// tests/roast.rs
use httpmock::prelude::*;

#[test]
fn roast_happy_path_exits_zero_and_prints_findings() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST).path("/reviews");
        then.status(202).json_body(serde_json::json!({"data": {"id": 1}}));
    });
    server.mock(|when, then| {
        when.method(GET).path("/reviews/1/events");
        then.status(200).header("Content-Type", "text/event-stream").body(concat!(
            "id: 1\nevent: panel.model.done\ndata: {\"model\":\"m\",\"ms\":1000}\n\n",
            "id: 2\nevent: review.completed\ndata: {\"findings\":[{\"severity\":\"should-fix\",\"confidence\":\"majority\",\"title\":\"T\",\"location\":\"#/x\"}],\"total_cost_usd\":0.2}\n\n",
        ));
    });

    let spec = std::env::temp_dir().join("roast-spec.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args(["roast", spec.to_str().unwrap(), "--server", &server.base_url()])
        .output()
        .unwrap();

    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(output.status.success(), "stderr: {}", String::from_utf8_lossy(&output.stderr));
    assert!(stdout.contains("✓ panel  m"));
    assert!(stdout.contains("should-fix"));
    assert!(stdout.contains("$0.2"));
}

#[test]
fn roast_exits_one_on_blockers() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST).path("/reviews");
        then.status(202).json_body(serde_json::json!({"data": {"id": 2}}));
    });
    server.mock(|when, then| {
        when.method(GET).path("/reviews/2/events");
        then.status(200).body("id: 1\nevent: review.completed\ndata: {\"findings\":[{\"severity\":\"blocker\",\"confidence\":\"consensus\",\"title\":\"B\",\"location\":\"#/y\"}]}\n\n");
    });

    let spec = std::env::temp_dir().join("roast-blocker.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let status = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args(["roast", spec.to_str().unwrap(), "--server", &server.base_url()])
        .status()
        .unwrap();

    assert_eq!(status.code(), Some(1));
}
```

(Binary name: set `[[bin]] name = "oast"` in `Cargo.toml` with `path = "src/main.rs"`.)

- [ ] **Step 2: Run to verify failure** — `cargo test --test roast` → fails (no subcommand yet).

- [ ] **Step 3: Implement**

```rust
// src/main.rs
mod api;
mod render;
mod sse;

use anyhow::Context;
use clap::{Parser, Subcommand};
use std::io::BufReader;

#[derive(Parser)]
#[command(name = "oast", about = "Convene the oast.sh Council on an API spec")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Review a spec with the multi-model Council
    Roast {
        /// Path to the OpenAPI spec file
        spec: std::path::PathBuf,
        #[arg(long, default_value = "domain-modeling")]
        dimension: String,
        #[arg(long)]
        baseline: bool,
        /// Server base URL (defaults to $OAST_SERVER)
        #[arg(long, env = "OAST_SERVER")]
        server: String,
        /// Print raw findings JSON instead of the table
        #[arg(long)]
        json: bool,
    },
}

fn main() {
    let cli = Cli::parse();

    let code = match cli.command {
        Command::Roast { spec, dimension, baseline, server, json } => {
            match roast(&spec, &dimension, baseline, &server, json) {
                Ok(code) => code,
                Err(err) => {
                    eprintln!("error: {err:#}");
                    2
                }
            }
        }
    };

    std::process::exit(code);
}

fn roast(spec: &std::path::Path, dimension: &str, baseline: bool, server: &str, json: bool) -> anyhow::Result<i32> {
    let spec_text = std::fs::read_to_string(spec)
        .with_context(|| format!("cannot read spec file {}", spec.display()))?;

    let client = api::Client::new(server);
    let review_id = client.create_review(&spec_text, dimension, baseline)?;
    println!("▸ review {review_id} started ({})", if baseline { "baseline" } else { "council" });

    let mut last_event_id: u64 = 0;
    let mut attempts = 0;

    loop {
        let response = match client.stream_events(review_id, last_event_id) {
            Ok(response) => {
                attempts = 0;
                response
            }
            Err(err) if attempts < 5 => {
                attempts += 1;
                eprintln!("stream dropped ({err:#}); reconnecting ({attempts}/5)");
                std::thread::sleep(std::time::Duration::from_secs(2));
                continue;
            }
            Err(err) => return Err(err),
        };

        for event in sse::SseReader::new(BufReader::new(response)) {
            let event = event?;
            if let Some(id) = event.id {
                last_event_id = id;
            }

            let data: serde_json::Value = serde_json::from_str(&event.data).unwrap_or_default();

            match event.event.as_str() {
                "review.completed" => {
                    let completed: api::Completed = serde_json::from_str(&event.data)?;

                    if json {
                        println!("{}", event.data);
                    } else {
                        print!("\n{}", render::findings_table(&completed.findings));
                        if let Some(cost) = completed.total_cost_usd {
                            println!("\ntotal cost: ${cost}");
                        }
                    }

                    return Ok(render::exit_code(&completed.findings));
                }
                "review.failed" => {
                    if let Some(line) = render::event_line(&event.event, &data) {
                        eprintln!("{line}");
                    }
                    return Ok(1);
                }
                _ => {
                    if let Some(line) = render::event_line(&event.event, &data) {
                        println!("{line}");
                    }
                }
            }
        }

        // Stream ended without a terminal event: reconnect from last id.
        attempts += 1;
        if attempts >= 5 {
            anyhow::bail!("stream ended {attempts} times without a terminal event");
        }
    }
}
```

- [ ] **Step 4: Run to verify pass** — `cargo test` (unit + integration) → all green; `cargo clippy -- -D warnings`; `cargo fmt --check`.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Wire oast roast end-to-end with SSE reconnect"`

---

### Task 6: Live smoke against the real server

- [ ] **Step 1:** In oast-server: `composer dev` (worker running, OpenRouter key set).
- [ ] **Step 2:** `OAST_SERVER=https://api.oast.test cargo run -- roast ../../PHP/oast-server/fixtures/specs/train-travel.yaml` — confirm: 202 accepted, panel events interleave (concurrency visible), findings table renders, exit code matches blocker presence.
- [ ] **Step 3:** Kill the CLI mid-stream, rerun — confirm reconnect picks up from `Last-Event-ID` (server replays, no duplicate lines before the resume point).
- [ ] **Step 4: Commit README updates** if the smoke changed usage docs.

---

## Self-review notes (already applied)

- The CLI sends `mode` on POST; the server's `StoreReviewRequest` must accept it — flagged in server plan Task 6.
- `reqwest` blocking with `timeout(None)` is required — the default 30s idle timeout would kill quiet streams between panel events.
- `httpmock` over `wiremock` because the client is blocking; no tokio runtime needed anywhere.
