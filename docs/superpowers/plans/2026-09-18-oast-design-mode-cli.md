# Design Mode (CLI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `oast design <spec>`, a line-oriented REPL in the Rust CLI that grows a spec file on disk through `POST /design/turns`, keeps a resumable session file beside it, and runs the existing Council via `/roast`.

**Architecture:** One new `api::Client::design_turn` method, one new `design` module (session file, clobber guard, REPL loop), and a small refactor of `roast` in `main.rs` so its findings can be reused by `/roast`. Spec on disk is the state; the session file only carries intent.

**Tech Stack:** Rust 2024, clap 4 (derive), reqwest 0.13 blocking, serde/serde_json, httpmock for tests. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-16-oast-design-mode-design.md` (in the `oast-server` repo)

## Global Constraints

- Repo: `~/Documents/Projects/Rust/oast-cli`. Run `cargo fmt`, `cargo clippy -- -D warnings` and `cargo test` before each commit.
- No new crates. Stdlib for files, time and stdin.
- Session file path is `<spec path with ".oast-design.jsonl" appended>`, e.g. `api.yaml.oast-design.jsonl`. Clobber fallback is `<spec>.oast-design.new`.
- History lines are `{"role":"user"|"assistant","content":"...","at":"<RFC 3339>"}`. The CLI sends every stored line; the server truncates.
- The server endpoint (server plan Task 4) returns `200 {"spec","reply"}` and Problem Details on error with a `detail` field.

---

### Task 1: `api::Client::design_turn`

**Files:**
- Modify: `src/api.rs`

**Interfaces:**
- Produces:
  ```rust
  #[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
  pub struct Message { pub role: String, pub content: String, #[serde(default)] pub at: String }
  #[derive(Debug, Deserialize)]
  pub struct Turn { pub spec: String, pub reply: String }
  impl Client { pub fn design_turn(&self, spec: &str, message: &str, history: &[Message]) -> anyhow::Result<Turn> }
  ```
  The request body sends `history` entries as `{role, content}` only (the `at` field is skipped on serialize).

- [ ] **Step 1: Write the failing tests**

Append inside the existing `mod tests` in `src/api.rs`:

```rust
    #[test]
    fn design_turn_posts_spec_message_and_history() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(POST)
                .path("/design/turns")
                .json_body(serde_json::json!({
                    "spec": "",
                    "message": "library API",
                    "history": [{"role": "user", "content": "hi"}],
                }));
            then.status(200)
                .json_body(serde_json::json!({"spec": "openapi: 3.1.0\n", "reply": "Started."}));
        });

        let history = vec![Message { role: "user".into(), content: "hi".into(), at: "2026-09-18T00:00:00Z".into() }];
        let turn = Client::new(&server.base_url())
            .design_turn("", "library API", &history)
            .unwrap();

        mock.assert();
        assert_eq!(turn.spec, "openapi: 3.1.0\n");
        assert_eq!(turn.reply, "Started.");
    }

    #[test]
    fn design_turn_surfaces_problem_detail_on_error() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST).path("/design/turns");
            then.status(422)
                .json_body(serde_json::json!({"title": "Designer produced an invalid spec", "detail": "did not parse"}));
        });

        let err = Client::new(&server.base_url())
            .design_turn("x", "y", &[])
            .unwrap_err();
        let text = err.to_string();
        assert!(text.contains("422"), "{text}");
        assert!(text.contains("did not parse"), "{text}");
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `cargo test design_turn`
Expected: compile error, `Message`, `Turn` and `design_turn` not found.

- [ ] **Step 3: Implement**

In `src/api.rs`, change the serde import to `use serde::{Deserialize, Serialize};` and add after `Completed`:

```rust
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Message {
    pub role: String,
    pub content: String,
    #[serde(default, skip_serializing)]
    pub at: String,
}

#[derive(Debug, Deserialize)]
pub struct Turn {
    pub spec: String,
    pub reply: String,
}

#[derive(Deserialize, Default)]
struct Problem {
    #[serde(default)]
    detail: String,
}
```

Add to `impl Client`:

```rust
    pub fn design_turn(
        &self,
        spec: &str,
        message: &str,
        history: &[Message],
    ) -> anyhow::Result<Turn> {
        let response = self
            .http
            .post(format!("{}/design/turns", self.base))
            .json(&serde_json::json!({
                "spec": spec,
                "message": message,
                "history": history,
            }))
            .send()
            .context("POST /design/turns failed")?;

        let status = response.status();
        if status.as_u16() != 200 {
            let problem = response.json::<Problem>().unwrap_or_default();
            bail!("server returned {status} for the design turn: {}", problem.detail);
        }

        Ok(response.json::<Turn>()?)
    }
```

- [ ] **Step 4: Run the tests**

Run: `cargo test`
Expected: PASS, all existing tests plus the two new ones.

- [ ] **Step 5: Commit**

```bash
cargo fmt && cargo clippy -- -D warnings
git add src/api.rs
git commit -m "feat: Add design_turn client method"
```

---

### Task 2: Session file and clobber guard

**Files:**
- Create: `src/design.rs`
- Modify: `src/main.rs` (add `mod design;`)

**Interfaces:**
- Consumes: `api::Message` (Task 1).
- Produces:
  ```rust
  pub fn session_path(spec: &Path) -> PathBuf
  pub fn read_session(path: &Path) -> anyhow::Result<Vec<api::Message>>
  pub fn append_session(path: &Path, messages: &[api::Message]) -> anyhow::Result<()>
  pub fn should_write(before: SystemTime, now: SystemTime) -> bool
  pub fn write_spec(spec: &Path, mtime_before: SystemTime, content: &str) -> anyhow::Result<Option<PathBuf>>
  pub fn now_rfc3339() -> String
  ```
  `write_spec` returns `Ok(None)` when it overwrote the spec, `Ok(Some(fallback))` when it wrote to `<spec>.oast-design.new` instead.

- [ ] **Step 1: Write the failing tests**

`src/design.rs`:

```rust
use crate::api::Message;
use anyhow::Context;
use std::io::Write;
use std::path::{Path, PathBuf};
use std::time::SystemTime;

#[cfg(test)]
mod tests {
    use super::*;

    fn tmp(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("oast-design-{}-{name}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        dir.join("api.yaml")
    }

    fn msg(role: &str, content: &str) -> Message {
        Message { role: role.into(), content: content.into(), at: "2026-09-18T00:00:00Z".into() }
    }

    #[test]
    fn session_path_sits_beside_the_spec() {
        assert_eq!(
            session_path(Path::new("/x/api.yaml")),
            PathBuf::from("/x/api.yaml.oast-design.jsonl")
        );
    }

    #[test]
    fn missing_session_reads_as_empty() {
        let path = tmp("missing").with_extension("jsonl");
        assert!(read_session(&path).unwrap().is_empty());
    }

    #[test]
    fn session_round_trips() {
        let path = session_path(&tmp("roundtrip"));
        let _ = std::fs::remove_file(&path);
        append_session(&path, &[msg("user", "hi"), msg("assistant", "hello")]).unwrap();
        append_session(&path, &[msg("user", "more")]).unwrap();

        let got = read_session(&path).unwrap();
        assert_eq!(got, vec![msg("user", "hi"), msg("assistant", "hello"), msg("user", "more")]);
    }

    #[test]
    fn should_write_only_when_mtime_unchanged() {
        let t = SystemTime::UNIX_EPOCH;
        assert!(should_write(t, t));
        assert!(!should_write(t, t + std::time::Duration::from_secs(1)));
    }

    #[test]
    fn write_spec_overwrites_when_untouched() {
        let spec = tmp("untouched");
        std::fs::write(&spec, "old").unwrap();
        let before = std::fs::metadata(&spec).unwrap().modified().unwrap();

        let fallback = write_spec(&spec, before, "new").unwrap();

        assert!(fallback.is_none());
        assert_eq!(std::fs::read_to_string(&spec).unwrap(), "new");
    }

    #[test]
    fn write_spec_falls_back_when_edited_in_flight() {
        let spec = tmp("edited");
        std::fs::write(&spec, "old").unwrap();
        let before = std::fs::metadata(&spec).unwrap().modified().unwrap();
        // Simulate a hand edit while the request was in flight.
        std::fs::write(&spec, "hand edit").unwrap();
        let later = before + std::time::Duration::from_secs(5);
        std::fs::File::options().write(true).open(&spec).unwrap().set_modified(later).unwrap();

        let fallback = write_spec(&spec, before, "new").unwrap().expect("fallback path");

        assert_eq!(fallback, PathBuf::from(format!("{}.oast-design.new", spec.display())));
        assert_eq!(std::fs::read_to_string(&spec).unwrap(), "hand edit");
        assert_eq!(std::fs::read_to_string(&fallback).unwrap(), "new");
    }

    #[test]
    fn now_rfc3339_looks_like_a_timestamp() {
        let s = now_rfc3339();
        assert_eq!(s.len(), 20, "{s}");
        assert!(s.ends_with('Z'));
        assert_eq!(&s[4..5], "-");
        assert_eq!(&s[10..11], "T");
    }
}
```

Add `mod design;` to the top of `src/main.rs` after `mod api;`.

- [ ] **Step 2: Run to verify failure**

Run: `cargo test design::`
Expected: compile errors, functions not found.

- [ ] **Step 3: Implement**

Insert between the `use` lines and `#[cfg(test)]` in `src/design.rs`:

```rust
pub fn session_path(spec: &Path) -> PathBuf {
    PathBuf::from(format!("{}.oast-design.jsonl", spec.display()))
}

pub fn read_session(path: &Path) -> anyhow::Result<Vec<Message>> {
    let text = match std::fs::read_to_string(path) {
        Ok(text) => text,
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => return Ok(Vec::new()),
        Err(err) => return Err(err).with_context(|| format!("cannot read {}", path.display())),
    };

    text.lines()
        .filter(|line| !line.trim().is_empty())
        .map(|line| serde_json::from_str(line).with_context(|| format!("bad session line in {}", path.display())))
        .collect()
}

pub fn append_session(path: &Path, messages: &[Message]) -> anyhow::Result<()> {
    let mut file = std::fs::File::options()
        .create(true)
        .append(true)
        .open(path)
        .with_context(|| format!("cannot open {}", path.display()))?;

    for message in messages {
        serde_json::to_writer(&mut file, &SessionLine(message))?;
        file.write_all(b"\n")?;
    }

    Ok(())
}

/// `Message` skips `at` on serialize for the wire; the session file keeps it.
struct SessionLine<'a>(&'a Message);

impl serde::Serialize for SessionLine<'_> {
    fn serialize<S: serde::Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        use serde::ser::SerializeStruct;
        let mut st = s.serialize_struct("Message", 3)?;
        st.serialize_field("role", &self.0.role)?;
        st.serialize_field("content", &self.0.content)?;
        st.serialize_field("at", &self.0.at)?;
        st.end()
    }
}

pub fn should_write(before: SystemTime, now: SystemTime) -> bool {
    before == now
}

/// Overwrite `spec` with `content` unless it was modified since `mtime_before`;
/// in that case write to `<spec>.oast-design.new` and return that path.
pub fn write_spec(spec: &Path, mtime_before: SystemTime, content: &str) -> anyhow::Result<Option<PathBuf>> {
    let now = std::fs::metadata(spec)
        .and_then(|m| m.modified())
        .with_context(|| format!("cannot stat {}", spec.display()))?;

    if should_write(mtime_before, now) {
        std::fs::write(spec, content).with_context(|| format!("cannot write {}", spec.display()))?;
        return Ok(None);
    }

    let fallback = PathBuf::from(format!("{}.oast-design.new", spec.display()));
    std::fs::write(&fallback, content).with_context(|| format!("cannot write {}", fallback.display()))?;
    Ok(Some(fallback))
}

/// UTC timestamp, seconds precision, no chrono. Civil-from-days per Howard Hinnant.
pub fn now_rfc3339() -> String {
    let secs = SystemTime::now()
        .duration_since(SystemTime::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0);
    let days = secs.div_euclid(86_400);
    let rem = secs.rem_euclid(86_400);
    let (h, m, s) = (rem / 3600, (rem % 3600) / 60, rem % 60);

    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let mo = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = if mo <= 2 { y + 1 } else { y };

    format!("{y:04}-{mo:02}-{d:02}T{h:02}:{m:02}:{s:02}Z")
}
```

- [ ] **Step 4: Run the tests**

Run: `cargo test design::`
Expected: PASS (7 tests). `File::set_modified` is stable since Rust 1.75; if the toolchain is older, `rustup update`.

- [ ] **Step 5: Commit**

```bash
cargo fmt && cargo clippy -- -D warnings
git add src/design.rs src/main.rs
git commit -m "feat: Add design session file and clobber guard"
```

---

### Task 3: Refactor `roast` to expose findings

**Files:**
- Modify: `src/main.rs`

**Interfaces:**
- Produces: `fn run_review(spec_text: &str, dimension: &str, baseline: bool, server: &str, json: bool) -> anyhow::Result<Option<api::Completed>>`. Prints progress and the findings table exactly as today. Returns `None` when the review ended in `review.failed`.
- `roast(...)` keeps its signature and behaviour: reads the file, calls `run_review`, maps `None` to exit code 1 and `Some(c)` to `render::exit_code(&c.findings)`.

- [ ] **Step 1: Confirm the integration test passes before touching anything**

Run: `cargo test --test roast`
Expected: PASS. This test is the safety net for the refactor.

- [ ] **Step 2: Refactor**

In `src/main.rs`, replace the body of `roast` and split the loop out:

```rust
fn roast(
    spec: &std::path::Path,
    dimension: &str,
    baseline: bool,
    server: &str,
    json: bool,
) -> anyhow::Result<i32> {
    let spec_text = std::fs::read_to_string(spec)
        .with_context(|| format!("cannot read spec file {}", spec.display()))?;

    Ok(match run_review(&spec_text, dimension, baseline, server, json)? {
        Some(completed) => render::exit_code(&completed.findings),
        None => 1,
    })
}

/// Create a review, stream it to the terminal, and return the completed payload
/// (or `None` if the review failed). Shared by `roast` and design mode's `/roast`.
fn run_review(
    spec_text: &str,
    dimension: &str,
    baseline: bool,
    server: &str,
    json: bool,
) -> anyhow::Result<Option<api::Completed>> {
    let client = api::Client::new(server);
    let review_id = client.create_review(spec_text, dimension, baseline)?;
    println!(
        "▸ review {review_id} started ({})",
        if baseline { "baseline" } else { "council" }
    );

    // ... existing loop body unchanged, except the two `return` sites:
    //   "review.completed" arm:  `return Ok(Some(completed));`   (after printing as today)
    //   "review.failed" arm:     `return Ok(None);`
}
```

Move the existing `let mut last_event_id ... loop { ... }` block into `run_review` verbatim, then change only the two return statements as noted in the comments. Delete the comments once done.

- [ ] **Step 3: Run the tests**

Run: `cargo test`
Expected: PASS, including `tests/roast.rs`, with identical stdout assertions.

- [ ] **Step 4: Commit**

```bash
cargo fmt && cargo clippy -- -D warnings
git add src/main.rs
git commit -m "refactor: Split run_review out of roast for reuse"
```

---

### Task 4: `oast design` REPL

**Files:**
- Modify: `src/main.rs` (new `Command::Design` variant and dispatch)
- Modify: `src/design.rs` (add `run` and `findings_summary`)
- Create: `tests/design.rs`

**Interfaces:**
- Consumes: `api::Client::design_turn` (Task 1), `design::{session_path, read_session, append_session, write_spec, now_rfc3339}` (Task 2), `run_review` (Task 3), `api::Finding`, `api::Completed`.
- Produces: `design::run(spec: &Path, server: &str, input: impl BufRead, out: &mut impl Write, review: &dyn Fn(&str) -> anyhow::Result<Option<api::Completed>>) -> anyhow::Result<()>`, and `design::findings_summary(findings: &[api::Finding]) -> String`.

`run` takes the reader, writer and a review closure so the loop is testable without stdin and without the SSE stack; `main` passes `stdin().lock()`, `stdout()` and a closure over `run_review`.

- [ ] **Step 1: Write the failing unit tests**

Append to the `tests` module in `src/design.rs`:

```rust
    use crate::api::{Completed, Finding};
    use std::io::Cursor;

    fn finding(sev: &str, title: &str) -> Finding {
        Finding { severity: sev.into(), confidence: "consensus".into(), title: title.into(), location: "#/paths".into() }
    }

    #[test]
    fn findings_summary_lists_one_line_per_finding() {
        let s = findings_summary(&[finding("blocker", "Join table exposed"), finding("consider", "Rename")]);
        assert_eq!(
            s,
            "Council review findings:\n- [blocker] Join table exposed (#/paths)\n- [consider] Rename (#/paths)"
        );
    }

    #[test]
    fn findings_summary_for_no_findings() {
        assert_eq!(findings_summary(&[]), "Council review findings: none.");
    }

    #[test]
    fn slash_roast_appends_findings_to_session_without_calling_the_server() {
        let spec = tmp("slashroast");
        std::fs::write(&spec, "openapi: 3.1.0\n").unwrap();
        let session = session_path(&spec);
        let _ = std::fs::remove_file(&session);
        let mut out = Vec::new();

        let review = |_: &str| -> anyhow::Result<Option<Completed>> {
            Ok(Some(Completed { findings: vec![finding("blocker", "X")], total_cost_usd: None }))
        };
        run(&spec, "http://127.0.0.1:9", Cursor::new("/roast\n/quit\n"), &mut out, &review).unwrap();

        let got = read_session(&session).unwrap();
        assert_eq!(got.len(), 1);
        assert_eq!(got[0].role, "assistant");
        assert!(got[0].content.contains("[blocker] X"));
    }

    #[test]
    fn empty_lines_are_ignored_and_eof_exits_cleanly() {
        let spec = tmp("eof");
        std::fs::write(&spec, "").unwrap();
        let mut out = Vec::new();
        let review = |_: &str| -> anyhow::Result<Option<Completed>> { Ok(None) };

        run(&spec, "http://127.0.0.1:9", Cursor::new("\n\n"), &mut out, &review).unwrap();

        assert!(read_session(&session_path(&spec)).unwrap().is_empty());
    }
```

- [ ] **Step 2: Write the failing integration test**

`tests/design.rs`:

```rust
use httpmock::prelude::*;

#[test]
fn design_turn_writes_spec_and_session_then_quits() {
    let server = MockServer::start();
    let mock = server.mock(|when, then| {
        when.method(POST).path("/design/turns");
        then.status(200).json_body(serde_json::json!({
            "spec": "openapi: 3.1.0\ninfo:\n  title: Library\n",
            "reply": "Started the Library spec."
        }));
    });

    let dir = std::env::temp_dir().join(format!("oast-design-it-{}", std::process::id()));
    std::fs::create_dir_all(&dir).unwrap();
    let spec = dir.join("api.yaml");
    let _ = std::fs::remove_file(&spec);
    let _ = std::fs::remove_file(dir.join("api.yaml.oast-design.jsonl"));

    let mut child = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args(["design", spec.to_str().unwrap(), "--server", &server.base_url()])
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .spawn()
        .unwrap();
    {
        use std::io::Write;
        let stdin = child.stdin.as_mut().unwrap();
        stdin.write_all(b"Library lending API\n/quit\n").unwrap();
    }
    let output = child.wait_with_output().unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);

    assert!(output.status.success(), "stderr: {}", String::from_utf8_lossy(&output.stderr));
    mock.assert();
    assert!(stdout.contains("Started the Library spec."), "{stdout}");
    assert_eq!(std::fs::read_to_string(&spec).unwrap(), "openapi: 3.1.0\ninfo:\n  title: Library\n");

    let session = std::fs::read_to_string(dir.join("api.yaml.oast-design.jsonl")).unwrap();
    let lines: Vec<&str> = session.lines().collect();
    assert_eq!(lines.len(), 2);
    assert!(lines[0].contains("\"role\":\"user\""));
    assert!(lines[0].contains("Library lending API"));
    assert!(lines[1].contains("\"role\":\"assistant\""));
}

#[test]
fn design_reports_server_errors_and_keeps_going() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST).path("/design/turns");
        then.status(422).json_body(serde_json::json!({"detail": "did not parse"}));
    });

    let dir = std::env::temp_dir().join(format!("oast-design-err-{}", std::process::id()));
    std::fs::create_dir_all(&dir).unwrap();
    let spec = dir.join("api.yaml");
    std::fs::write(&spec, "openapi: 3.1.0\n").unwrap();
    let _ = std::fs::remove_file(dir.join("api.yaml.oast-design.jsonl"));

    let mut child = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args(["design", spec.to_str().unwrap(), "--server", &server.base_url()])
        .stdin(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .spawn()
        .unwrap();
    {
        use std::io::Write;
        child.stdin.as_mut().unwrap().write_all(b"add books\n/quit\n").unwrap();
    }
    let output = child.wait_with_output().unwrap();

    assert!(output.status.success());
    assert!(String::from_utf8_lossy(&output.stderr).contains("did not parse"));
    assert_eq!(std::fs::read_to_string(&spec).unwrap(), "openapi: 3.1.0\n");
    assert!(!dir.join("api.yaml.oast-design.jsonl").exists());
}
```

- [ ] **Step 3: Run to verify failure**

Run: `cargo test design`
Expected: compile errors for `run` and `findings_summary`; the integration tests fail on the unknown `design` subcommand.

- [ ] **Step 4: Implement `findings_summary` and `run`**

Add to `src/design.rs` above the `tests` module:

```rust
use crate::api::{Client, Completed, Finding, Message};
use std::io::BufRead;

pub fn findings_summary(findings: &[Finding]) -> String {
    if findings.is_empty() {
        return "Council review findings: none.".to_string();
    }
    let lines: Vec<String> = findings
        .iter()
        .map(|f| format!("- [{}] {} ({})", f.severity, f.title, f.location))
        .collect();
    format!("Council review findings:\n{}", lines.join("\n"))
}

fn message(role: &str, content: &str) -> Message {
    Message { role: role.into(), content: content.into(), at: now_rfc3339() }
}

pub fn run(
    spec: &Path,
    server: &str,
    input: impl BufRead,
    out: &mut impl Write,
    review: &dyn Fn(&str) -> anyhow::Result<Option<Completed>>,
) -> anyhow::Result<()> {
    if !spec.exists() {
        std::fs::write(spec, "").with_context(|| format!("cannot create {}", spec.display()))?;
    }
    let session = session_path(spec);
    let client = Client::new(server);

    write!(out, "› ")?;
    out.flush()?;
    for line in input.lines() {
        let line = line?;
        let line = line.trim();

        match line {
            "" => {}
            "/quit" => break,
            "/roast" => {
                let spec_text = std::fs::read_to_string(spec)?;
                match review(&spec_text) {
                    Ok(Some(completed)) => {
                        append_session(&session, &[message("assistant", &findings_summary(&completed.findings))])?;
                    }
                    Ok(None) => eprintln!("review failed; nothing added to the session"),
                    Err(err) => eprintln!("error: {err:#}"),
                }
            }
            prose => {
                let spec_text = std::fs::read_to_string(spec)?;
                let mtime = std::fs::metadata(spec)?.modified()?;
                let history = read_session(&session)?;

                match client.design_turn(&spec_text, prose, &history) {
                    Ok(turn) => {
                        if let Some(fallback) = write_spec(spec, mtime, &turn.spec)? {
                            eprintln!(
                                "warning: {} changed while drafting; wrote the draft to {} instead",
                                spec.display(),
                                fallback.display()
                            );
                        }
                        append_session(&session, &[message("user", prose), message("assistant", &turn.reply)])?;
                        writeln!(out, "\n{}\n", turn.reply)?;
                    }
                    Err(err) => eprintln!("error: {err:#}"),
                }
            }
        }

        write!(out, "› ")?;
        out.flush()?;
    }

    Ok(())
}
```

Merge the two `use crate::api::...` lines at the top of the file into one.

- [ ] **Step 5: Wire the subcommand**

In `src/main.rs`, add the variant:

```rust
    /// Co-author a spec from scratch in a conversational loop
    Design {
        /// Path to the OpenAPI spec file (created if missing)
        spec: std::path::PathBuf,
        /// Server base URL (defaults to $OAST_SERVER)
        #[arg(long, env = "OAST_SERVER")]
        server: String,
    },
```

and the dispatch arm in `main`:

```rust
        Command::Design { spec, server } => {
            let review = |spec_text: &str| run_review(spec_text, "domain-modeling", false, &server, false);
            match design::run(&spec, &server, std::io::stdin().lock(), &mut std::io::stdout(), &review) {
                Ok(()) => 0,
                Err(err) => {
                    eprintln!("error: {err:#}");
                    2
                }
            }
        }
```

- [ ] **Step 6: Run everything**

Run: `cargo test`
Expected: PASS: unit tests in `api`, `design`, `render`, plus `tests/roast.rs` and `tests/design.rs`.

- [ ] **Step 7: Commit**

```bash
cargo fmt && cargo clippy -- -D warnings
git add src/main.rs src/design.rs tests/design.rs
git commit -m "feat: Add oast design conversational spec loop"
```

---

### Task 5: README line

**Files:**
- Modify: `README.md` (if present; otherwise skip this task)

- [ ] **Step 1: Document the command**

Under the usage section, after `oast roast`:

```markdown
oast design api.yaml     # conversational co-author; `/roast` reviews, `/quit` exits
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: Mention oast design"
```

---

## Manual acceptance (the dogfood run)

Server running with a real OpenRouter key, then:

```bash
OAST_SERVER=https://api.oast.test oast design ~/tmp/library.yaml
› Library lending API. Books, members, loans.
› Members can have at most 5 open loans. Loans are immutable once returned.
› Add search on books by title and author.
› /roast
› Fix the blockers.
› /quit
```

Expected: five prose replies of three to six lines, a findings table after `/roast`, the "Fix the blockers" turn naming which findings it addressed, and `library.yaml` parseable throughout. Re-running `oast design ~/tmp/library.yaml` continues with the prior context.
