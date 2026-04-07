# Gemini CLI Integration Design

**Date:** 2026-04-07
**Scope:** Global Claude Code workflow (all projects)
**Goals:** Token offloading (primary), adversarial review (secondary)

---

## 1. Architecture Overview

The integration has three layers:

### 1.1 Gemini CLI Shell Layer
Gemini CLI installed globally via npm (`@google/gemini-cli`), authenticated once via `gemini auth`. Callable from any terminal session. No per-project configuration required.

Content is piped to Gemini in non-interactive mode:
```sh
echo "<content>" | gemini -p "<prompt>"
cat file1.js file2.js | gemini -p "<prompt>"
```
Exact flag syntax verified during implementation.

### 1.2 Skill Layer (Three Skills)
Three globally-available Claude Code skills stored in `~/.claude/plugins/local/`:

| Skill | Trigger | Purpose |
|---|---|---|
| `gemini-explore` | Understanding large/unfamiliar code | Offload file reading and codebase mapping to Gemini's 1M context |
| `gemini-research` | Knowledge questions without local file context | Offload "how should I implement X / trade-offs of Y" reasoning |
| `gemini-review` | After Claude writes or modifies meaningful code | Adversarial second-opinion on the diff or affected files |

### 1.3 Invocation Pattern
Skills can be triggered:
- **Manually** — `/gemini-explore`, `/gemini-research`, `/gemini-review`
- **Self-invoked** — Claude recognizes the task type and invokes the appropriate skill

Skills return structured output back into Claude's context. Claude reasons about the result rather than just displaying it.

---

## 2. Skill Designs

### 2.1 `gemini-explore`

**Trigger:** Task is primarily about reading, tracing, or understanding a large amount of existing code or files.

**Behavior:**
1. Claude identifies the relevant files for the question at hand.
2. Passes file contents as stdin to Gemini with a targeted analysis prompt.
3. Returns a structured summary (key components, data flow, dependencies, gotchas).
4. Claude reasons from the summary rather than reading the files itself, preserving tokens.

**Prompt framing:** "You are analyzing source code. Summarize the key components, data flow, and anything non-obvious. Be specific and technical. Format output as structured sections."

---

### 2.2 `gemini-research`

**Trigger:** A knowledge or approach question that does not require reading local project files — e.g., "what's the best way to implement X", "what are the trade-offs between Y and Z".

**Behavior:**
1. Claude constructs a focused, well-scoped research question.
2. Passes it to Gemini (no file context needed).
3. Returns a structured answer: recommended approach, alternatives, trade-offs, caveats.
4. Claude uses the answer to inform its implementation plan without spending tokens reasoning from scratch.

**Prompt framing:** "You are a senior engineer answering a technical question. Provide a recommended approach, 1-2 alternatives, trade-offs for each, and any important caveats. Be concrete."

---

### 2.3 `gemini-review`

**Trigger:** Claude has just written or modified meaningful logic (after Edit/Write tool calls on non-trivial code).

**Behavior:**
1. Claude gathers the relevant diff or file content.
2. Passes it to Gemini with an adversarial review prompt.
3. Gemini returns a prioritized critique: bugs, logic errors, security issues, missed edge cases, code quality.
4. Claude evaluates each point — accepts valid critique, pushes back on false positives, and acts on real issues.

**Prompt framing:** "You are an adversarial code reviewer. Find bugs, logic errors, security vulnerabilities, missed edge cases, and code quality issues. Do not be polite. Output as a prioritized list: CRITICAL / MAJOR / MINOR. Only include real issues."

---

## 3. File Locations

```
~/.claude/plugins/local/   ← exact path verified during setup
  gemini-explore.md
  gemini-research.md
  gemini-review.md
```

All three skills are global — available across all projects, not scoped to the portfolio canvas plugin. The correct local skill directory path will be confirmed during installation (may differ from the cached plugins path at `~/.claude/plugins/cache/`).

---

## 4. Skill vs. Raw Slash Command

The key differentiator from a simple `/gemini` slash command is that each skill has:
- A hardcoded, task-appropriate prompt (not generic)
- A defined input preparation step (what Claude gathers before calling Gemini)
- A defined output handling step (how Claude uses Gemini's response)

This produces significantly better signal than an ad-hoc prompt.

---

## 5. Out of Scope (v1)

- Hook-based automatic triggering (can be added later once skill patterns are validated)
- Per-project Gemini configuration
- Gemini web search integration
- Cost/token tracking across both models
