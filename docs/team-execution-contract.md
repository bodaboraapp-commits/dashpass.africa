# Team Execution Contract — no-idle rule (binding for Lead + all teammates)

**Status:** binding from 2026-11-11 onward. Applies to every agent on this project.

## 1. The rule
1. **No agent is ever idle while work remains.** The Lead must always ensure each teammate either has an active task, a queued next task, or is blocked by a named dependency that is being worked. "Idle" means: no active turn, no assigned task, and no queued next task.
2. When an agent reports a task complete, the Lead must **immediately** do one of:
   - assign the next queued task for that agent (with scope + acceptance), or
   - split the next chunk of the current increment and assign it, or
   - record an explicit, named blocker (e.g. "waiting on migration gate opened by Lead") with the exact message that will unblock them, and keep them on standby only for that blocker.
3. If a Lead gate is waiting (e.g. a full suite run), **parallel work must continue** on the next increment's contract/spec/views/tests/docs in ways that are safe for the running suite: no route/migration changes while the shared suite runs, and no writes to another agent's files.
4. Agents must end each report with **"NEXT"**: the next task they can start immediately (or the exact blocker + unblock message). The Lead replies with a claimable task id or a gate message.
5. **Split large tasks** so every agent has something to do: e.g. Inc-6 was split into DB (T37), modules (T38), views (T39), tests (T40), gate (T41), and parallel QA (T47–T49). Increment 7 follows the same pattern.
6. **Parallel safety:** before starting any DB- or route-changing work, check with the Lead; while any `php tests/run.php` is running, only safe scopes are allowed (docs, new views, new modules code, test files for future increments). Never edit `database/**`, `routes/**` or `tests/run.php` concurrently with a suite run unless Lead says so.
7. **Testing collisions:** the test DB (`mtaapass_test`) is shared; the final full-suite run must be exclusive. Until T50 (stable lock) lands, QA agents must poll for no running php test process before running filtered suites, and must not run concurrently with the full suite.
8. **Task board is the source of truth:** every assignment is a shared task; agents claim (CAS revision), execute, report, complete. The Lead keeps a short "who owns what / next step" table in every checkpoint.

## 2. Reporting format (required)
- Files changed (paths), verification commands + exact results, limitations, and **NEXT** (claimable task id or named blocker).
- Product bugs → Lead with file/line/expected; never silently work around product defects in tests.

## 3. Escalation
- If a teammate has nothing safe to do, they must message the Lead with "IDLE — need next task" rather than ending silently.
- Lead must answer with a concrete next task or a named blocker and keep the pipeline moving.

## 4. Current cadence (as of this contract)
Every increment: 1) contract, 2) DB/schema (if any) staged, 3) backend modules, 4) views, 5) tests, 6) Lead gate + acceptance doc, while the **next** increment's contract/DB staging/views/tests run in parallel where safe.
