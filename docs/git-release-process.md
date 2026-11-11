# Git release process — automatic commit & push

**Repo:** <https://github.com/bodaboraapp-commits/dashpass.africa> (branch `main`)
**Remote:** `git@github.com:bodaboraapp-commits/dashpass.africa.git`
**Service:** `scripts/git-autopush.ps1` running as a long-lived background watcher

MtaaPass keeps the running localhost app and GitHub in sync automatically: a
watcher process polls the working tree and, once changes have been quiet for a
configurable period, commits them and pushes to `main`. Product-owner goal: every
update to the localhost app is committed and pushed without manual steps.

---

## 1. Current status

| Item | Value |
|---|---|
| Repo root | `C:\xampp\htdocs\www\MtaaPASS` |
| Branch / remote | `main` / `origin` |
| Foundation commit | `f6d67fd` — "Initial commit: MtaaPass foundation, resident PWA and visitor access module" |
| Watcher script | `scripts/git-autopush.ps1` |
| Watcher log | `storage/logs/git-sync.log` (gitignored) |
| Watcher lock/PID | `storage/temporary/git-autopush.pid` (gitignored) |
| Poll / quiet window | 25 s / 60 s (Lead-managed watcher; options below) |
| Git binary | `.tools/git/cmd/git.exe` (portable MinGit 2.56.1, gitignored) |
| SSH wrapper | `.tools/ssh/git-ssh-wrapper.cmd` (gitignored) |

> The watcher is a DSH **managed background job** in the session that started it
> (for example job id `pwsh-3098` in the session that wrote this file). Job ids are
> session-specific — always confirm the live watcher through the lock file and log:
> `Get-Content storage\temporary\git-autopush.pid` and
> `Get-Content storage\logs\git-sync.log -Tail 20`.

To see what has been published:

```powershell
# local history
& .tools\git\cmd\git.exe log --oneline -10
# local vs. GitHub (empty output = in sync)
$env:GIT_SSH = "$PWD\.tools\ssh\git-ssh-wrapper.cmd"
& .tools\git\cmd\git.exe rev-list --count origin/main..HEAD
```

---

## 2. How the pipeline works

```
file change(s) in the workspace (app code, docs, scripts, …)
      │
      ▼
git-autopush.ps1 poll (every -IntervalSeconds, default 25 s)
      │  git status --porcelain --untracked-files=all
      ▼
tree dirty? ──no──► sleep and poll again (nothing logged while idle)
      │yes
      ▼
signature changed? ──yes──► reset quiet timer, log "changes detected … waiting 60s"
      │no (same signature for -QuietSeconds)
      ▼
git add -A
      │
      ├─► unstage excluded paths (.env*, storage runtime, .tools, keys, credentials)
      ├─► secret screen on staged content → abort commit if a secret is found
      ▼
git commit  (timestamped subject + area / changed-path summary)
      ▼
git push origin main  (never forced, never rewrites history)
      │
      ├─► success → "pushed <hash> to origin/main"
      └─► network failure → commits stay local, retried on every later pass
                           (tracked by git rev-list --count origin/main..HEAD)
```

The pipeline stops at "pushed to GitHub". Deployment and the database migration
step are separate, deliberate actions — see §4b.

Every action writes one line to `storage/logs/git-sync.log`:

```
[2026-11-11 14:27:03] [INFO] [pid 5100] pushed 5c301d4 to origin/main
[2026-11-11 14:27:03] [INFO] [pid 5100] changes detected (1 path(s)); waiting 60s of quiet before committing
```

### Trigger and debounce rules

* **Trigger:** any `git status --porcelain` output (modified, added, deleted,
  renamed, untracked, not ignored) — i.e. any real file change anywhere in the
  tracked tree.
* **Debounce:** the tree must be unchanged for `-QuietSeconds` (default 60,
  minimum 10). Concurrent writers (multiple agents, editors, generated files)
  therefore collapse into one coherent commit. A change that appears mid-window
  restarts the timer.
* **Safety pauses:** no commit while `.git/index.lock` exists (another git
  process is running); the pass is skipped and retried.
* **Retry:** commits made while the remote is unreachable are pushed on the
  next pass — the watcher checks `origin/main..HEAD` even when the tree is clean.
* **One watcher only:** `storage/temporary/git-autopush.pid` is a singleton lock.
  A second watcher detects the live PID and exits instead of racing on the index.

---

## 3. Running / stopping / restarting the watcher

`pwsh` (PowerShell 7) is **not** installed on this machine; use Windows
PowerShell 5.1 (`powershell.exe`). The script is compatible with both.

### Start (the durable service)

```powershell
cd C:\xampp\htdocs\www\MtaaPASS
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\git-autopush.ps1 `
    -IntervalSeconds 25 -QuietSeconds 60
```

From the DSH agent harness, start it as a managed background job so it keeps
running while the session works:

```
pwsh tool: command = powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\git-autopush.ps1 -IntervalSeconds 25 -QuietSeconds 60
           run_in_background = true
```

### Stop

```powershell
$pidFile = 'storage\temporary\git-autopush.pid'
$watcherPid = [int](Get-Content $pidFile -Raw).Trim()
# confirm it is really the watcher before stopping anything
Get-CimInstance Win32_Process -Filter "ProcessId=$watcherPid" | Select-Object ProcessId, CommandLine
Stop-Process -Id $watcherPid
Remove-Item $pidFile -ErrorAction SilentlyContinue
```

Agents can also stop the DSH background job directly (`job_kill` with the job id
returned when it was started). A clean stop removes the PID file; a hard kill can
leave it behind — the next start detects the dead PID and clears it automatically.

### Restart

Stop (as above), then run the start command again. The watcher resumes pushing
anything left local (`origin/main..HEAD > 0`). Do **not** restart the PHP dev
server (`127.0.0.1:8010`) or the ngrok tunnel as part of a restart.

### One-off manual sync

```powershell
# commit + push the current working tree right now (no debounce wait)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\git-autopush.ps1 -Once

# commit locally only (no push); a later watcher pass pushes it
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\git-autopush.ps1 -Once -NoPush
```

`-Once` is for deliberate manual/CI use. The background watcher always honours
the quiet period.

### Exit codes (watcher process)

| Code | Meaning |
|---|---|
| `0` | normal stop / soft failures only (network errors are logged, not fatal) |
| `1` | configuration/tooling error (no git, no remote, bad repo) |
| `2` | remote history diverged (watcher stopped; no force-push — resolve manually) |
| `3` | checked-out branch does not match `-Branch` (no automatic checkout) |

---

## 4. Tooling and key paths

| Path | Purpose | Committed? |
|---|---|---|
| `scripts/git-autopush.ps1` | the watcher / auto-commit-push pipeline | yes |
| `docs/git-release-process.md` | this document | yes |
| `.tools/git/cmd/git.exe` | portable MinGit 2.56.1 (no system git installed) | no (gitignored) |
| `.tools/ssh/git-ssh-wrapper.cmd` | `GIT_SSH` wrapper that calls OpenSSH directly | no (gitignored) |
| `%USERPROFILE%\.ssh\id_ed25519_bodabora` | private key used by the wrapper (outside repo) | never |
| `storage/logs/git-sync.log` | one line per pipeline action | no (gitignored) |
| `storage/temporary/git-autopush.pid` | watcher singleton lock | no (gitignored) |

The script self-heals its tooling: it finds `.tools\git\cmd\git.exe` (falling
back to `git` on `PATH`), recreates `.tools\ssh\git-ssh-wrapper.cmd` if missing,
exports `GIT_SSH` to it and removes any `GIT_SSH_COMMAND` / `core.sshCommand`
(they invoke MSYS `sh.exe`, which the DSH sandbox blocks).

---

## 4b. Deployment step after each push (database sync)

The auto-push pipeline only publishes code to GitHub; it never deploys and never
runs migrations. The production site (`dashpass.africa`, cPanel) is updated by
pulling `main` there and then running the token-protected database sync. Full
deployment instructions live in `docs/deployment-cpanel.md`.

After **every** deployment that contains migrations (i.e. after each increment
that changes `database/`), run the sync so the production schema catches up:

```bash
# apply pending migrations (idempotent, serialised with a file lock)
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=migrate"

# read-only health/schema status
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=status"

# first-time install only: migrations + demo seed (idempotent)
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=install"
```

* The token is the server's `DEPLOY_SYNC_TOKEN` (24+ chars) from its `.env`; the
  locally prepared copy lives in the gitignored `.env.production.local`. It is
  **never** committed — `.env.example` keeps the value empty.
* The endpoint refuses everything (HTTP 404) when the token is unset, and answers
  403 on a mismatch; it never returns SQL, paths or credentials.
* `?token=<token>` also works for a browser/cron call, but the `X-Deploy-Token`
  header is preferred because it stays out of access logs.
* A git push alone does **not** update production: unless cPanel pulls and the
  migrate action runs, the deployed app keeps running the previous schema.

### Which increments need the migrate step?

Run `action=migrate` only when the deployed increment changed `database/`
(i.e. added migration files). **Increment 4 (estate/admin management portal) and
Increment 5 (email alerts, PWA/greeting, mail providers) add no database
migrations** — existing tables already support them (no `ALTER`s) — so **no
`deploy-sync.php?action=migrate` call is required for the Increment-4 or
Increment-5 deployments**. Future increments that do add migrations must run the
migrate step again after the cPanel pull, exactly as described above.

### Deploy bundle refreshes (release agent)

`storage/temporary/mtaapass-deploy.zip` is a release artefact, not a repo file
(gitignored, built locally from the working tree). It excludes `.git/`, `.tools/`,
`.env*` except `.env.example`, runtime storage, logs and test scratch files. The
release agent rebuilds it after code-changing gates, verifies the entry count/size
and runs the secret screen before upload. Current snapshot (2026-11-11, gated source
HEAD `9c4fe06`): ~381 entries, ~0.97 MB compressed (~3.2 MB raw), including
Increments 4–7 (migration 9) — the count grows with each increment; use the entry
count printed by the rebuild. In-flight Inc-8/9/10 code may be present but is
**inert** (routes stay gated at the Inc-7 131-route build); gated deploys must pin the
gated sha. It must be rebuilt again after the **Inc-8 route gate** (no migration) or
the **Inc-10 DB gate** (migration 10). Redeploy steps (pull, `.htaccess` conflict
resolution, deploy-sync `action=install` for migration 9, cache purge, verification
URLs including `/health` applied=8, `/manifest.json`, `/service-worker.js`, `/admin`,
Inc-6 + Inc-7 notices smoke routes and mobile install self-check) are in
`docs/deployment-cpanel.md` §3d.

---

## 5. Changing the remote or the branch

**Remote** — update git, then restart the watcher (it validates the remote at
startup):

```powershell
& .tools\git\cmd\git.exe remote set-url origin <new-remote-url>
# then restart the watcher (section 3)
```

**Branch** — the watcher refuses to switch branches itself. Check the branch out,
then start the watcher with the matching `-Branch`:

```powershell
& .tools\git\cmd\git.exe checkout -b release/next    # or checkout main
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\git-autopush.ps1 -Branch release/next
```

`-Remote <name>` is also supported (default `origin`) if you add a second remote.

---

## 6. Authentication model

Push authentication uses SSH. The DSH sandbox cannot run MSYS `sh.exe`, so git's
normal `GIT_SSH_COMMAND` / `core.sshCommand` paths fail (see §7). Instead the
watcher sets `GIT_SSH` to `.tools/ssh/git-ssh-wrapper.cmd`, which executes
`C:\Windows\System32\OpenSSH\ssh.exe` with:

* `-i %USERPROFILE%\.ssh\id_ed25519_bodabora` (private key, outside the repo)
* `IdentitiesOnly=yes`, `BatchMode=yes` (never prompts), `ConnectTimeout=10`
* `StrictHostKeyChecking=accept-new` (`github.com` is already in `known_hosts`)

That key authenticates as the **`bodaboraapp-commits`** GitHub account and has
push access to the repo (`ssh -T git@github.com` reports
`Hi bodaboraapp-commits! You've successfully authenticated…`). No secrets live in
the repo: the wrapper is gitignored and contains only a path, never key material.

### The product owner's `deploy@dasspass.africa` key

The owner supplied an `ssh-ed25519 … deploy@dasspass.africa` **public** key only.
A public key cannot authenticate on its own and no matching private key exists on
this machine, so it is **not usable for push today**. Two supported paths to
switch to it later:

1. **Recommended:** keep pushing with the working `id_ed25519_bodabora` key (it
   already has write access), or
2. **Switch:** install the private half at e.g.
   `%USERPROFILE%\.ssh\id_ed25519_dashpass_deploy` **outside the repo**, make sure
   that key is authorised on GitHub (account SSH key or repository deploy key with
   write access), then edit the `-i` path in
   `.tools/ssh/git-ssh-wrapper.cmd` (or delete the wrapper and recreate it with the
   new path), verify with
   `ssh -i %USERPROFILE%\.ssh\id_ed25519_dashpass_deploy -T git@github.com`,
   and restart the watcher. Never copy the private key into the workspace.

---

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `couldn't create signal pipe, Win32 error 5` or random `sh.exe` failures | MSYS `sh.exe` is blocked by the sandbox. Never use `GIT_SSH_COMMAND` or `core.sshCommand`; the watcher exports `GIT_SSH` to the `.cmd` wrapper and strips those settings at startup. |
| `Permission denied (publickey)` on push | Wrong/missing key in the wrapper, or the key is not authorised on GitHub. Check `ssh -i <key> -T git@github.com`; fix the wrapper's `-i` path. |
| `Host key verification failed` | `known_hosts` entry missing/corrupt. Add GitHub's host key (`ssh-keyscan github.com >> %USERPROFILE%\.ssh\known_hosts`) or keep `StrictHostKeyChecking=accept-new`. |
| Push rejected (`non-fast-forward`, `[rejected]`) | The watcher fetches and recovers only when a fast-forward is possible. If the histories truly diverged it exits with code `2` and logs `CRITICAL` — resolve manually (inspect `git fetch`, `git log --oneline origin/main`, rebase/merge by hand, push) and restart the watcher. **Never force-push.** |
| `.git/index.lock` exists / pass skipped | Another git process is running. The watcher waits. Only remove a **stale** lock when no git process exists (`Get-Process git`). |
| Commit aborted with `SECURITY` in the log | The secret screen matched staged content (app key value, PEM private-key header, authtoken-shaped assignment). The file:line is logged; remove the secret, rotate it if it was real, then let the next quiet pass commit the rest. |
| Commits happen but never reach GitHub | Remote unreachable or auth failure — `push failed … kept local for retry` is logged. Once fixed, the next pass pushes automatically (or run `-Once`). Check `git rev-list --count origin/main..HEAD`. |
| `/deploy-sync.php` answers 404 or 403 | 404 means the deployment token is unset on the server (by design); 403 means token mismatch. Set/copy `DEPLOY_SYNC_TOKEN` in the server `.env` — see `docs/deployment-cpanel.md` and §4b. |
| Nothing commits although files changed | Check the watcher is alive via the PID file/log, that the changes are not excluded (§8), that the quiet window has elapsed, and that the checked-out branch equals `-Branch` (exit code `3` logs a refusal). |
| Two watchers racing | Singleton lock prevents it; the second exits with `another watcher is already running`. Remove a stale PID file only if that PID is dead. |
| Need to see what happened | `Get-Content storage\logs\git-sync.log -Tail 50`; timestamps are local (Africa/Nairobi). |

---

## 8. Security rules (non-negotiable)

* **Never commit secrets.** `.env` / `.env.*` (except the placeholder
  `.env.example`), `APP_KEY` values, private keys, tokens and uploads must stay
  out of git. Everything pushed to GitHub is public — treat every commit as
  world-readable.
* **Never stage excluded paths.** The pipeline explicitly refuses/strips
  `.env*`, `storage/logs|temporary|uploads|exports|cache`, `.tools/`, `.git/`,
  SSH wrapper, PEM/KEY/PFX/PPK files, `id_*` private keys, and
  credential/secret-named files. `.gitignore` is the first line of defence; the
  watcher's exclusion list is the second.
* **Secret screen before commit.** Staged content is scanned for the real
  `APP_KEY` value (read from the gitignored `.env`), PEM private-key headers,
  authtoken-shaped assignments and literal app-key assignments. A match aborts
  the commit (logged as `SECURITY`) and leaves the files unstaged.
* **No force-push, no history rewrite.** The pipeline is append-only: it never
  uses `--force`, `--amend` or `reset --hard` on published commits. Divergence
  stops the watcher for manual resolution instead.
* **Quiet-period commits only.** Automated commits happen only after
  `-QuietSeconds` of inactivity, so partially written files are not snapshotted.
  `-Once` is the explicit exception, for deliberate manual use.
* **Logs are local.** `storage/logs/git-sync.log` is gitignored; it may contain
  commit hashes and paths but never key material.
* **Deployment token stays server-side.** `DEPLOY_SYNC_TOKEN` and the prepared
  `.env.production.local` are gitignored and never committed. Rotate by editing
  the server `.env` (and the local copy) only — no code change needed. The
  auto-push watcher never reads or transmits the deployment token.
* **Mail credentials stay server-side.** `RESEND_API_KEY` /
  `MAIL_SMTP_PASSWORD` (and the SMTP username) are read from the server's `.env`
  only; the locally prepared copy lives in the gitignored
  `.env.production.local`, and `.env.example` keeps both values empty. They are
  never committed to the repo, docs, issues or logs. **Rotation:** update the
  value in the server `.env` (and the local gitignored copy), restart PHP-FPM /
  the site's PHP pool if the host caches environment at process start, then send
  a test alert to confirm; no code change, no migration and no commit needed.
* **Approved test sentinels.** `tests/Unit/MailEdgeCaseTest.php` and
  `tests/Unit/ResendMailProviderTest.php` intentionally contain fake
  `re_`-prefixed sentinel constants (their names clearly say edge/QA/test
  fixture) that exercise mail-provider redaction. They are not credentials and
  are safe to commit; secret screens should treat them as approved fixtures
  (they never match the real key value).

---

## 9. Known limitations

* **Snapshots, not CI.** Auto-commits record what was on disk after a quiet
  window; they do not prove the app works. Syntax checks (`php -l`,
  `node --check`), the test suite and acceptance are run by the tests agent and
  the Lead — **not** by this pipeline.
* **Publishes only; does not deploy.** A successful push means GitHub is current;
  the live site only updates after cPanel pulls `main` and the
  `/deploy-sync.php?action=migrate` step is run (§4b). The watcher never touches
  the server, the deployment token or migrations.
* **Not an OS service.** The watcher is a session-managed background job. If the
  DSH session/harness or the machine restarts, it stops and must be started
  again (section 3). It is intentionally not installed as a scheduled task.
* **One branch, append-only.** It pushes the configured branch only; no pull
  requests, tags or releases. Multi-machine pushes need manual fast-forward
  handling (no force-push ever).
* **Coarse granularity.** All changes inside one quiet window become a single
  commit; unrelated areas can land together (the subject lists areas/paths).
* **Network dependent.** Offline periods leave commits local; they are pushed on
  the next successful pass. Pushes are not retried with backoff beyond the poll
  interval.
* **Single watcher.** The PID lock allows only one instance per workspace.
* **Manual `-Once` bypasses debounce** and can snapshot work-in-progress; use it
  only when the tree is known-good.
* Only files that are not gitignored are ever considered — runtime artefacts
  (logs, uploads, cache, exports, temp) stay local by design.
