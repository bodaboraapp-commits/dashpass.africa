# Deploy now — MtaaPass/DashPASS Inc-6 → Inc-10 go-live packet

**Audience:** product owner / deploy operator.
**Goal:** take `https://dashpass.africa` from the current live snapshot to **Inc-10** in one
deployment: Increment 4 (admin portal), Increment 5 (SMTP mail + PWA/greeting), Increment 6
(staff directory + incidents), Increment 7 (estate notices/announcements), Increment 8
(parking administration + overstay), Increment 9 (platform super-admin portal), Increment 10
(billing / invoices).

**At a glance**

| Item | Value |
|---|---|
| Gated commit to deploy (pin) | **`f11367c`** (Inc-10 “bundle go” pin; previous: Inc-9 `be9b410`, Inc-8 `742a9ad`) |
| Deployment bundle | `storage/temporary/mtaapass-deploy.zip` — **`409` entries / `1,203,313` B compressed** (includes Inc-4…Inc-10 + accepted Inc-10 UI fixes) |
| Bundle sha256 | **`18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`** — verify the uploaded archive with `Get-FileHash`; recorded here + in `changelog`/acceptance (the packet ships inside the archive, so this hash is of the frozen zip) |
| Target DB state | `/health` → `migrations.applied=9`, `pending=0`; **39 tables / 217 role links / 52 permissions / 13 roles / 72 FK constraints**; demo seed **2 invoices + 1 payment** (Inc-10 migration 10 required) |
| Demo data after seed | 4 Greenview notices (3 published, 1 draft), 3 staff, 3 incidents, parking zones/bays + `parking_overstay_grace_minutes=30`, platform operator **Daniel `+254700000020`**, seeded plans + one Growth subscription per estate, **billing: paid `INV-202610-0001` + open current-month invoice + 1 confirmed `DEMO-MPESA-001` payment** |
| Wired route table | **165 routes** (121 web + 44 API; 7 `/platform/billing*`) — Inc-11 (estate billing read/reports) is future scope |
| Expected elapsed time | ~20–30 min + smoke matrix |

**Verified live state (2026-11-11, updated):** the owner pulled the code and the Lead applied
**migration 10** on live (applied_now `20261111_000010…`, seeded=true) — `/health` → version
**`0.2.1-dashpass`**, `environment: production`, **applied=9 / pending=0** (DB
`tokeqvlx_dashpass_db`; **165 routes**; `/platform/billing` → `302 /login` unauth). Inc-6/7/8/9/10
routes are live; assets `app.js` **59,566 B** / `app.css` **138,526 B**; `/service-worker.js`
Cloudflare bypass **confirmed** (`cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`,
`service-worker-allowed: /`); security probes 403/404. **Live Firebase login is ACTIVATED** (probe
`activationDetected=true`; T138 403 guard remains). **Inc-10 is DELIVERED:** release bundle
`storage/temporary/mtaapass-deploy.zip` = `409` entries / sha256
**`18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`**, pin **`f11367c`**, final suite **757/757 PASS** — the bundle is the release
artifact/rollback record; current live code arrived by repo pull + the Lead's install and the
expected live seed is **2 invoices + 1 payment** (confirm with the integrity check).

**Launch blockers (before real residents):** (1) ~~OTP provider — Firebase phone auth~~ **DONE /
ACTIVATED ON LIVE (2026-11-11, verified)** — the owner applied the server `.env`
(`FIREBASE_SMS_ENABLED=true` + public `FIREBASE_*`; provider=firebase was already active) and live
`/login` now serves the Firebase markers (appId `…ad3900…`, measurement `G-D9JVCQJG9V`, SDK
**13.2.0** module + reCAPTCHA; probe `activationDetected=true`), with the T138 guard still returning
**403 `AUTH_PROVIDER_MISMATCH`** on legacy OTP endpoints. Firebase needed **no migration**. Keep the
console checklist/quota monitoring and rollback (`AUTH_OTP_PROVIDER=server` + PHP reload) available;
(2) **deploy-token rotation** — **DONE/verified 2026-11-11**: old token `403`, rotated token `200`
(rollback §6a).

Detailed references: [`deployment-cpanel.md`](deployment-cpanel.md) (full runbook),
[`deployment-rollback.md`](deployment-rollback.md) (rollback/verification),
[`git-release-process.md`](git-release-process.md) (auto-commit/bundle policy).

---

## 1. Pre-flight (do all 10 before touching the server)

- [ ] **1. Confirm the gated commit `be9b410` (Inc-9) is what you are deploying.** In cPanel →
  *Git™ Version Control* the repository should be on `main`; if it is ahead with later
  in-progress commits, deploy the bundle instead (it is a pinned snapshot) or check out the
  gated sha in the repo (Terminal: `git fetch --all && git checkout be9b410`).
  (Inc-8's pin was `742a9ad`.)
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
  git checkout be9b410`.
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

**Step 4 — run the database sync (Inc-10 migration 10 required).** With the server
`DEPLOY_SYNC_TOKEN` (header preferred so it never appears in access logs):

```bash
curl -s -X POST -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=install"
# read-only status any time:
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=status"
```

The `install` action applies **all pending migrations** and runs the idempotent demo seeder — for
the Inc-10 build this is **migration 10** (`billing_invoices` + `billing_payments` + the additive
`subscriptions (id, estate_id)` unique index) plus the billing demo seed (**2 invoices + 1
payment**, Greenview only). `/health` moves to **`applied=9 / pending=0`** (from applied=8), the
schema to **39 tables / 217 links**, and routes to **165**. `action=migrate` is enough when the demo
seed rows already exist and must not be re-touched; `install` is the owner-confirmed path. Running
`install` again is a safe no-op (idempotent migration 10 + seeder).

**Step 5 — verify the database target.** `GET /health` must show:

```json
{ "status": "ok", "migrations": { "applied": 9, "pending": 0 }, ... }
```

and the schema must be at **39 tables / 217 role links / 52 permissions / 13 roles / 72 FKs**, with
the demo seed at 4 notices / 3 staff / 3 incidents, the parking demo zones/bays +
`parking_overstay_grace_minutes=30` settings, the platform demo operator
**Daniel `+254700000020`** with seeded plans + Growth subscriptions, and **2 billing invoices + 1
payment** (paid `INV-202610-0001` + open current-month invoice, `DEMO-MPESA-001`). If `pending > 0`,
stop and check `deploy-sync.php?action=status`; if 404/403, the token is missing/mismatched.

**Step 6 — purge caches and refresh the service worker.**
cPanel → Cloudflare → **Purge Everything**, then hard-refresh the admin pages once.
Clients pick up service-worker **v5** on next load (old caches are deleted on activate).

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
| `GET /health` | `200` JSON, `status ok`, `version 0.2.1-dashpass`, `environment production`, **applied=9**, `pending=0`, `storage ok` (post-Inc-10 target: 39 tables / 217 links / 2 invoices + 1 payment) |
| `GET /` , `GET /login` | `200`, navy welcome / phone+OTP screen |
| `GET /.env` | `404` (never downloadable) |
| `GET /storage/logs/` | `403`/`404`, never a directory listing |
| `GET /manifest.json` | `200`, `application/manifest+json`, DashPASS name + 192/512 icons |
| `GET /service-worker.js` | `200`, `application/javascript`, `VERSION = 'v5'`; headers `cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`, `service-worker-allowed: /` (verify with `curl -sI https://dashpass.africa/service-worker.js`) |
| Assets | `app.js` 59,566 B, `app.css` 138,526 B (byte-current; no stale edge copy) |
| Inc-6/7/8/9 routes (unauth) | `/app/notices`, `/app/staff`, `/app/incidents`, `/admin/notices`, `/admin/parking`, `/gate/parking/violations`, `/platform*` → `302 /login` (no 404s) |
| Inc-10 billing routes (post-deploy) | `/platform/billing`, `/platform/billing/invoices/new` → `302 /login` unauth; **404 is expected only before the Inc-10 deploy** |
| Security (unauth) | `/.env`, `/.git/HEAD`, `/storage/logs/`, `/deploy-sync.php` → `403`; `/database/`, `/vendor/`, `/README.md` → `404`; never `200` |
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
| `/service-worker.js` | — | `v5`, `no-cache`/`must-revalidate`, `Service-Worker-Allowed` |
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

### 4h. Inc-10 — billing / invoices (migration 10)

| URL / command | Account | Expected |
|---|---|---|
| `/platform/billing` | Daniel `+254700000020` (`platform_super_admin`/finance) | `200` overview with money stats, invoice table + filters, New-invoice CTA; seeded paid `INV-202610-0001` + current-month open invoice listed |
| `/platform/billing/invoices/new` → POST create | Daniel | `200` form (estate/subscription prefill, amount/period/due, draft checkbox); create opens the invoice detail with the ledger; invalid amount → `422` |
| `/platform/billing/invoices/{uuid}` + status/payment POSTs | Daniel | detail `200`; partial payment keeps `open`, covering payment auto-marks `paid`; void/terminal change → `409` (`INVOICE_CLOSED`/`PAYMENT_CLOSED`); refund of the covering payment re-opens the invoice; invalid transition → `422` |
| `/platform/billing/*` | Grace `+254700000001`, Jane `+254700000010`, a guard | **`403`** (estate users have no billing role); unauthenticated → **`302 /login`** |
| `/platform/billing` (before Inc-10 deploy) | — | `404` is expected pre-deploy (routes land with the Inc-10 build) |
| `php scripts/billing-past-due.php --dry-run` | server CLI | reports overdue `open` invoices it would flag; **no writes** |
| `php scripts/billing-past-due.php` | server CLI | flags overdue `open` → `past_due` once (audited `billing.invoice_status_changed`); second run reports **0 new** (idempotent) |

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
8. **Inert later-increment code** — Inc-11 (estate billing read + reports/exports) is future scope;
   nothing in this deployment is inert: the Inc-10 billing routes are wired and must work after the
   migration 10 install.
9. **Demo data present** — Greenview/Riverside demo accounts (incl. platform operator Daniel
   `+254700000020`), 4 demo notices, 3 demo staff, 3 demo incidents, parking demo zones/bays,
   seeded plans/subscriptions, and **billing demo 2 invoices + 1 payment**. Purge/replace before
   onboarding real residents.
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
    route). Billing is **platform-only** in Inc-10 (invoices/payments managed by the operator);
    estate billing read/reports are deferred to **Inc-11**. Platform operators are not estate
    users, so they cannot use `/app`/`/admin` route families unless they also hold an estate
    membership (the demo platform admin is a separate account by design).
15. **Billing limits** — payments are a **manual ledger** (bank/M-Pesa reference recorded by the
    operator; no M-Pesa API/callback/card charge), there is **no invoice email/PDF**, no
    proration/tax/credit notes and no multi-currency conversion; invoices are `void` (no hard
    deletes) and the past-due sweep is a **CLI/cron** job (`scripts/billing-past-due.php`).

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
| `/health` applied=9 / pending=0 / 39 tables / 217 links / 2 invoices + 1 payment | ☐ | |
| Inc-4 admin smoke | ☐ | |
| Inc-5 manifest/SW/mail/install smoke | ☐ | |
| Inc-6 staff/incidents smoke | ☐ | |
| Inc-7 notices smoke (incl. Home cards + View All) | ☐ | |
| Inc-8 parking admin smoke (Grace/David/John + overstay dry-run) | ☐ | |
| Inc-9 platform portal smoke (Daniel; Grace/Jane/guard 403; unauth 302) | ☐ | |
| Inc-10 billing smoke (Daniel: overview/new/detail/payments/status 409s; estate users 403; unauth 302; past-due CLI dry-run→apply idempotent) | ☐ | |
| Cloudflare purged + SW v5 confirmed | ☐ | |
| Deployment record written (commit/bundle sha, time, result) | ☐ | |

If any row fails, stop, preserve logs, and follow [`deployment-rollback.md`](deployment-rollback.md).
