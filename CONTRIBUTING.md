# Contributing to oast

Thanks for wanting to help. Two things to know before your first PR: how the
project is licensed, and how to get a green build.

## Licensing & the CLA

The oast server is licensed under **AGPL-3.0-only**. The hosted version at
oast.sh — which funds development of the open project — layers private code
(billing, tenancy) on top of this repository.

To keep that model legal while keeping this repository genuinely open, all
contributions require signing the [Contributor License Agreement](CLA.md):
you **keep the copyright** to your work and grant the maintainer a license to
relicense it (this is what allows the hosted version to exist). A bot will
prompt you to sign — one comment, once, on your first pull request. No
paperwork, no email.

If you're contributing on behalf of your employer, have them contact the
maintainer for the corporate CLA before you open the PR.

## Development

```sh
composer setup     # one-time project setup
composer dev       # serve + queue worker + logs + vite
composer test      # the full gate: type coverage, tests, lint, static analysis
```

Ground rules the CI gate enforces:

- **Pest 4**, functional style. New code ships with tests — line and type
  coverage are gated at **100%**.
- **PHPStan level max** and Rector must pass; format with `composer lint`
  before committing.
- Commit messages: concise, imperative mood.

Run `composer test` locally before pushing; it's the same gate CI runs.

## Scope

Good first contributions: bug fixes, new review dimensions (see
`docs/judge-rubric.md` for the pattern), self-hosting ergonomics, docs.
For anything architectural, open an issue first — the design docs in `docs/`
explain where the project is headed.
