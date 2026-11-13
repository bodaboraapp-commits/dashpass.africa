# MtaaPass â€” Deployment rollback & post-deploy verification runbook

**Applies to:** production `https://dashpass.africa` (cPanel, PHP 8.3, MySQL 8) and staging/pilot
on the same host.
**Companion docs:** [`deployment-cpanel.md`](deployment-cpanel.md) (deploy steps, Â§3d redeploy
checklist), [`git-release-process.md`](git-release-process.md) (auto-commit/push pipeline), and the
per-increment acceptance records as they land ([`increment-3-acceptance.md`](increment-3-acceptance.md),
[`increment-4-acceptance.md`](increment-4-acceptance.md),
[`increment-5-acceptance.md`](increment-5-acceptance.md); later increments add their own
`increment-<N>-acceptance.md`).

This runbook is for the release/deploy owner. It answers: *how do we undo a bad deploy, how do we
treat the database during a rollback, and how do we prove a deploy is good?* Nothing here contains
credentials â€” secrets live only in the server `.env` (see Â§6).

---

## 0. Before every deploy â€” capture the rollback point (2 minutes)

Do this **before** uploading code or running a migration:

1. Record the **gated commit** you are deploying: `git rev-parse --short HEAD` on the dev machine
   (and the commit shown in cPanel â†’ Gitâ„¢ Version Control on the server). The Lead announces the
   gated sha per increment (Inc-7 gate: `9c4fe06`; later gates will announce their own â€” pin that).
2. Save a copy of the current deployment bundle with the commit in its name:
   `storage/temporary/mtaapass-deploy-<short-sha>.zip` (keep at least the **previous** good bundle
   plus sha256: `Get-FileHash â€¦ -Algorithm SHA256`). The bundle itself is gitignored and not the
   source of truth, but it is the fastest restore path when the server tree is corrupted.
3. Take a **database backup** (cPanel â†’ *Backup* â†’ *Download a Database Backup*, or phpMyAdmin
   export) and store it off-server with a timestamp in the filename.
4. Note the server `.env` state (keys only, never values): `APP_KEY`, `DEPLOY_SYNC_TOKEN`,
   `MAIL_*` â€” these must be unchanged by a code rollback.

**Bundle pins after the resident cleanup (2026-11-12):** the Inc-10 rollback artifact pin
**`f11367c`** remains a valid restore point for the Inc-10 schema. The newer accepted
code-gate pin **`4d492b5`** (`484` entries / sha256 `A36A71CFâ€¦`, routes 195) also
**predates** the resident cleanup commits **`25920c5`** (Payments/& Bills, notices View
All, Account bills), **`0438467`** (Inbox â†’ `/notifications`) and **`8c407e5`** (resident
Activity visitor timeline) plus the 2026-11-13 guest marketing pages (`/about`, `/blog`,
`/help`, `POST /help/contact`): current `main` has **routes 199 (155 web
+ 44 API)** and **no migration, no new permission, no config change**. Rebuild the bundle
at the next **`bundle go`** before capturing a new rollback point for a release that
includes those commits. Code rollback still needs **no DB downgrade** â€” migrations are
forward-only and these commits add none. Targeted suites are green for this tree, but the
exclusive full suite has **not** been re-run after these commits (no 890/890 full-suite
claim applies to HEAD `146e7b7`).

**Golden rules**

* **Migrations are forward-only.** There are no `down` migrations and the app has no downgrade
  command. A database rollback is a **backup restore**, never a hand-written `DROP/ALTER` and never
  editing the `schema_migrations` table.
* **`.env` is never in git or in the bundle.** It lives only on the server; code rollbacks must not
  touch it.
* **`APP_KEY` must stay stable.** Changing it invalidates sessions, encrypted QR tokens and
  invitation credentials.
* **Never force-push GitHub** and never rewrite published commit history. Roll back the *server*
  to an older commit; the repository history stays append-only.
* When in doubt, **roll back first, diagnose second**. Preserve `storage/logs/` before changing
  anything else.

---

## 1. Code rollback (cPanel)

Choose the fastest safe path. Prefer the **git path** for a clean rollback; use the **bundle path**
when the server tree is damaged or Terminal is unavailable.

### 1a. Git path (recommended, needs cPanel Terminal/SSH)

```bash
cd /home/<cpanel-user>/repositories/dashpass.africa     # repo path from Gitâ„¢ Version Control
git status                     # note local changes; .env is untracked/ignored
git fetch --all --prune
git log --oneline -5           # pick the last known-good <sha> (ideally an increment gate commit)
git checkout <known-good-sha>  # detached HEAD at the gated commit (or: git reset --hard <known-good-sha>)
# The domain serves public/ from this working tree; no file copy step is needed.
```

Then in cPanel â†’ **Gitâ„¢ Version Control** â†’ the repository â†’ **Deploy HEAD Commit** (writes the
deploy marker only). Verify with Â§3.

Notes:

* `git checkout <sha>` leaves a detached HEAD â€” fine for a rollback; when the next forward deploy
  happens use **Update from Remote** + `git checkout main` (or `git switch main`).
* `git reset --hard <sha>` moves `main` locally; never push that to GitHub (it would be a
  history-rewriting force-push).
* Do **not** run `git clean -x` / `git clean -fd` blindly: it would delete the untracked `.env`,
  `storage/logs/`, uploads and temp files. If you must clean, exclude them explicitly:
  `git clean -fd -e .env -e .env.production.local -e storage/`.
* If the rollback target predates a tracked file added later (e.g. a newer migration SQL), a plain
  checkout removes it â€” that is correct. Files added by a newer release *and still on disk* are the
  main risk of the bundle path (Â§1b), not the git path.

### 1b. Bundle path (File Manager only, no Terminal)

1. Upload the previous `mtaapass-deploy-<sha>.zip` via cPanel **File Manager** into the repository
   root and **Extract** over it (overwrite existing files).
2. **Do not** overwrite `.env` â€” it is not in the bundle, so extraction leaves it intact.
3. Inspect for **residual files** from the newer release that are not in the older bundle. Quick
   check from the dev machine: `git diff --name-status <good-sha>..<bad-sha>` â†’ delete on the server
   the files marked `A`/`A+M` that should not exist in the old version (typically a newer
   `database/migrations/*.sql`, `app/Modules/<New>/*`, views, routes). Leaving them is usually
   harmless (unrouted code), but leaving a **newer migration file** is not: do not let the next
   `deploy-sync` apply a migration the rolled-back code cannot use.
4. Purge caches (Â§5) and verify (Â§3).

### 1c. Document-root switch (if release directories are retained)

If the deploy keeps release directories (e.g. `/home/<user>/mtaapass_2026-11-11/`), the fastest
rollback is cPanel â†’ **Domains** â†’ document root back to the previous release's `public/`. Keep the
previous release directory for at least one increment. Combine with Â§2 if the DB changed.

### 1d. File-level rollbacks

| File(s) | Rollback | Notes |
|---|---|---|
| `.htaccess`, `public/.htaccess`, `storage/.htaccess` | `git checkout <sha> -- .htaccess public/.htaccess storage/.htaccess` (or restore from bundle) | `public/.htaccess` must exist (front controller + dotfile denial). If a pull conflict is involved, use the `.htaccess` conflict procedure in `deployment-cpanel.md` Â§12. |
| `.env` | restore from the owner's secret backup / re-enter the previous values | never in git/bundle; keep `APP_KEY` identical; changing it logs everyone out and invalidates QR tokens. |
| `public/service-worker.js`, `public/assets/*` | restore with the code rollback | after rollback, purge Cloudflare and hard-refresh; the old service worker may have cached newer assets. |
| `storage/` runtime files | do **not** restore; they are runtime data (logs, sessions, uploads) | deleting `storage/logs/*` is safe only when diagnostics are not needed. |

---

## 2. Database rollback policy (forward-only)

**The database never rolls back by migration.** `deploy-sync.php` supports `status`, `migrate`,
`seed` and `install` only â€” there is no downgrade action. The supported undo is: **restore the
pre-deploy database backup**.

### 2a. When a code rollback is safe without a DB restore

Migrations in this project are **additive** (new tables only, no `ALTER`s to existing tables).
Older code generally tolerates extra tables it does not know about, so a **code-only rollback** is
usually safe when:

* the rolled-back code does not reference the new tables, and
* `/health` shows `pending=0` for the deployed code's migration set (i.e. the DB is at least as new
  as the code), and
* the last deployment did **not** change existing table semantics.

If those hold: roll back code (Â§1), do **not** touch the database, verify (Â§3).

### 2b. When a DB restore is required

Restore the backup **before** returning to the old code when:

* a migration failed half-way (schema partially applied);
* the new release changed data the old code depends on (seed rewrites, permission/role re-links);
* `/health` reports `pending > 0` for the code you are rolling back to (the DB is *older* than the
  target code), or `schema_migrations` shows applied migrations the old code cannot run with;
* tenant/RBAC rows were altered by the release and access is broken.

Restore procedure:

1. Put the deploy on hold (do not run `deploy-sync` while restoring).
2. cPanel â†’ **Backup** â†’ restore the chosen DB backup (or phpMyAdmin â†’ Import of the saved dump)
   into the same database name. Confirm table count/migrations after import.
3. Roll back the code to the matching commit/bundle (Â§1).
4. Call `POST /deploy-sync.php?action=status` (header `X-Deploy-Token`) and confirm
   `pending=0` for that code's migration set.
5. Verify (Â§3) and record the restored backup filename + UTC time in the incident log.

### 2c. `deploy-sync.php` safety rules

| Action | Safety | When to use |
|---|---|---|
| `status` | read-only, safe anytime | after every deploy/rollback; incident triage |
| `migrate` | idempotent â€” applies only pending migrations, serialised by a file lock | after deploying an increment that adds migrations (Inc-6 = migration 8, Inc-7 = migration 9) |
| `seed` | idempotent demo seeder (adds missing demo rows only) | demo/pilot data repair |
| `install` | `migrate` + `seed` (idempotent) | first installation and owner-confirmed increment deploys (Inc-6 path) |

* Auth: `X-Deploy-Token: <DEPLOY_SYNC_TOKEN>` header (preferred) or `?token=`; token unset on the
  server â‡’ endpoint answers **404 by design**; wrong token â‡’ **403**. Never paste the token into
  tickets/chat/logs.
* **Never run `migrate` to "undo"** a migration â€” there is no down path; restore the backup instead.
* **Do not run `migrate`/`install` when `action=status` shows the DB is already at a *newer* schema
  than the code** (post-rollback state) â€” you would only confirm `pending=0`; nothing to apply.
* **Do not run it during an active upload/deploy** (half-written files) or while the app is being
  restored from backup.
* `install` may insert demo rows if they are missing â€” acceptable for pilots/owner-confirmed paths,
  but not a generic production "repair" button. Prefer `migrate` for a pure schema catch-up.
* The endpoint returns generic errors only and never returns SQL, paths or credentials; detailed
  errors go to `storage/logs/`.

---

## 3. Post-deploy verification checklist

Run after **every** deploy and after every rollback. Use a private/incognito window for logged-out
checks and the listed demo accounts for role checks (production pilot: OTP is real SMS; the dev-OTP
panel only exists when `SMS_DEV_EXPOSE_OTP=true` in a non-production environment).

### 3a. Common checks (all increments)

| # | Check | Expected |
|---|---|---|
| 1 | `GET /health` | `200` JSON, `"status":"ok"`, `pending=0`, `storage ok`; `migrations.applied` = the deployed increment's count (Â§3b) |
| 2 | `GET /` | `200` welcome page, CSS/JS `200` |
| 3 | `GET /login` | `200` phone + OTP screen, CSP/`no-store` headers present |
| 4 | `GET /.env` | `404` (never downloadable) |
| 5 | `GET /storage/logs/` | `403`/`404`, never a directory listing |
| 6 | `GET /manifest.json` | `200`, `Content-Type: application/manifest+json`, product name/short-name + 192/512 icons |
| 7 | `GET /service-worker.js` | `200`, `Content-Type: application/javascript`, body has the current `VERSION`, response is not cached (`no-cache`/`must-revalidate`) |
| 8 | Logged-out `GET /app` | `302` to `/login`; API equivalent `401 UNAUTHENTICATED` |
| 9 | Cloudflare | HTML/PHP/`/app`/`/api/*`/`/login`/`/health` are **not** cached (Â§5); cache purged after deploy |
| 10 | **Database integrity** (read-only CLI) | `php -d display_startup_errors=0 scripts/db-integrity-check.php` â†’ `OK â€” database integrity checks passed (26 checks)`, exit `0`; expected pre-Inc-10 counts 8 migrations / 37 tables / 52 permissions / 13 roles / 217 links / 64 FK constraints / 5 settings (Â§3b). Full check list and FAIL handling: [`db-integrity-check.md`](db-integrity-check.md) |

Quick command block (replace `<token>`; do not echo it):

```bash
curl -s  https://dashpass.africa/health
curl -sI https://dashpass.africa/service-worker.js | head -12
curl -sI https://dashpass.africa/manifest.json
curl -sI https://dashpass.africa/.env
curl -s -X POST -H "X-Deploy-Token: <token>" "https://dashpass.africa/deploy-sync.php?action=status"
php -d display_startup_errors=0 scripts/db-integrity-check.php   # exit 0 required (read-only)
```

### 3b. Expected state by increment

| Increment | Routes | Migrations applied | Tables | Role links | DB sync needed? |
|---|---|---|---|---|---|
| Inc-4 admin portal | 99 | 6 | 34 | 198 | no |
| Inc-5 mail / PWA / greeting | 99 | 6 | 34 | 198 | no |
| Inc-6 staff + incidents | 121 | 7 | 36 | 204 | **yes** (migration 8: `action=install`, owner-confirmed) |
| Inc-7 notices | 131 | 8 | 37 | 217 | **yes** (migration 9: `action=install` after the pull) |
| Inc-8 parking admin *(when live)* | 147 | 8 | 37 | 217 | no (no migration) |
| Inc-9 platform portal *(when live)* | 158 | 8 | 37 | 217 | no (no migration) |
| Inc-10 billing *(when live)* | 165 | 9 | 39 | 217 | **yes** (migration 10: `action=install`/`migrate`) |

Route counts are asserted by the test suite (`RouteRegistrationTest`); use the number for the
increment you deployed, not a future one.

### 3c. Inc-4 â€” estate/admin portal

| Check | Role | Expected |
|---|---|---|
| `GET /admin` (logged out) | â€” | `302` to `/login` |
| `GET /admin` | Grace `+254700000001` (`estate_admin`) | `200` dashboard, real stats, no "preview"/demo rows |
| `GET /admin/buildings`, `/admin/households`, `/admin/residents`, `/admin/guards`, `/admin/gates`, `/admin/settings` | Grace | `200` each, forms/CSRF present |
| `GET /admin/...` | Jane `+254700000010` (`resident`) | `403` (permission denied) |
| `GET /admin` | David `+254700000014` (`estate_admin`, Riverside only) | `200` but **Riverside data only** (no Greenview rows) |
| Foreign estate UUID anywhere | Grace (Greenview) | `404` (never another tenant's row) |

### 3d. Inc-5 â€” SMTP mail, PWA, greeting

| Check | Role / tool | Expected |
|---|---|---|
| Greeting on `/app` | Jane | Correct current-estate-local greeting, escaped |
| `GET /manifest.json` | â€” | `200` JSON, hardened fields, icons `192`/`512`, `start_url` `/` |
| `GET /service-worker.js` | â€” | `200`, `VERSION = 'v7'` (or current), no-cache headers, `Service-Worker-Allowed` present |
| Installability self-check | Chrome Android / DevTools â†’ Application | install prompt available, manifest parses, standalone launch, offline shell; SW active |
| Mail config check | server CLI | `php scripts/mail-test.php --check` â‡’ configured (no secrets printed) |
| Live mail send (optional) | server CLI | `php scripts/mail-test.php --to=<verified address>` sends; `storage/logs/mail.log` shows masked recipients; no credential material logged |
| Alert path | John â†’ report high-severity incident | mail attempt logged when `MAIL_ALERT_TO` set; `email.alert_sent` audit; delivery failure is non-blocking |

### 3e. Inc-6 â€” staff directory + incidents (migration 8)

| Check | Role | Expected |
|---|---|---|
| `GET /app/staff` | Jane `+254700000010` | `200` staff directory, masked phone numbers only |
| `GET /app/incidents` | Jane | `200` own incidents only; submitting an incident works (PRG + flash) |
| `GET /gate/incidents` | John `+254700000004` (guard, Main Gate) | `200`; gate incident report works; sees own/gate-scoped records |
| `GET /admin/staff`, `GET /admin/incidents` | Grace `+254700000001` | `200`; create/deactivate staff; incident triage open â†’ investigating â†’ resolved with notes; terminal status `409` |
| `GET /admin/incidents` | David `+254700000014` | Riverside incidents only |
| Foreign incident UUID | Jane/Grace | `404` |
| `/health` | â€” | `migrations.applied=7`, `pending=0` (36 tables / 204 links after the sync) |

### 3f. Inc-7 â€” estate notices (migration 9)

| Check | Role | Expected |
|---|---|---|
| `GET /admin/notices` | Grace | `200` queue with draft/published counts, filters |
| `GET /admin/notices/new`, POST create | Grace | draft created, `302` PRG + flash, audit `notice.created` |
| POST publish â†’ `/admin/notices/{uuid}` | Grace | status published; publishing again â‡’ `409` `NOTICE_ALREADY_PUBLISHED`; archived â‡’ `409` `NOTICE_CLOSED` |
| POST archive | Grace | hidden from residents/guards; audit `notice.archived` |
| `GET /app/notices` | Jane | published + audience all/residents + unexpired only, priority order (urgent â†’ important â†’ normal); empty state honest for Riverside |
| `GET /app/notices/{uuid}` | Jane | `200` published; `404` for draft/archived/expired/foreign |
| Home announcements | Jane | real published notices (â‰¤3), no preview/demo announcements |
| `GET /gate/notices` | John | `200` published, audience all/staff (read-only); `/app/notices` also allowed per Lead decision |
| `GET /app/notices` | David | **no** Greenview notices (empty state) |
| Seed sanity (pilot) | â€” | 4 Greenview demo notices (3 published incl. 1 expiring, 1 draft) |
| `/health` | â€” | `migrations.applied=8`, `pending=0` (37 tables / 217 links) |

**If any check fails and is not a one-line config issue: stop, record evidence (URL, role, status,
UTC time, last commit/bundle), preserve `storage/logs/`, and roll back (Â§1).** Never patch a failing
production deployment by hand-editing `.env`/files without recording it.

---

## 4. Post-incident / deployment record

Write one line per deploy/rollback to the release log (`storage/logs/git-sync.log` is for git
automation; keep a human deploy note in the owner's runbook or ticket):

```
2026-11-11 17:40 EAT  DEPLOY  commit <sha>  bundle sha256 <hash>  increment <N>  result PASS
2026-11-11 18:20 EAT  ROLLBACK commit <bad-sha> -> <good-sha>  reason <one line>  DB restored <backup-file>  result PASS
```

---

## 5. Cloudflare purge & cache rules

1. cPanel â†’ Cloudflare â†’ **Purge Everything** after every deploy **and** every rollback (old CSS/JS,
   service worker and HTML must not survive).
2. Confirm the caching rules still bypass HTML/PHP: `/app`, `/api/*`, `/login`, `/health`, and any
   authenticated area must never be cached (Cloudflare â†’ Rules â†’ Cache Rules / Page Rules).
3. `service-worker.js` and `manifest.json` must not be edge-cached; verify response headers
   (`Cache-Control: no-cache`/`must-revalidate`, correct `Content-Type`).
4. After a rollback, tell pilot users to hard-reload once (or rely on the SW activate cleanup);
   the new service-worker version will replace cached assets on next load.
5. Never enable "Cache Everything" globally as a workaround for slow pages.

---

## 6. Server `.env` checklist (values never committed)

`.env` exists **only** on the server (and in the owner's secret storage). `.env.example` in the repo
keeps placeholders/empty values. On every deploy verify the keys are present â€” do not print values.

| Key | Expected / notes |
|---|---|
| `APP_ENV` | `production` for real pilots (`staging` only for the labelled dev-OTP pilot) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://dashpass.africa` |
| `APP_KEY` | present, **unchanged** since first deploy (rotation invalidates sessions/QR tokens) |
| `APP_VERSION` | current release label (e.g. `0.2.1-dashpass`) |
| `UI_PREVIEW_CONTENT` | `false` (no "Layout preview" rows on a public site) |
| `DB_*` | cPanel-local DB host/name/user/password (MySQL not proxied through Cloudflare) |
| `DEPLOY_SYNC_TOKEN` | 24+ chars, set on the server only; rotate with the procedure below |
| `MAIL_DRIVER` / `MAIL_FROM_ADDRESS` | `smtp` (or `resend`) + a verified sender |
| `MAIL_SMTP_HOST` / `MAIL_SMTP_PORT` / `MAIL_SMTP_USERNAME` / `MAIL_SMTP_PASSWORD` **or** `RESEND_API_KEY` | server `.env` only; never committed/logged |
| `MAIL_ALERT_TO` | optional; alert recipient(s) for high-severity incidents |
| `SMS_DEV_EXPOSE_OTP` | `false` in production (real SMS adapter required) |
| `SESSION_*`, `SECURITY_HSTS` | production cookie/security settings (`auto`/`true` behind HTTPS) |

**`.env` rules:** never commit it, never upload it in a bundle, never paste values into tickets/chat;
`/.env` over HTTP must return `404`; file permissions as tight as cPanel allows (`600`).
**Rotate `DEPLOY_SYNC_TOKEN` immediately after any share/hand-off** (pasted in chat/ticket, visible
in a screenshot/log, uploaded token file not deleted) â€” this is the **â€œpost-share rotationâ€** step
in the release checklist.

### 6a. Rotating `DEPLOY_SYNC_TOKEN` (automated)

Use the server's CLI helper (cPanel Terminal, from the application root) â€” it rewrites **only** the
`DEPLOY_SYNC_TOKEN` line, keeps a timestamped backup, writes atomically and sets `0600`:

```bash
php scripts/rotate-deploy-token.php --generate                       # new 48-hex token, printed once
php scripts/rotate-deploy-token.php --from-file=.deploy-token-new   # token from an uploaded file
```

- **CLI-only** (a web request gets 404); validates exactly **48 lowercase hex** characters and
  refuses anything else; `--from-file` reads the file then deletes the hand-off file; `--generate`
  prints the new token **once** for copying into local secrets.
- Writes `DEPLOY_SYNC_TOKEN=<new>` (replacing the existing line, or appending a commented line if
  missing), keeps `.env.bak-<Ymd-His>`, writes via `tmp` + `rename` (atomic) and `chmod 0600` when
  the host allows it. If the host caches environment at PHP process start, restart the site's PHP
  pool afterwards.
- **File-Manager alternative (no Terminal):** back up `.env` first, then edit the
  `DEPLOY_SYNC_TOKEN=` line to the new 48-hex value in cPanel File Manager and save with permissions
  as tight as possible; delete any hand-off file. Never paste the token into chat/tickets.
- **Update local secrets too:** set the same new token in the **local gitignored `.env` and
  `.env.production.local`** (they are never committed); local tooling that calls `deploy-sync` will
  otherwise 403.
- **Verify the rotation:** the **old token must return `403`** on
  `deploy-sync.php?action=status`; the **new token must return `200`**; `GET /health` is unchanged
  (rotation never touches schema/migrations â€” no deploy is needed for the rotation itself).
- Keep the `.bak` file only until verification passes, then remove it (it contains the old secret).
- **Rotation completed and verified (2026-11-11):** the owner applied the rotation on the server
  (cPanel `/home/<user>/mtaapass/.env`, `chmod 600`) and it was verified from the workstation:
  the **old (previously shared) token now returns `403 Forbidden.`**, while the **rotated 48-hex
  token returns `200`** with `success:true`, `migrations_applied:8`, `migrations_pending:0`
  (database `tokeqvlx_dashpass_db`, environment `production`); `GET /health` remains unchanged
  (**`0.2.1-dashpass`**, applied=8/pending=0). The new value exists **only** in the server `.env`
  / local gitignored `.env` + `.env.production.local` + the gitignored
  `storage/temporary/deploy-token-new.txt` hand-off file â€” **never print or paste it in chat,
  tickets or docs**. Delete the hand-off file once the owner no longer needs it, and re-rotate
  immediately if the token is ever exposed again (repeat the 403/200/health verification).

---

## 7. Incident contact & escalation (placeholders â€” fill before a real pilot)

| Role | Who | Contact | When to escalate |
|---|---|---|---|
| Product owner / decision maker | *TBD* | *TBD* | rollback vs forward-fix decision, tenant-visible outage, data loss risk |
| Release/deploy owner (this runbook) | *TBD* | *TBD* | every failed deploy/rollback |
| cPanel / hosting support | *TBD* | *TBD* (ticket URL) | server-level 500s, PHP/MySQL down, backups unavailable |
| DNS / Cloudflare | *TBD* | *TBD* | DNS/TLS outage, cache purge problems, WAF rule blocking login |
| Email provider (Resend) | *TBD* | *TBD* status page | mail delivery failing broadly |
| SMS provider (production, later) | *TBD* | *TBD* | OTP delivery failures blocking logins |
| Database backup owner | *TBD* | *TBD* | needed restore drill / missing backup |

**Severity guide**

* **S1 â€” site down / data loss / cross-tenant exposure:** stop deploys, snapshot evidence, preserve
  logs, take a fresh DB backup, follow Â§1/Â§2 rollback, notify owner and all-demo users.
* **S2 â€” login/OTP broken, payments (later) or admin unusable:** roll back or hot-fix forward with
  owner approval; notify pilot users.
* **S3 â€” degraded PWA/mail/notices:** verify increment checklist, fix forward; log the incident.
* **S4 â€” cosmetic/minor:** record and batch into the next increment.

**First actions on any incident:** (1) note UTC time + deployed commit/bundle sha, (2) copy
`/health` output and relevant `storage/logs/*` (mask PII), (3) stop further deploys/migrations,
(4) choose rollback path, (5) record the outcome in the deployment record (Â§4).

---

## 8. Related checklists

* Deploy an increment: `docs/deployment-cpanel.md` Â§3d (pull, `.htaccess` conflict, DB sync,
  cache purge, verification URLs).
* Database integrity checker (read-only, run after every install/rollback): [`db-integrity-check.md`](db-integrity-check.md)
  (`php -d display_startup_errors=0 scripts/db-integrity-check.php`).
* Auto-commit/push pipeline: `docs/git-release-process.md` (watcher, bundle refresh policy).
* Bundle refresh trigger: after each increment gate; the bundle is a point-in-time snapshot and must
  be rebuilt after the **Inc-7** DB/route gate (migration 9) before the next production deploy.
