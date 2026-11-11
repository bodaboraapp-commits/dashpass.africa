# Deploy now — MtaaPass/DashPASS Inc-6 → Inc-9 go-live packet

**Audience:** product owner / deploy operator.
**Goal:** take `https://dashpass.africa` from the current live snapshot to **Inc-9** in one
deployment: Increment 4 (admin portal), Increment 5 (SMTP mail + PWA/greeting), Increment 6
(staff directory + incidents), Increment 7 (estate notices/announcements), Increment 8
(parking administration + overstay), Increment 9 (platform super-admin portal).

**At a glance**

| Item | Value |
|---|---|
| Gated commit to deploy (pin) | **the announced Inc-9 gated commit** (Inc-8 pin was `742a9ad`; use the latest gated sha the Lead announces; the release agent reports it with the bundle) |
| Deployment bundle | `storage/temporary/mtaapass-deploy.zip` — rebuilt at the Inc-9 gate (~400 entries, ~1.0 MB; exact count/sha in the release report) |
| Bundle sha256 | recorded by the release agent for this refresh — verify the uploaded archive with `Get-FileHash` (this packet ships inside the archive, so it cannot embed the archive's own final hash) |
| Target DB state | `/health` → `migrations.applied=8`, `pending=0`; **37 tables / 217 role links / 52 permissions** (unchanged by Inc-8 **and** Inc-9 — neither adds a migration) |
| Demo data after seed | 4 Greenview notices (3 published, 1 draft), 3 staff, 3 incidents, parking zones/bays + `parking_overstay_grace_minutes=30`, platform operator **Daniel `+254700000020`**, seeded plans + one Growth subscription per estate |
| Wired route table | **158 routes** (114 web + 44 API) — Inc-10 billing code is inert until its gate |
| Expected elapsed time | ~20–30 min + smoke matrix |

Detailed references: [`deployment-cpanel.md`](deployment-cpanel.md) (full runbook),
[`deployment-rollback.md`](deployment-rollback.md) (rollback/verification),
[`git-release-process.md`](git-release-process.md) (auto-commit/bundle policy).

---

## 1. Pre-flight (do all 10 before touching the server)

- [ ] **1. Confirm the announced Inc-9 gated commit is what you are deploying.** In cPanel →
  *Git™ Version Control* the repository should be on `main`; if it is ahead with later
  in-progress commits, deploy the bundle instead (it is a pinned snapshot) or check out the
  gated sha in the repo (Terminal: `git fetch --all && git checkout <inc-9-gated-sha>`).
  (Inc-8's pin was `742a9ad`; the release report names the Inc-9 pin.)
- [ ] **2. Take a database backup** (cPanel → *Backup* → *Download a Database Backup*, or
  phpMyAdmin → Export). Store it off-server with a timestamp; name it in the deploy record.
- [ ] **3. Record the current live state**: `GET /health` output, the commit shown in the
  server repo, and the current `app_version`. This is your rollback baseline.
- [ ] **4. Save the current deployment bundle** as `mtaapass-deploy-<current-sha>.zip` +
  its sha256 (rollback path, `deployment-rollback.md` §0).
- [ ] **5. Verify the bundle checksum** on the machine you upload from:
  `Get-FileHash storage/temporary/mtaapass-deploy.zip -Algorithm SHA256` must equal the
  sha256 reported by the release agent for this refresh; do not upload a mismatched file.
- [ ] **6. Confirm the domain document root is `<repo>/public`** (cPanel → *Domains* →
  `dashpass.africa` → *Document Root*). If it still points at the repository root, fix it
  **before** deploying — otherwise `.env`, logs and app code are web-reachable (see
  `deployment-cpanel.md` §12).
- [ ] **7. Server `.env` checklist** done (§2 below) — no values are ever committed.
- [ ] **8. Confirm Cloudflare cache rules** bypass HTML/PHP (`/app`, `/api/*`, `/login`,
  `/health`), and that you can run **Purge Everything** (§3).
- [ ] **9. Mail readiness**: a **verified Resend sender domain** and a valid API key are
  configured on the server; `MAIL_ALERT_TO` is set to a monitored inbox for high-severity
  incident alerts (see known issue #5).
- [ ] **10. Announce a maintenance window** to pilot users (a few minutes) and make sure
  nobody is mid-visit at the gate during the deploy.

---

## 2. Server `.env` checklist (server only — never commit / never upload it)

`.env` lives only on the server (and in the owner's secret store). `.env.example` in the
repo keeps placeholders. Verify **keys are present** — never print values into tickets/chat.

| Key | Expected |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://dashpass.africa` |
| `APP_KEY` | present and **unchanged** (rotation logs everyone out and invalidates QR/pass tokens) |
| `APP_VERSION` | `0.2.1-dashpass` (release label for this deploy) |
| `APP_SHORT_NAME` | `DashPASS` (PWA home-screen name) |
| `UI_PREVIEW_CONTENT` | `false` (no demo/preview rows on a public site) |
| `SESSION_SECURE_COOKIE` | `auto` (Secure on HTTPS) or `true` behind Cloudflare TLS |
| `SECURITY_HSTS` | `true` on HTTPS |
| `SMS_DEV_EXPOSE_OTP` | `false` for a production pilot (needs a real SMS provider) |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | cPanel-local DB values (Cloudflare does not proxy MySQL) |
| `DEPLOY_SYNC_TOKEN` | 24+ random chars, server only; rotate: edit `.env` (and the local gitignored copy), restart the PHP pool if env is cached, use the new token; no code change |
| `MAIL_DRIVER` | `smtp` |
| `MAIL_FROM_ADDRESS` | verified sender, e.g. `alerts@dashpass.africa` (owner-confirmed domain) |
| `MAIL_SMTP_HOST` / `MAIL_SMTP_PORT` | `smtp.resend.com` / `465` |
| `MAIL_SMTP_USERNAME` | `resend` |
| `MAIL_SMTP_PASSWORD` | the Resend API key (server `.env` only) |
| `MAIL_ALERT_TO` | security/ops inbox for high-severity incident alerts (optional but recommended) |
| `FIREBASE_SMS_ENABLED` | `false` unless Firebase phone auth is explicitly enabled (dev OTP fallback stays) |

Also: `GET /.env` must return **404**; permissions as tight as cPanel allows (`600`).

---

## 3. Deploy steps (exact order)

**Step 1 — get the gated code onto the server** (choose one):

* **A. Git pull (recommended):** cPanel → *Git™ Version Control* → repository →
  **Update from Remote** (pulls `main`). Because auto-commits may include later in-flight
  code (inert, unrouted), the strict gated option is to check out the pinned commit in the
  repo via Terminal: `cd /home/<user>/repositories/dashpass.africa && git fetch --all &&
  git checkout <inc-9-gated-sha>`.
* **B. Bundle upload (File Manager only):** upload `mtaapass-deploy.zip` to the repository
  root and **Extract** (overwrite files). `.env` is not in the bundle and must be left
  untouched. Remove any *newer* migration file left over from a previous failed upload
  (`deployment-rollback.md` §1b residual-file note).

**Step 2 — fix an `.htaccess` pull conflict if it appears.** If Git pull stops with
“untracked working tree files would be overwritten by merge: .htaccess”, follow
`deployment-cpanel.md` §12: rename the manual file to `.htaccess.manual-backup`, keep the
tracked **root `.htaccess`** and **`public/.htaccess`**, run the pull again, verify the
site, then delete the backup. Never delete `public/.htaccess`.

**Step 3 — Deploy HEAD Commit** in cPanel Git Version Control (writes the deploy marker
only; the domain serves `public/` directly from the clone).

**Step 4 — run the database sync (Inc-7 migration 9 required; Inc-8 and Inc-9 add no
migration).** With the server `DEPLOY_SYNC_TOKEN` (header preferred so it never appears in
access logs):

```bash
curl -s -X POST -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=install"
# read-only status any time:
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=status"
```

The `install` action applies **all pending migrations** (migration 8 + 9 for a pre-Inc-6
server) and runs the idempotent demo seeder. `action=migrate` is enough when seed rows
already exist and must not be re-touched. **Inc-8 itself adds no migration** — after
switching to the Inc-8 build, `/health` stays `applied=8 / pending=0` (37 tables / 217
links); running `install` again is safe and applies the new Greenview setting
`parking_overstay_grace_minutes=30` through the idempotent seeder. **Inc-9 also adds no
migration** — its build keeps `/health` at `applied=8/pending=0`; `install` is a safe no-op
(platform tables/plans/memberships are already seeded), no new schema or demo rows are
required.

**Step 5 — verify the database target.** `GET /health` must show:

```json
{ "status": "ok", "migrations": { "applied": 8, "pending": 0 }, ... }
```

and the schema must be at **37 tables / 217 role links / 52 permissions**, with the demo
seed at 4 notices / 3 staff / 3 incidents, the parking demo zones/bays +
`parking_overstay_grace_minutes=30` settings, and the platform demo operator
**Daniel `+254700000020`** with the seeded plans + Growth subscriptions. If `pending > 0`,
stop and check `deploy-sync.php?action=status`; if 404/403, the token is missing/mismatched.

**Step 6 — purge caches and refresh the service worker.**
cPanel → Cloudflare → **Purge Everything**, then hard-refresh the admin pages once.
Clients pick up service-worker **v4** on next load (old caches are deleted on activate).

**Step 7 — run the smoke matrix (§4) and record the result** in the deployment record.
If anything fails and it is not a one-line config fix: **roll back** (§5).

---

## 4. Smoke matrix (accounts + URLs + expected)

Use these demo accounts (dev-OTP panel only exists when `SMS_DEV_EXPOSE_OTP=true`; in
production the owner signs in with a real SMS code, or the operator assists):

| Account | Phone | Role | Estate scope |
|---|---|---|---|
| Grace Wanjiku | `+254700000001` | `estate_admin` | Greenview **and** Riverside |
| David Njoroge | `+254700000014` | `estate_admin` | **Riverside only** (cross-tenant checks) |
| Jane Smith | `+254700000010` | `resident` | Greenview, household A-001 |
| John Mutiso | `+254700000004` | `security_guard` | Greenview, Main Gate |

### 4a. Common checks (all increments)

| Check | Expected |
|---|---|
| `GET /health` | `200` JSON, `status ok`, `applied=8`, `pending=0`, `storage ok` |
| `GET /` , `GET /login` | `200`, navy welcome / phone+OTP screen |
| `GET /.env` | `404` (never downloadable) |
| `GET /storage/logs/` | `403`/`404`, never a directory listing |
| `GET /manifest.json` | `200`, `application/manifest+json`, DashPASS name + 192/512 icons |
| `GET /service-worker.js` | `200`, `application/javascript`, `VERSION = 'v4'`, not cached |
| Logged-out `GET /app`, `GET /admin` | `302` to `/login` |

### 4b. Inc-4 — estate/admin portal (Grace)

| URL | Account | Expected |
|---|---|---|
| `/admin` | logged out | `302` → `/login` |
| `/admin` | Grace | `200` dashboard, real stats, no preview/demo rows |
| `/admin/buildings`, `/admin/households`, `/admin/residents`, `/admin/guards`, `/admin/gates`, `/admin/settings` | Grace | `200` each, forms + CSRF |
| `/admin` | Jane | `403` (no admin permission) |
| `/admin/households` | David | `200`, **Riverside data only** (no Greenview rows) |
| foreign estate uuid anywhere | Grace | `404` |

### 4c. Inc-5 — mail, PWA, greeting

| Check | Account/tool | Expected |
|---|---|---|
| `/app` greeting | Jane | estate-local greeting, escaped |
| `/manifest.json` | — | hardened fields, `start_url=/`, icons 192/512 |
| `/service-worker.js` | — | `v4`, `no-cache`/`must-revalidate`, `Service-Worker-Allowed` |
| Install self-check | Chrome Android / DevTools → Application | install prompt, manifest parses, standalone launch, offline shell, SW active |
| Mail config | server CLI | `php scripts/mail-test.php --check` → configured (no secrets printed) |
| Live mail (optional) | server CLI | `php scripts/mail-test.php --to=<verified address>` delivers; `storage/logs/mail.log` masked |
| Alert path | John reports high-severity incident (with `MAIL_ALERT_TO` set) | mail attempt logged + `email.alert_sent` audit; failure non-blocking |

### 4d. Inc-6 — staff directory + incidents

| URL | Account | Expected |
|---|---|---|
| `/app/staff` | Jane | `200` staff directory (masked phones); create + deactivate/reactivate works |
| `/app/incidents`, `/app/incidents/new` | Jane | `200` own incidents; report works (PRG + flash) |
| `/gate/incidents`, `/gate/incidents/new` | John | `200`; gate incident report works |
| `/admin/staff` | Grace | `200`; create/deactivate staff rows |
| `/admin/incidents` | Grace | `200` queue + filters |
| `/admin/incidents/{uuid}` + status POST | Grace | triage open → investigating → resolved (notes required); terminal state `409 INCIDENT_CLOSED` |
| `/admin/incidents` | David | Riverside incidents only |
| foreign incident uuid | Jane/Grace | `404` |

### 4e. Inc-7 — estate notices/announcements (migration 9)

| URL | Account | Expected |
|---|---|---|
| `/admin/notices` | Grace | `200` queue with draft/published counts, filters |
| `/admin/notices/new` → POST create | Grace | draft created, `302` PRG + success flash |
| POST publish on `/admin/notices/{uuid}` | Grace | published; publish again → `409 NOTICE_ALREADY_PUBLISHED`; edit published → `409 NOTICE_LOCKED`; archived → `409 NOTICE_CLOSED` |
| POST archive | Grace | hidden from residents/guards; no hard delete |
| `/app/notices` | Jane | published + audience all/residents + unexpired only, priority order (urgent→important→normal) |
| `/app/notices/{uuid}` | Jane | `200` for published; `404` for draft/archived/expired/foreign |
| `/gate/notices` | John | `200` published, audience all/staff (no resident-only rows) |
| `/app/notices` | David | **empty state** (no Greenview notices) |
| Home `/app` | Jane | **real** announcement cards (≤3, priority order) + “View All” → `/app/notices`; no demo/preview announcements |
| Seed sanity | — | 4 Greenview notices seeded (3 published incl. 1 expiring, 1 draft) |

### 4f. Inc-8 — parking administration + overstay (no migration)

| URL / command | Account | Expected |
|---|---|---|
| `/admin/parking` | Grace | `200` overview with real stats + links to zones/bays/allocations/violations |
| `/admin/parking/zones`, `/admin/parking/bays` | Grace | `200`; create/edit a zone/bay, archive a bay (in-use bay must refuse with a validation message) |
| `/admin/parking/allocations` | Grace | `200`; create an allocation, end it (single active allocation per bay/household) |
| `/admin/parking/violations` | Grace | `200`; create a violation, resolve/dismiss it (no hard deletes) |
| `/gate/parking/violations` | John | `200`; report a gate parking violation, then Grace sees it in the admin queue |
| `/admin/parking/*` | David | `200` but **Riverside data only** (no Greenview zones/bays/violations) |
| `php scripts/parking-overstay.php --dry-run` | server CLI | prints the overdue active sessions it would flag; **no writes** |
| `php scripts/parking-overstay.php` | server CLI | flags overdue sessions once (status `overstay` + one violation each); second run reports **0 new** (idempotent) |

### 4g. Inc-9 — platform super-admin portal (no migration)

| URL | Account | Expected |
|---|---|---|
| `/platform` | Daniel `+254700000020` (`platform_super_admin`) | `200` dashboard with real SaaS stats (accounts, estates by status, active subscriptions, plans) + recent **platform audit** table |
| `/platform/estates` | Daniel | `200` estate list + account/plan columns |
| `/platform/estates/new` → POST create | Daniel | provisioning form; creating a test estate + admin user + default settings (+ optional plan subscription) succeeds in one transaction (audit `platform.estate_created`); duplicate slug → `422 ESTATE_EXISTS`, invalid phone → `422` |
| `/platform/estates/{uuid}` + status POST | Daniel | `200` detail + status form; active/suspended/archived transition audited (`platform.estate_status_changed`), no hard delete |
| `/platform/plans` + add/retire | Daniel | `200` list; create plan (duplicate code → `422 PLAN_EXISTS`); retire makes it unselectable for new subscriptions |
| `/platform/subscriptions` + update | Daniel | `200` list; change plan/status; cancelled/expired terminal → `409 SUBSCRIPTION_CLOSED` |
| `/platform`, `/platform/estates`, `/platform/plans`, `/platform/subscriptions` | logged out | `302` → `/login` |
| every `/platform*` route | Grace `+254700000001`, Jane `+254700000010`, a guard | **`403`** (estate users have no platform membership) |

---

## 5. Rollback (if the deploy is bad)

**Full runbook: [`deployment-rollback.md`](deployment-rollback.md).** Fast path:

1. Note UTC time, deployed commit/bundle sha, failing check; preserve `storage/logs/`.
2. Restore code: check out the last known-good commit in the cPanel repo (or extract the
   saved previous bundle) — `.env` is untouched by both.
3. DB: migrations are **forward-only** — if the DB must go back, restore the pre-deploy
   backup (`deployment-rollback.md` §2). Never hand-edit `schema_migrations`.
4. Purge Cloudflare, then re-run the smoke matrix from the previous increment.

**When NOT to run deploy-sync:** never as a “downgrade”; never while rolling back; never
while a restore/upload is in progress. Use `action=status` (read-only) to inspect.

---

## 6. Known issues / deferred (as of this deploy)

1. **Live document-root finding** — if the domain root is still the repository root,
   `.env`/logs are exposed; fix the document root to `<repo>/public` before deploying
   (`deployment-cpanel.md` §12). If it was just fixed, confirm `/.env` → `404`.
2. **Cloudflare/stale asset staleness** — old CSS/JS/service-worker can survive a deploy
   until *Purge Everything* + a hard refresh; if the admin UI looks outdated, purge again
   and reload the page.
3. **No staff-edit route** — staff can be created, deactivated and reactivated only
   (`/app/staff`); there is no edit form this increment. Correct a record by
   deactivating and re-creating it.
4. **Guard incident detail is admin-only by design** — guards have list + report
   (`/gate/incidents`); there is no guard incident-detail route. Triage happens in the
   admin portal.
5. **Alert inbox configuration** — high/critical incident emails only fire when
   `MAIL_ALERT_TO` is set **and** the Resend key/sender are valid. The earlier owner key
   returned `401`; replace/verify it, then check with `php scripts/mail-test.php --check`
   (and a live `--to=` send). Resend sandbox senders only deliver to verified recipients.
6. **Notices are in-app only** — no push/email fan-out, no rich text/attachments, no
   scheduling, no read receipts; archive instead of delete.
7. **Incidents are text-only** — no photo evidence, offline queue or PDF export; notices
   and incidents have no SMS.
8. **Inert later-increment code** — Inc-10 billing files may exist in the bundle, but their
   routes are **not wired** (the wired table is the Inc-9 158-route build). Do not enable
   them without the Inc-10 DB/route gate.
9. **Demo data present** — Greenview/Riverside demo accounts (incl. platform operator Daniel
   `+254700000020`), 4 demo notices, 3 demo staff, 3 demo incidents, parking demo
   zones/bays, seeded plans/subscriptions. Purge/replace before onboarding real residents.
10. **Browser install prompt** must be verified on a real device (automation covers only
    manifest/SW/headers).
11. **`APP_KEY` must never change** — rotating it invalidates sessions and QR/pass tokens.
12. **Parking admin has no barriers/ANPR/payments** — the admin surfaces manage zones, bays,
    allocations and violations only; no boom-barrier or number-plate integration and no
    fines/payments this increment (no hard deletes; archive/end/resolve semantics).
13. **Overstay job is CLI/server-side** — run `php scripts/parking-overstay.php` from the
    cPanel Terminal or a cron entry (an optional cPanel cron line is documented in the
    README); it is not triggered by the web UI.
14. **Platform portal scope** — the audit viewer is **dashboard-only** (no `/platform/audit`
    route); there are no invoices/payments until Increment 10; platform operators are not
    estate users, so they cannot use `/app`/`/admin` route families unless they also hold an
    estate membership (the demo platform admin is a separate account by design).

**Report problems to:** *owner / release operator (TBD contacts)* — for outages follow the
severity + escalation table in `deployment-rollback.md` §7; include the failing URL, role,
HTTP status, UTC time and deployed commit/bundle sha.

---

## 7. Sign-off (fill after smoke)

| Item | Result | By / time |
|---|---|---|
| Bundle sha256 verified | ☐ | |
| Document root = `<repo>/public`, `/.env` 404 | ☐ | |
| `deploy-sync install` success | ☐ | |
| `/health` applied=8 / pending=0 / 37 tables / 217 links | ☐ | |
| Inc-4 admin smoke | ☐ | |
| Inc-5 manifest/SW/mail/install smoke | ☐ | |
| Inc-6 staff/incidents smoke | ☐ | |
| Inc-7 notices smoke (incl. Home cards + View All) | ☐ | |
| Inc-8 parking admin smoke (Grace/David/John + overstay dry-run) | ☐ | |
| Inc-9 platform portal smoke (Daniel; Grace/Jane/guard 403; unauth 302) | ☐ | |
| Cloudflare purged + SW v4 confirmed | ☐ | |
| Deployment record written (commit/bundle sha, time, result) | ☐ | |

If any row fails, stop, preserve logs, and follow [`deployment-rollback.md`](deployment-rollback.md).
