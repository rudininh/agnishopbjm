# Codex Global Memory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install a global Codex skill that reads safe local memory at the start of substantive work and records verified, durable global or project facts at task completion.

**Architecture:** The globally installed skill lives under `~/.codex/skills/codex-global-memory`. A dependency-free Node helper owns Markdown memory file creation, validation, deduplication, and reading at `~/.codex/memory`; the skill invokes the helper instead of editing memory ad hoc. Project memory files are keyed by a validated slug, keeping AgniShop facts separate from other repositories.

**Tech Stack:** Codex skills, Markdown, Node.js 22 standard library, PowerShell, Node test runner.

## Global Constraints

- Store memory only in `~/.codex/memory`, never in a repository during normal memory updates.
- Never record passwords, API keys, tokens, cookies, session IDs, connection strings, `.env` contents, customer data, payment data, or personal identifiers.
- Record only verified, durable facts; skip conversational, speculative, temporary, or duplicate information.
- Prefer updating an existing fact over appending a conflicting fact.
- Project memory uses a slug matching `^[a-z0-9][a-z0-9-]{0,79}$`.
- Keep the skill dependency-free and compatible with Node.js 22.

---

## File Structure

- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\SKILL.md`
  - Global operating instructions and trigger conditions for reading and recording memory.
- Create: `C:\Users\JACKDAW\.codex\AGENTS.md`
  - Global trigger that requires the memory workflow for substantive Codex tasks.
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\agents\openai.yaml`
  - Display metadata for the Codex skill picker.
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.mjs`
  - Dependency-free CLI for reading and recording safe Markdown memory.
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`
  - Node tests for valid records, duplicate suppression, secret rejection, and project isolation.
- Create: `C:\Users\JACKDAW\.codex\memory\global.md`
  - Cross-project memory template.
- Create: `C:\Users\JACKDAW\.codex\memory\projects\agnishopbjm-laravel.md`
  - Seeded, verified AgniShop project memory.

## CLI Interface

`memory-store.mjs` accepts these commands:

```text
node memory-store.mjs read --scope global
node memory-store.mjs read --scope project --project agnishopbjm-laravel
node memory-store.mjs record --scope global --category Preferences --fact "Use concise Indonesian for operational updates." --verified-by "User request" --date 2026-08-01
node memory-store.mjs record --scope project --project agnishopbjm-laravel --category Deployment --fact "Build the Vue frontend in frontend, then publish index.html and referenced hashed assets to backend/public." --verified-by "Successful production build and deployed HTTP verification" --date 2026-08-01
node memory-store.mjs replace --scope project --project agnishopbjm-laravel --old-fact "Old deployment location." --category Deployment --fact "Build the Vue frontend in frontend, then publish index.html and referenced hashed assets to backend/public." --verified-by "Successful production build and deployed HTTP verification" --date 2026-08-01
```

`read` returns the relevant Markdown file, or a deterministic empty-memory message when the file does not exist. `record` validates inputs, creates a file only after a valid fact is supplied, and returns `recorded`, `duplicate`, or a non-zero error. `replace` validates both facts, replaces one exact existing fact in place, and returns `replaced` or a non-zero error when the old fact is absent.

### Task 1: Create the failing CLI test suite

**Files:**
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`
- Test: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`

**Interfaces:**
- Consumes: Node.js built-ins `node:test`, `node:assert/strict`, `node:child_process`, `node:fs`, and `node:path`.
- Produces: executable tests that call `memory-store.mjs` with `CODEX_MEMORY_ROOT` pointed at a temporary directory.

- [ ] **Step 1: Write the failing test file**

```javascript
import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

const script = new URL('./memory-store.mjs', import.meta.url)

function run(args, memoryRoot) {
  return spawnSync(process.execPath, [script.pathname, ...args], {
    encoding: 'utf8',
    env: { ...process.env, CODEX_MEMORY_ROOT: memoryRoot },
  })
}

test('records a project fact once and returns duplicate on the second record', () => {
  const root = mkdtempSync(join(tmpdir(), 'codex-memory-'))
  const args = ['record', '--scope', 'project', '--project', 'agnishopbjm-laravel', '--category', 'Deployment', '--fact', 'Publish the Vite build to backend/public.', '--verified-by', 'HTTP verification', '--date', '2026-08-01']
  assert.equal(run(args, root).status, 0)
  assert.match(run(args, root).stdout, /duplicate/)
  const content = readFileSync(join(root, 'projects', 'agnishopbjm-laravel.md'), 'utf8')
  assert.equal((content.match(/Publish the Vite build/g) ?? []).length, 1)
})

test('rejects a secret-like record without creating a memory file', () => {
  const root = mkdtempSync(join(tmpdir(), 'codex-memory-'))
  const result = run(['record', '--scope', 'global', '--category', 'Credentials', '--fact', 'API_KEY=secret-value', '--verified-by', 'manual', '--date', '2026-08-01'], root)
  assert.notEqual(result.status, 0)
  assert.match(result.stderr, /sensitive/i)
})

test('reads only the requested project memory', () => {
  const root = mkdtempSync(join(tmpdir(), 'codex-memory-'))
  run(['record', '--scope', 'project', '--project', 'agnishopbjm-laravel', '--category', 'Architecture', '--fact', 'Laravel backend resides in backend.', '--verified-by', 'repository inspection', '--date', '2026-08-01'], root)
  const result = run(['read', '--scope', 'project', '--project', 'agnishopbjm-laravel'], root)
  assert.equal(result.status, 0)
  assert.match(result.stdout, /Laravel backend resides in backend/)
})
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```powershell
node --test C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: FAIL because `memory-store.mjs` does not exist.

- [ ] **Step 3: Commit the test-only change if the skill directory is versioned**

```powershell
git -C C:\Users\JACKDAW\.codex\skills\codex-global-memory status --short
```

Expected: Skip the commit when the global skill directory is not a Git repository; the versioned design and implementation plan remain in the AgniShop repository.

### Task 2: Implement the safe memory-store CLI

**Files:**
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.mjs`
- Modify: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`
- Test: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`

**Interfaces:**
- Consumes: the CLI interface defined above and optional `CODEX_MEMORY_ROOT` for tests.
- Produces: `read` and `record` commands, Markdown memory files under `~/.codex/memory`, and deterministic status text.

- [ ] **Step 1: Implement strict argument parsing and destination selection**

```javascript
const MEMORY_ROOT = process.env.CODEX_MEMORY_ROOT || join(homedir(), '.codex', 'memory')
const PROJECT_SLUG = /^[a-z0-9][a-z0-9-]{0,79}$/

function memoryPath(scope, project) {
  if (scope === 'global') return join(MEMORY_ROOT, 'global.md')
  if (scope === 'project' && PROJECT_SLUG.test(project)) {
    return join(MEMORY_ROOT, 'projects', `${project}.md`)
  }
  throw new Error('Project memory requires a valid --project slug.')
}
```

- [ ] **Step 2: Add secret detection before any write**

```javascript
const SENSITIVE_PATTERNS = [
  /\b(?:api[_-]?key|password|secret|token|cookie|session(?:[_-]?id)?)\s*[:=]\s*\S+/i,
  /\bauthorization\s*:\s*bearer\s+\S+/i,
  /\bpostgres(?:ql)?:\/\/[^\s]+/i,
]

function assertSafe(value) {
  if (SENSITIVE_PATTERNS.some((pattern) => pattern.test(value))) {
    throw new Error('Sensitive data must not be stored in Codex memory.')
  }
}
```

- [ ] **Step 3: Write Markdown creation, category insertion, and exact-fact deduplication**

```javascript
function entry(category, fact, verifiedBy, date) {
  return `## ${category}\n- Updated: ${date}\n- Fact: ${fact}\n- Verified by: ${verifiedBy}\n`
}

function normalizeFact(value) {
  return value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-US')
}

function isDuplicate(content, fact) {
  return content.split('\n').some((line) => line.startsWith('- Fact: ') && normalizeFact(line.slice(8)) === normalizeFact(fact))
}
```

- [ ] **Step 4: Run the targeted test suite**

Run:

```powershell
node --test C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: PASS with all three initial tests.

- [ ] **Step 5: Add an invalid-project-slug test and verify it passes**

```javascript
test('rejects a project slug containing path separators', () => {
  const root = mkdtempSync(join(tmpdir(), 'codex-memory-'))
  const result = run(['record', '--scope', 'project', '--project', '../outside', '--category', 'Architecture', '--fact', 'Unsafe path.', '--verified-by', 'manual', '--date', '2026-08-01'], root)
  assert.notEqual(result.status, 0)
  assert.match(result.stderr, /valid --project slug/i)
})
```

Run:

```powershell
node --test C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: PASS with four tests.

- [ ] **Step 6: Write a failing replacement test**

```javascript
test('replaces an existing fact without leaving the old fact behind', () => {
  const root = mkdtempSync(join(tmpdir(), 'codex-memory-'))
  const recordArgs = ['record', '--scope', 'project', '--project', 'agnishopbjm-laravel', '--category', 'Deployment', '--fact', 'Frontend is published to old-public.', '--verified-by', 'legacy setup', '--date', '2026-08-01']
  assert.equal(run(recordArgs, root).status, 0)

  const replaceResult = run(['replace', '--scope', 'project', '--project', 'agnishopbjm-laravel', '--old-fact', 'Frontend is published to old-public.', '--category', 'Deployment', '--fact', 'Frontend is published to backend/public.', '--verified-by', 'deployed HTTP verification', '--date', '2026-08-01'], root)
  assert.equal(replaceResult.status, 0)
  assert.match(replaceResult.stdout, /replaced/)

  const content = readFileSync(join(root, 'projects', 'agnishopbjm-laravel.md'), 'utf8')
  assert.doesNotMatch(content, /old-public/)
  assert.match(content, /backend\/public/)
})
```

- [ ] **Step 7: Run the replacement test to verify it fails**

Run:

```powershell
node --test --test-name-pattern "replaces an existing fact" C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: FAIL because `replace` is not implemented.

- [ ] **Step 8: Implement and verify replace behavior**

```javascript
function replaceFact(content, oldFact, category, fact, verifiedBy, date) {
  const oldLine = `- Fact: ${oldFact}`
  const oldIndex = content.indexOf(oldLine)
  if (oldIndex === -1) throw new Error('The old fact was not found.')

  const entryStart = content.lastIndexOf('## ', oldIndex)
  const entryEnd = content.indexOf('\n## ', oldIndex)
  const replacement = formatEntry(category, fact, verifiedBy, date).trimEnd()
  return `${content.slice(0, entryStart)}${replacement}${entryEnd === -1 ? '\n' : content.slice(entryEnd)}`
}
```

Run:

```powershell
node --test C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: PASS with five tests.

### Task 3: Author and validate the global Codex skill

**Files:**
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\SKILL.md`
- Create: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\agents\openai.yaml`
- Create: `C:\Users\JACKDAW\.codex\AGENTS.md`
- Test: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\SKILL.md`

**Interfaces:**
- Consumes: `memory-store.mjs` commands from Task 2.
- Produces: a globally discoverable skill whose instructions require relevant memory reads before substantive work and safe writes before a final completion claim.

- [ ] **Step 1: Add concise frontmatter and a deterministic workflow**

```markdown
---
name: codex-global-memory
description: Use when starting or completing substantive work in any repository to read relevant local memory and save only verified, durable, non-sensitive global or project facts.
---

## Start Of Work

1. Derive the project slug from the workspace directory name using lowercase letters, digits, and hyphens.
2. Run `read --scope global` and `read --scope project --project <slug>`.
3. Use only relevant entries. Current repository files and verified behavior override memory.

## End Of Work

Before a final completion claim, decide whether verified durable knowledge was created or corrected. If yes, call `record`; otherwise do not write memory.
```

- [ ] **Step 2: Add hard safety boundaries and update rules**

```markdown
Never store a credential, `.env` value, URL containing credentials, customer data, order data, payment data, personal data, raw logs, or a hypothesis.

Record a fact only after successful verification. When a prior fact is obsolete, update the existing memory entry; do not append contradictory entries. If memory cannot be read or written, continue the requested work and disclose that memory was not updated.
```

- [ ] **Step 3: Add UI metadata**

```yaml
interface:
  display_name: Codex Global Memory
  short_description: Save verified engineering context across repositories.
  default_prompt: Read relevant global and project memory before work, then store durable verified facts when the task is complete.
```

- [ ] **Step 4: Create the global trigger file**

```markdown
# Global Codex Workflow

For every substantive task, use the `codex-global-memory` skill before taking action and before making a completion claim. Read only the relevant global and project memory. Record only verified, durable, non-sensitive facts. Do not store secrets, customer data, payment data, or `.env` values.
```

- [ ] **Step 5: Validate the skill file, metadata, and global trigger**

Run:

```powershell
$skillRoot = 'C:\Users\JACKDAW\.codex\skills\codex-global-memory'
Test-Path "$skillRoot\SKILL.md"
Test-Path "$skillRoot\agents\openai.yaml"
Test-Path 'C:\Users\JACKDAW\.codex\AGENTS.md'
Get-Content -Raw "$skillRoot\SKILL.md" | Select-String -Pattern '^name: codex-global-memory$','Use when starting or completing substantive work','Never store a credential'
Get-Content -Raw 'C:\Users\JACKDAW\.codex\AGENTS.md' | Select-String -Pattern 'codex-global-memory','Do not store secrets'
```

Expected: all paths exist and the skill and global trigger instruction patterns are found.

### Task 4: Seed and verify safe AgniShop memory

**Files:**
- Create: `C:\Users\JACKDAW\.codex\memory\global.md`
- Create: `C:\Users\JACKDAW\.codex\memory\projects\agnishopbjm-laravel.md`
- Test: `C:\Users\JACKDAW\.codex\memory\projects\agnishopbjm-laravel.md`

**Interfaces:**
- Consumes: `record --scope project` from Task 2.
- Produces: concise, verified AgniShop context with no secret or volatile IP data.

- [ ] **Step 1: Create the global memory heading without adding project facts**

```markdown
# Codex Global Memory

## Usage
- Store only verified, durable, non-sensitive engineering context.
- Project-specific facts belong in `projects/<project-slug>.md`.
```

- [ ] **Step 2: Seed verified AgniShop facts through the CLI**

Run:

```powershell
$script = 'C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.mjs'
node $script record --scope project --project agnishopbjm-laravel --category Architecture --fact 'Laravel backend is in backend; Vue 3 and Vite frontend are in frontend.' --verified-by 'Repository README and package manifests reviewed' --date 2026-08-01
node $script record --scope project --project agnishopbjm-laravel --category Deployment --fact 'Build the frontend in frontend with npm run build, then publish index.html and referenced hashed assets to backend/public.' --verified-by 'Successful production build and deployed HTTP verification' --date 2026-08-01
node $script record --scope project --project agnishopbjm-laravel --category Networking --fact 'LAN clients resolve agnishopbjm-laravel.test through router-managed local DNS; revalidate the server IP before operational use.' --verified-by 'Router DNS query and Apache virtual-host verification' --date 2026-08-01
node $script record --scope project --project agnishopbjm-laravel --category Features --fact 'Mobile product management is available at /mobile/kelola-produk.' --verified-by 'Vue router and deployed route verification' --date 2026-08-01
```

- [ ] **Step 3: Verify no duplicate or sensitive content exists**

Run:

```powershell
node $script read --scope project --project agnishopbjm-laravel
node $script record --scope project --project agnishopbjm-laravel --category Deployment --fact 'Build the frontend in frontend with npm run build, then publish index.html and referenced hashed assets to backend/public.' --verified-by 'repeat check' --date 2026-08-01
Select-String -Path 'C:\Users\JACKDAW\.codex\memory\projects\agnishopbjm-laravel.md' -Pattern 'password\s*[:=]|token\s*[:=]|secret\s*[:=]|api[_-]?key\s*[:=]' -CaseSensitive:$false
```

Expected: the second record reports `duplicate`; the final command returns no matches.

### Task 5: Run end-to-end validation and restart Codex

**Files:**
- Test: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs`
- Test: `C:\Users\JACKDAW\.codex\skills\codex-global-memory\SKILL.md`
- Test: `C:\Users\JACKDAW\.codex\memory\projects\agnishopbjm-laravel.md`

**Interfaces:**
- Consumes: all files created in Tasks 1 through 4.
- Produces: evidence that the skill is safe, the memory is isolated, and Codex can load the new skill after restart.

- [ ] **Step 1: Run all helper tests**

Run:

```powershell
node --test C:\Users\JACKDAW\.codex\skills\codex-global-memory\scripts\memory-store.test.mjs
```

Expected: PASS with valid record, duplicate, secret rejection, project isolation, and invalid slug coverage.

- [ ] **Step 2: Verify the helper does not write to the repository**

Run from `C:\laragon\www\agnishopbjm-laravel`:

```powershell
git status --short
```

Expected: memory actions add no repository files or modifications. Existing user changes remain untouched and are reported separately.

- [ ] **Step 3: Restart Codex and perform a smoke test**

After restart, ask Codex to summarize the active AgniShop memory before making a change. Expected response mentions the frontend deployment path and the LAN DNS rule without exposing sensitive values.

- [ ] **Step 4: Commit the implementation plan artifact**

```powershell
git add docs/superpowers/plans/2026-08-01-codex-global-memory.md
git commit -m "Plan global Codex memory skill"
```

Expected: only the implementation plan is committed. Do not stage unrelated repository changes.

## Plan Self-Review

- Spec coverage: Tasks 1-5 cover global and project storage, automatic read/write behavior, safety restrictions, AgniShop seed context, conflict handling, and validation.
- Completeness: every task includes concrete files, commands, expected outcomes, and validation steps.
- Interface consistency: all tasks use the same `read` and `record` CLI, `--scope`, `--project`, `--category`, `--fact`, `--verified-by`, and `--date` argument names.
