# Codex Global Memory Design

## Goal

Provide one globally installed Codex skill that preserves safe, durable engineering context across repositories while keeping project-specific facts isolated.

## Scope

The skill is installed at `~/.codex/skills/codex-global-memory` and uses local files only:

```text
~/.codex/memory/
  global.md
  projects/
    <project-slug>.md
  archive/
```

`global.md` contains cross-project preferences and reusable environment facts. Each project file contains only facts for one repository. The current repository is identified from its workspace root; `C:\laragon\www\agnishopbjm-laravel` maps to `agnishopbjm-laravel.md`.

## Lifecycle

At the start of substantive work, the skill reads `global.md` and the active project file when present. It uses the smallest relevant context and does not load unrelated project files.

At the end of substantive work, before claiming completion, the skill records only verified and durable facts. It does not write memory for normal conversation, unconfirmed hypotheses, transient command output, or duplicate facts.

When a fact changes, the skill updates the existing entry or marks it obsolete instead of appending a conflicting record.

## Data Format

Memory files are Markdown with short category sections:

```markdown
## Deployment
- Updated: 2026-08-01
- Fact: Build the Vue frontend with `npm run build` in `frontend`, then publish the referenced `index.html` and hashed assets to `backend/public`.
- Verified by: successful build and HTTP response from the deployed asset.
```

Project files use categories such as Architecture, Development, Deployment, Integrations, Networking, Testing, and Known Constraints. Entries must identify the source of truth when one exists.

## Safety Rules

The skill must never store secrets or sensitive operational data, including:

- Passwords, API keys, access tokens, cookies, session IDs, or `.env` contents.
- Customer data, order data, payment data, or personal identifiers.
- Unverified network addresses, credentials, or temporary machine state.

It must not alter repository code, deployment configuration, or memory outside the local Codex memory directory while merely updating memory.

## AgniShop Seed Context

The first project memory file will record verified non-sensitive facts:

- Laravel backend is in `backend`; Vue 3 and Vite frontend is in `frontend`.
- The local virtual host serves the SPA from `backend/public`.
- Production frontend changes require `npm run build` in `frontend`, followed by publishing the generated `index.html` and hashed assets to `backend/public`.
- The mobile product management route is `/mobile/kelola-produk`.
- Local LAN access uses router-managed DNS for `agnishopbjm-laravel.test`; IP values are treated as volatile and must be revalidated before writing.

## Failure Handling

If a memory file is absent, the skill treats it as empty and creates it only when there is a verified fact worth recording. If a memory file cannot be read or written, work continues and the final response reports that memory was not updated.

When repository documentation conflicts with memory, repository code and current verified behavior take priority. The skill corrects memory only after verification.

## Validation

Before deployment, verify that:

1. The skill has valid YAML frontmatter and clear trigger conditions.
2. The skill reads the global and active project memory before substantive work.
3. A realistic AgniShop deployment fact is written once and is not duplicated on a second run.
4. A secret-like value is rejected and not written.
5. The skill does not modify any repository file while recording memory.

## Non-Goals

- Semantic search, vector databases, external services, or cloud synchronization.
- Automatic capture of every conversation or command.
- Replacing project documentation, Git history, tests, or deployment guides.
