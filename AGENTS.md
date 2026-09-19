# Sebastian — Codex working rules

## Project root
Permanent project root:
D:\Projects\Sebastian

Do not create another working copy.

## Context discipline
Work only on the task requested in the current prompt.

Do NOT scan or inspect the entire repository unless the task explicitly requires it.

Prefer exact paths supplied in the prompt.

Do not inspect:
- unrelated frontend/backend areas
- historical QA reports
- STARTER packages
- old stage instructions
- D:\Projects\Sebastian_Materials
unless the current task explicitly names those files or directories.

If additional context is required, inspect the smallest relevant dependency first.

## Token Optimizer
The token-optimizer MCP server is available.

When its tools are visible:

- use smart_read for large files (about >25 KB / >400 lines);
- use smart_read for files already read during the session;
- use smart_glob for large or unknown directory trees;
- use smart_edit for edits to large files;
- prefer targeted reads/ranges over full-file reads;
- avoid repeated raw reads of unchanged files.

For small one-off files, normal tools are acceptable.

If token-optimizer tools are unavailable, use bounded native reads instead.
Do not waste turns trying repeatedly to discover unavailable tools.

## Search
Do not run repository-wide grep/glob by default.

Search only the relevant directory or exact file set needed by the task.

Do not inspect node_modules, vendor, build output, caches or generated reports
unless the task specifically concerns them.

## Existing architecture
Frontend:
http://127.0.0.1:4321/

CMS:
http://127.0.0.1:8080/admin

Frontend is Astro + TypeScript.
CMS is Laravel + Filament + MariaDB.

Preserve stable project, track and asset IDs.

For content changes use the established CMS workflow when applicable:

CMS Draft
→ Candidate Export
→ Validation
→ Review Diff
→ Explicit Apply
→ Frontend

Do not bypass this workflow by rewriting the active manifest directly
unless the prompt explicitly requires it.

## Safety boundaries
Production release is blocked unless explicitly authorised.

Do not:
- deploy to Netcup
- change DNS
- enable SMTP
- enable analytics/tracking
- expose secrets
- change admin credentials
- run git init / commit / push
unless explicitly requested.

Do not modify originals in:
D:\Projects\Sebastian_Materials

## Verification
Run only tests relevant to the files and behaviour changed by the current task.

Do not automatically rerun the entire historical QA suite.

Reuse existing valid test results when the underlying files have not changed.

If a targeted test fails, diagnose that failure before broadening test scope.

## Handoff
At the end of a task report briefly:

1. files changed;
2. tests actually run;
3. unresolved issues;
4. anything that requires a separate next task.

Do not re-summarize the entire project history.
