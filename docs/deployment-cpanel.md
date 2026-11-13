# MtaaPass â€” cPanel deployment runbook (dashpass.africa)

Target: cPanel hosting for `dashpass.africa` (currently showing the cPanel default page).
Application: plain PHP 8.2+/8.3, PDO MySQL/MariaDB, no Composer/Node build step.

> **Secrets rule:** never commit `.env`. The real production values live only in a
> gitignored `.env.production.local` on the development machine (and in cPanel).
> The GitHub auto-sync watcher excludes `.env*`, `.tools/`, logs and private keys.

## 0. Preconditions (from the product owner)

- cPanel account for `dashpass.africa` (File Manager or SFTP; ideally Terminal/cron access).
- PHP 8.2 or 8.3 selectable in cPanel â†’ *MultiPHP Manager* (PHP 8.2+ is required).
- MySQL database + user created in cPanel â†’ *MySQL Databases*:
  - database: `<cpanel-user>_dashpass_db`
  - user: `<cpanel-user>_dashpass_db` (the product owner has supplied these; keep them out of git)
  - the user must be added to the database with **ALL PRIVILEGES**.
- DNS/Cloudflare: `dashpass.africa` currently resolves through Cloudflare. Keep the
  Cloudflare proxy **on**, but make sure HTML/PHP responses are not cached (see Â§6).

## 1. Upload layout (recommended: app outside the docroot)

Upload the app to `/home/<cpanel-user>/mtaapass/` (or any non-public folder), then point the
domain document root at the app's `public/` directory:

1. cPanel â†’ *Domains* â†’ `dashpass.africa` â†’ **Document Root** â†’ `/home/<cpanel-user>/mtaapass/public`.
2. Or use a subdirectory-safe layout: upload `public/*` into `public_html/` and the rest into
   `/home/<cpanel-user>/mtaapass/`, then edit `public/index.php` so it requires
   `/home/<cpanel-user>/mtaapass/app/bootstrap.php` instead of `dirname(__DIR__) . '/app/bootstrap.php'`.
   (Document root pointing at `public/` is cleaner and is what this runbook assumes.)

Expected tree on the server:

```
/home/<user>/mtaapass/
â”œâ”€â”€ app/           config/       database/    public/   <- document root
â”œâ”€â”€ routes/        scripts/      storage/     tests/    (tests optional on prod)
â”œâ”€â”€ .env           (created on the server; never in git/zip)
â””â”€â”€ .env.example
```

The deployment bundle `storage/temporary/mtaapass-deploy.zip` (created locally, gitignored)
is rebuilt by the release agent from the current working tree. Latest snapshot
(2026-11-12, built at the **Inc-11/12/13 code-gate â€œbundle goâ€**, gated source pin `4d492b5`):
**`484` entries / `1,665,049` B compressed / `5,080,696` B raw / sha256 `A36A71CF222B5442119975F5CFF3DF8CB37BF3B3F5FE6497F38C2E9C7CA90822`** â€”
includes Increments 4â€“10 (admin portal, mail/PWA/greeting, staff + incidents, notices, parking
administration/overstay, platform super-admin portal, billing/invoices + migration 10) plus the
accepted Inc-10 UI fixes. Counts grow with each increment, so read the entry count printed by the
rebuild, not an approximate figure.
It excludes `.git/`, `.tools/`, `.env`/`.env.*` (only `.env.example` with empty placeholders
ships), runtime storage (`logs|temporary|uploads|exports|cache`), test scratch files, any
`*.log`/`*.zip`, and Inc-12/Inc-13 staging (`storage/temporary/**`). Upload it with cPanel *File
Manager* (Upload â†’ Extract), or SFTP/`git pull` the repository contents. **Live note:** live already
received the Inc-10 code by repo pull and migration 10 is applied (`/health` applied=9) â€” the bundle
is the **release artifact/rollback record** for fresh deploys and re-deploys.

> **STALE ARTIFACT â€” rebuild required before deploy (2026-11-12):** the accepted
> code-gate pin **`4d492b5`** above (`484` entries / sha256 `A36A71CFâ€¦` / routes
> 195) **predates** the resident coming-soon cleanup commits â€” **`25920c5`**
> (Payments/& Bills, notices View All, Account bills), **`0438467`** (Inbox â†’
> `/notifications`) and **`8c407e5`** (resident Activity visitor timeline). The
> current tree is HEAD **latest `main`** (guest-marketing + resident/offline/navigation commits through 2026-11-13), with **routes
> 216 (172 web + 44 API)** and **no migration, no new permission, no config
> change** in those commits. The zip described here is therefore a historical
> code-gate record, **not the current release artifact**: rebuild it at the next
> **`bundle go`** and re-verify the new entry count/size/sha256 before deploying.
> Targeted suites are green (ResidentActivityTest 7/7, ResidentPwaHomeTest 12/12,
> RouteRegistrationTest 8/8, LoginLandingTest 11/11, `--filter=Resident` 121/121;
> QA HealthTest 6/6 + NotificationBellTest 12/12 + 32/32 live smoke), but the
> exclusive **full suite has NOT been re-run** after these commits â€” do not cite a
> 890/890 full-suite baseline for this tree. Production/live is unchanged (still
> Inc-13/Inc-12 preview, `applied=11`) and deploying remains the owner's step.

> **Rebuild rule:** the zip is a point-in-time snapshot, not a streaming deploy. Rebuild it
> after every increment gate that lands routes/views/modules â€” the **Inc-9 build** was produced
> at its route gate (158 routes, no migration, pin `be9b410`); the **Inc-10 build** (pin `f11367c`) was followed by the **Inc-11/12/13 code-gate build** â€” pin **`4d492b5`**, 484 entries, sha256 `A36A71CFâ€¦` (890/890 baseline6, routes 195, migrations 000011/000012). **Deploy is the owner step** (no rollback required; live preview applied=11). See Â§3d and Â§1. Before uploading, verify count/size/sha256 and the release secret screen.

## 2. Create `.env` on the server

Copy the values from the local `.env.production.local` file (gitignored) into a new file
`/home/<user>/mtaapass/.env` on the server. Key points:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashpass.africa
UI_PREVIEW_CONTENT=false          # no "Layout preview" rows on a public site
APP_KEY=base64:...                # the generated production key from .env.production.local
DB_HOST=localhost                 # cPanel DB is local to the server; remote MySQL is not enabled
DB_DATABASE=<cpanel-user>_dashpass_db
DB_USERNAME=<cpanel-user>_dashpass_db
DB_PASSWORD=<the cPanel DB password>
SESSION_SECURE_COOKIE=true
SECURITY_HSTS=true
SMS_DRIVER=log                    # fallback server-OTP path only (development adapter; refused in production)
SMS_DEV_EXPOSE_OTP=false          # never expose OTPs publicly
AUTH_OTP_PROVIDER=firebase        # default OTP gateway: Firebase phone auth (owner console setup required)
FIREBASE_SMS_ENABLED=true
FIREBASE_API_KEY=<public browser key>            # public by design; restrict by HTTP referrers in Google Cloud
FIREBASE_AUTH_DOMAIN=komiuta.firebaseapp.com
FIREBASE_PROJECT_ID=komiuta
FIREBASE_STORAGE_BUCKET=komiuta.firebasestorage.app
FIREBASE_MESSAGING_SENDER_ID=99186909972
FIREBASE_APP_ID=1:99186909972:web:ad3900fddee22fe6b91801
FIREBASE_MEASUREMENT_ID=G-D9JVCQJG9V
FIREBASE_ALLOW_SERVER_OTP_FALLBACK=false   # dev/tooling only; forced off in production (never enable on live)
LOG_LEVEL=warning
SEO_GOOGLE_SITE_VERIFICATION=     # Google Search Console HTML-tag token; blank renders no meta tag
SEO_BING_SITE_VERIFICATION=       # Bing Webmaster Tools msvalidate.01 token; blank renders no meta tag
```

Permissions: `.env` 600, `storage/` (and `storage/logs`, `storage/temporary`) writable by the
PHP user (typically 755 for dirs, 644 for files; use 775 only if the PHP user differs from your
cPanel user).

> **OTP = Firebase phone auth by default** (`AUTH_OTP_PROVIDER=firebase`,
> `FIREBASE_SMS_ENABLED=true`). The **browser** Firebase SDK sends/verifies the SMS; the server
> verifies Firebase **ID tokens** against Google's public certificates â€” **no service-account
> JSON**, no secret and no server SMS credentials are needed. The implementation is landed in dev
> (T127/T128): `POST /login/verify` accepts the hidden `firebase_token`, failures map to
> `INVALID_FIREBASE_TOKEN` (401) / `FIREBASE_PHONE_INVALID` (422) / `SERVICE_UNAVAILABLE` (503),
> and logins audit `auth.firebase_login`. **Auth-provider enforcement (T138):** with
> `provider=firebase` the legacy API OTP endpoints return **`403 AUTH_PROVIDER_MISMATCH`**
> (web `/login/otp` gets a generic redirect; web `/login/verify` requires the hidden
> `firebase_token`), guards run before validation/rate-limits and audit is reason-only;
> `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` (default `false`) is a dev/tooling-only escape hatch that is
> **force-disabled in production** â€” never enable it on live. The login page uses **Firebase modular SDK 13.2.0**
> (static ESM imports from gstatic in an external `type="module"` script; no inline config, no
> compat globals; **analytics intentionally omitted**); the server verifier is unaffected by
> appId/measurement/SDK changes (it depends only on the unchanged project id `komiuta`). The owner
> must still complete the Firebase console
> checklist (Phone sign-in enabled; authorized domains; reCAPTCHA; billing/quota) and verify one
> real OTP round-trip **after the test suite passes** â€” full guide + optional tuning keys
> (`AUTH_FIREBASE_IP_*`/`AUTH_FIREBASE_PHONE_*`, cert-cache TTL, clock leeway):
> [`firebase-sms-otp.md`](firebase-sms-otp.md). CSP note: `style-src 'unsafe-inline'` is
> deliberately allowed **only for the Google reCAPTCHA badge** (Â§5 of the guide); the script
> policy stays strict (no inline scripts/eval). Fallback: `AUTH_OTP_PROVIDER=server` (server OTP)
> requires a real SMS provider in production â€” the app refuses `log`/`mock` adapters when
> `APP_ENV=production`, by design.

## 2b. Branding (per-deployment product name)

The product name is configuration-driven, so the same codebase can run as
**MtaaPass** in development and **DashPASS** in production:

```dotenv
APP_NAME="DashPASS"          # titles, PWA manifest, install prompts, emails
APP_SHORT_NAME="DashPASS"    # compact home-screen name (defaults to APP_NAME)
APP_SUPPORT_EMAIL=hello@dashpass.africa
```

`/manifest.json` is generated from these values (dynamic route, not a static
file), so no asset edits are needed per deployment. The development `.env`
keeps `APP_NAME="MtaaPass"`.

## 3. Run migrations + seed

cPanel â†’ *Terminal* (or a cron â€œonceâ€ job / `php -r` wrapper):

```bash
cd /home/<user>/mtaapass
php -v                          # must be 8.2+
php scripts/migrate.php --status
php scripts/migrate.php         # applies 7 migrations (through migration 8)
php scripts/seed.php            # demo estate + role catalogue (idempotent)
```

**Automatic migrations:** the app now applies pending migrations on the **first web request**
after a git pull (`AUTO_MIGRATE=true` by default, guarded by a MySQL advisory lock and a 60s
check stamp), so a Git deploy no longer needs the manual `php scripts/migrate.php` step.
The command above stays as the explicit fallback / seeding path; set `AUTO_MIGRATE=false` in
`.env` to disable the automatic run. Verify `/health` shows `pending: 0` after the first load.

`install.php` is the shortcut (`migrate.php` + `seed.php`, idempotent, never drops the DB).

The seed creates the **demo Greenview Residences** estate, demo users and visitor fixtures.
Before onboarding real residents, purge/replace the demo rows (or add the estate-admin module
in the next increment and create the real estate there). The README lists demo accounts.

## 3b. cPanel Gitâ„¢ Version Control (recommended deploy path)

The repository is public, so cPanel can clone it directly â€” no credentials needed:

1. cPanel â†’ **Gitâ„¢ Version Control** â†’ **Create** â†’ *Clone a Repository*.
   - Clone URL: `https://github.com/bodaboraapp-commits/dashpass.africa.git`
   - Repository Path: `/home/<cpanel-user>/repositories/dashpass.africa`
   - Branch: `main`
2. Set the domain document root to the cloned `public/` folder:
   cPanel â†’ **Domains** â†’ `dashpass.africa` â†’ **Document Root** â†’
   `/home/<cpanel-user>/repositories/dashpass.africa/public`.
3. Create `.env` in the repository root (File Manager) from `.env.production.local` (Â§2).
4. Make `storage/` writable: `storage/logs`, `storage/temporary` (the app creates
   `storage/temporary/sessions` on first run).
5. First database install (no Terminal needed): call the sync URL in Â§3c with
   `action=install` â€” it applies all 7 migrations (through migration 8) and seeds the demo
   estate.
6. Verify `/health` returns `migrations.applied=8`, `pending=0`.

Updates flow (every time the local app changes):

```
local auto-sync watcher  â†’  GitHub main  â†’  cPanel "Update from Remote" (git pull)
                                          â†’  cPanel "Deploy HEAD Commit"
                                          â†’  POST /deploy-sync.php?action=migrate
                                             (ONLY when the increment adds migrations â€” NOT for Inc-4/Inc-5)
                                          â†’  purge Cloudflare cache (changed CSS/JS/SW)
                                          â†’  smoke /health, /manifest.json, /service-worker.js, /admin
```

Increments 4 (estate/admin portal) and 5 (SMTP mail, PWA/greeting) add **no database
migrations**, so their redeploy needs only the git pull + deploy + cache purge â€” do not
run `action=migrate`. See Â§3d for the step-by-step redeploy checklist and Â§3c for the sync
URL when a future increment does add migrations.

Optional automation of the pull (cPanel Terminal/cron, if available):

```bash
# crontab -e
*/5 * * * * cd /home/<user>/repositories/dashpass.africa && git pull --ff-only >> /home/<user>/.mtaapass-git-pull.log 2>&1
```

`.cpanel.yml` is included and only writes a deploy marker, so cPanel's
"Deploy HEAD Commit" button always succeeds without copying files (the domain
serves `public/` directly from the clone).

## 3c. Database sync URL (maintenance over HTTPS, no Terminal)

`public/deploy-sync.php` is a standalone, token-protected maintenance endpoint.
It is **disabled** unless `DEPLOY_SYNC_TOKEN` is set in `.env` (24+ characters),
and it returns only generic errors (details go to `storage/logs`).

```bash
# status (safe, read-only)
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=status"

# apply new migrations after a deployment
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=migrate"

# first-time install (migrate + demo seed; idempotent)
curl -s -H "X-Deploy-Token: <token>" \
  "https://dashpass.africa/deploy-sync.php?action=install"
```

- Preferred authentication: `X-Deploy-Token` header (keeps the token out of access logs).
  `?token=` also works for a plain browser/cron call.
- Actions: `status`, `migrate`, `seed`, `install`.
- **After every `action=install`/`action=migrate`, run the read-only database integrity check**
  and record the output: `php -d display_startup_errors=0 scripts/db-integrity-check.php`
  (exit `0` = pass). It verifies migration checksums, schema/tenant FKs, reference data and
  secrets/PII hygiene; expected counts and FAIL handling are in
  [`db-integrity-check.md`](db-integrity-check.md).
- Serialised with a file lock; safe for a deploy hook. Never returns SQL, paths,
  credentials or stack traces. **Rotate the token with
  `php scripts/rotate-deploy-token.php --generate` (or `--from-file=<path>`) on the server** â€” it
  validates a 48-hex token, backs up `.env`, writes atomically and sets `0600`; no code change is
  needed. Full procedure + verification (old token `403` â†’ new token `200`) and the File-Manager
  alternative: [`deployment-rollback.md`](deployment-rollback.md) Â§6a.
- The production token is stored **only** in the gitignored `.env.production.local`
  on the development machine (and in the server's `.env`). It is never committed.

> **Pilot login mode:** the prepared `.env.production.local` uses `APP_ENV=staging`
> with `SMS_DEV_EXPOSE_OTP=true` so the labelled development-OTP panel works on the
> test site (no SMS provider yet). Switch to `APP_ENV=production` and
> `SMS_DEV_EXPOSE_OTP=false` before a real pilot with residents; at that point a
> real SMS provider adapter is required for login to work.

## 3d. What's new to redeploy (Inc-4 admin Â· Inc-5 SMTP/PWA Â· Inc-6 staff/incidents Â· Inc-7 notices Â· Inc-8 parking admin Â· Inc-9 platform portal Â· Inc-10 billing)

Since the last deployed snapshot the live app is missing:

* **Increment 4 â€” estate/admin portal**: `/admin` dashboard plus buildings, households
  (and members), residents, guards, gates and estate-settings screens;
  `layouts/admin.php` and `assets/css/admin.css`. Estate admins see their **active estate
  only**; forms are server-rendered (no JSON admin API).
* **Increment 5 â€” email + PWA/greeting**: `app/Integrations/Mail/*` (Resend HTTP API,
  Resend SMTP, log driver), `config/mail.php`, `EmailAlertService` alert hooks, hardened
  `ManifestController`, greeting on the `/app` home screen, service-worker **v7** cache
  bump, and `public/.htaccess` hardening.
* **Increment 6 â€” staff directory + incidents**: resident staff directory (`/app/staff`),
  resident incidents (`/app/incidents`), guard gate incidents (`/gate/incidents`), admin
  staff/incident management (`/admin/staff`, `/admin/incidents`). Adds **migration 8**
  (`20261111_000008_create_staff_and_incident_tables.sql` â†’ `household_staff` + `incidents`
  only, no ALTERs) and `DemoStaffIncidentSeeder`; Inc-6 ran the **121-route** build
  (77 web + 44 API) with role links 204.
* **Increment 7 â€” estate notices / announcements**: admin composer
  (`/admin/notices`, `/admin/notices/new`, `/admin/notices/{uuid}` + publish/archive),
  resident list/detail (`/app/notices`, `/app/notices/{uuid}`) and guard read-only list
  (`/gate/notices`); the Home announcement feed now shows **real** published notices (â‰¤3,
  priority order) with a â€œView Allâ€ link. Adds **migration 9**
  (`20261111_000009_create_estate_notices_table.sql` â†’ `estate_notices` only, no ALTERs)
  and `DemoNoticeSeeder` (4 Greenview notices: 3 published incl. 1 expiring, 1 draft; none
  for Riverside). The shipped route table is the **Inc-7 gate build = 131 routes** (87 web
  + 44 API), 52 permissions / **217 role links**, 4 demo notices.
* **Increment 8 â€” parking administration + overstay**: admin parking surfaces
  (`/admin/parking` overview, `/admin/parking/zones`, `/admin/parking/bays`,
  `/admin/parking/allocations`, `/admin/parking/violations` â€” create/edit/archive/end/
  resolve), guard violation reporting (`/gate/parking/violations`), and the CLI overstay
  detector (`php scripts/parking-overstay.php --dry-run` then apply). **No migration** â€”
  the parking tables/settings already exist; the only DB delta is the idempotent seed
  setting `parking_overstay_grace_minutes=30` (Greenview). The wired route table becomes
  **147 routes** (103 web + 44 API); DB stays **8 migrations / 37 tables / 217 links**.
* **Increment 9 â€” platform super-admin portal**: a separate `/platform` shell for platform
  operators â€” dashboard with real SaaS stats + recent **platform audit viewer**
  (dashboard-only audit; no separate `/platform/audit` route), estates list + provisioning
  form (account/estate/admin user/default settings/optional plan subscription in one
  transaction), estate detail + status form, plans list + add + retire, subscriptions list +
  change plan/status. **No migration** â€” platform tables already exist; DB stays **8
  migrations / 37 tables / 217 links**. Wired route table becomes **158 routes**
  (114 web + 44 API; 11 platform web routes, all `['auth']` + controller platform gate, no
  APIs). Seeded demo platform operator: **Daniel Mwangi `+254700000020`**
  (`platform_super_admin`); Grace/Jane/guards are estate users and must get **403** on every
  platform route (unauthenticated â†’ `302 /login`).
* **Increment 10 â€” billing / invoices**: platform-only billing surfaces (`/platform/billing`
  overview with money stats/filters, `/platform/billing/invoices/new` create form, invoice detail
  with ledger + status/payment actions), the manual **payment ledger** (M-Pesa/bank/card/cash
  reference), and the past-due CLI (`php scripts/billing-past-due.php --dry-run` then apply).
  Adds **migration 10** (`20261111_000010_create_billing_tables.sql` â†’ `billing_invoices` +
  `billing_payments` + an additive `subscriptions (id, estate_id)` UNIQUE index; **no other table**
  touched). Wired route table becomes **165 routes** (121 web + 44 API; 7 billing web routes,
  `['auth']` + controller platform gate + `billing.invoice.view` / `billing.payment.manage`; no
  APIs). DB after install: **9 migrations / 39 tables / 52 permissions / 13 roles / 217 links /
  72 FKs** + demo **2 invoices + 1 payment** (Greenview). Estate users **403**, unauth **302**;
  Billing nav/quick-link is visible to platform `billing.*` / super-admin / platform-finance only.

> **In-flight later increments (do not enable):** Inc-11 estate billing read + reports/exports is
> future scope (no core migration expected). For a gated deploy **pin the announced gated commit
> for the increment you are deploying** â€” Inc-8 was `742a9ad`, Inc-9 was `be9b410`; the Inc-10 pin
> is announced with the Inc-10 bundle. Never enable later-increment routes without their gates.

Redeploy steps:

1. **Pull** â€” cPanel â†’ **Gitâ„¢ Version Control** â†’ the repository â†’ **Update from Remote**.
   If the pull stops on `.htaccess`, resolve it per Â§12 (rename the manual file to
   `.htaccess.manual-backup`, keep the tracked `.htaccess` and `public/.htaccess`; do not
   delete `public/.htaccess`).
2. **Deploy HEAD Commit** â€” writes the deploy marker only; the domain serves `public/`
   directly from the clone.
3. **Database sync â€” Inc-10 migration 10 required; Inc-7/8/9 migration history below** â€” after the
   pull, run the sync URL with `action=install` (migrate + idempotent demo seed) and the
   `X-Deploy-Token` header:

   ```bash
   curl -s -X POST -H "X-Deploy-Token: <token>" \
     "https://dashpass.africa/deploy-sync.php?action=install"
   ```

   Expect `/health` â†’ `migrations.applied=9`, `pending=0`, schema at **39 tables / 217 permission
   links / 52 permissions**, demo seed **2 invoices + 1 payment**. The migrator applies **all
   pending migrations** idempotently, so a server still on Inc-5 (6 applied) gets migrations 8 + 9
   + 10 in one call, an Inc-6 server (7 applied) gets 9 + 10, and an Inc-9 server (8 applied) gets
   migration 10 only. (`action=migrate` is enough when the demo seed rows already exist and must not
   be re-touched; `install` is the owner-confirmed path.) **Inc-8/Inc-9 added no migration** (their
   builds stayed applied=8 / 37 tables); **Inc-10 is the first migration since Inc-7** â€” after the
   install the app has the 7 `/platform/billing*` routes and the seeded invoices; running `install`
   again is a no-op.
4. **Mail configuration (server `.env` only)** â€” set the values from
   `.env.production.local` on the server: `MAIL_DRIVER=smtp` (or `resend`),
   `MAIL_FROM_ADDRESS`, `MAIL_SMTP_HOST|PORT|USERNAME|PASSWORD` or `RESEND_API_KEY`.
   Never commit these; `.env.example` keeps them empty. If the host caches environment at
   PHP process start, restart the site's PHP pool. Optionally verify with
   `php scripts/mail-test.php --check` and `php scripts/mail-test.php --to=<address>`.
5. **Cache purge** â€” cPanel â†’ Cloudflare â†’ **Purge Everything** (new `admin.css`,
   `app.js`, Home announcements markup and service worker). Existing clients pick up
   service-worker v7 on next load; old caches are deleted on activate. Hard-refresh the
   admin pages once for CSS.
6. **Verify** (full table in Â§4):
   - `GET /health` â†’ `200`, `migrations.applied=8`, `pending=0` (37 tables / 217 links).
   - **Inc-6 smoke routes** â€” each `200` for the right role, real data, no preview rows:
     - `GET /app/staff` â€” resident **Jane `+254700000010`** â†’ staff directory.
     - `GET /app/incidents` â€” Jane â†’ own incidents; reporting an incident works.
     - `GET /gate/incidents` â€” guard **John `+254700000004`** â†’ gate incidents; report works.
     - `GET /admin/staff` and `GET /admin/incidents` â€” **Grace `+254700000001`**
       (`estate_admin`) â†’ manage/triage (open â†’ investigating â†’ resolved); **David
       `+254700000014`** â†’ Riverside only.
   - **Inc-7 notices smoke** (4 demo notices seeded):
     - `GET /admin/notices` â€” Grace â†’ `200` queue; create a draft (PRG + flash), publish it,
       archive it; publishing twice â†’ `409 NOTICE_ALREADY_PUBLISHED`; editing a
       published/archived notice â†’ `409 NOTICE_LOCKED` / `409 NOTICE_CLOSED`.
     - `GET /app/notices` â€” Jane â†’ published + audience all/residents + unexpired only,
       priority order; `GET /app/notices/{uuid}` for a draft/archived/expired/foreign
       notice â†’ `404`.
     - `GET /gate/notices` â€” John â†’ published, audience all/staff (no resident-only rows).
     - `GET /app/notices` â€” **David `+254700000014`** â†’ Riverside empty state (no Greenview
       notices).
     - `GET /app` â€” Jane â†’ **real** announcement cards (â‰¤3, priority order) + â€œView Allâ€
       linking to `/app/notices`; no demo/preview announcements.
   - **Inc-8 parking admin smoke** (Grace = `estate_admin`; David = Riverside-only):
     - `GET /admin/parking` â€” Grace â†’ `200` overview with real stats + links.
     - `GET /admin/parking/zones`, `/admin/parking/bays`, `/admin/parking/allocations`,
       `/admin/parking/violations` â€” Grace â†’ `200`; create/edit/archive a bay, end an
       allocation, resolve a violation (validation errors show inline; no hard deletes).
     - `GET /gate/parking/violations` â€” guard **John `+254700000004`** â†’ `200`; report a
       gate violation, then Grace resolves it from the admin queue.
     - `GET /admin/parking` â€” **David `+254700000014`** â†’ Riverside data only (no Greenview
       zones/bays/violations).
     - Overstay CLI (server, after the deploy): `php scripts/parking-overstay.php --dry-run`
       (prints what would be flagged, no writes) â†’ then apply on a test estate/session and
       confirm it flags the session **once** (idempotent second run = 0 new).
   - **Inc-9 platform portal smoke** â€” sign in as the seeded platform operator **Daniel
     Mwangi `+254700000020`** (`platform_super_admin`; a platform membership, not an estate):
     - `GET /platform` â†’ `200` dashboard with real SaaS stats (accounts, estates by status,
       active subscriptions, plans) + the recent **platform audit** table.
     - `GET /platform/estates` â†’ `200` list; `GET /platform/estates/new` â†’ `200` provisioning
       form; `POST /platform/estates` creates a test estate + admin user + default settings
       (+ optional plan subscription) in one transaction and audits
       `platform.estate_created`; duplicate slug â†’ `422 ESTATE_EXISTS`, invalid phone â†’ `422`
       (rollback leaves no partial rows).
     - `GET /platform/estates/{uuid}` â†’ `200` detail + status form;
       `POST /platform/estates/{uuid}/status` toggles active/suspended/archived (audit
       `platform.estate_status_changed`; no hard deletes).
     - `GET /platform/plans` â†’ `200` list; `POST /platform/plans` creates a plan (duplicate
       code â†’ `422 PLAN_EXISTS`); `POST /platform/plans/{uuid}/retire` retires it.
     - `GET /platform/subscriptions` â†’ `200` list; `POST /platform/subscriptions/{uuid}`
       changes plan/status (cancelled/expired terminal â†’ `409 SUBSCRIPTION_CLOSED`).
     - Area gate: `GET /platform`, `/platform/estates`, `/platform/plans`,
       `/platform/subscriptions` logged out â†’ `302 /login`; logged in as **Grace
       `+254700000001`** / **Jane `+254700000010`** / a guard â†’ **403** on every platform
       route.
   - **Inc-10 billing smoke** â€” sign in as **Daniel `+254700000020`** (platform
     `platform_super_admin`/finance):
     - `GET /platform/billing` â†’ `200` overview with money stats, invoice table/filters and a
       â€œNew invoiceâ€ CTA; the seeded paid invoice `INV-202610-0001` + current-month open invoice
       are listed.
     - `GET /platform/billing/invoices/new` â†’ `200` form (estate/subscription prefill, amount,
       period/due, draft checkbox); create â†’ detail `200` with the payment ledger (`DEMO-MPESA-001`
       on the seeded paid invoice).
     - Status/payment actions: `void`/terminal change â†’ `409` (`INVOICE_CLOSED`/`PAYMENT_CLOSED`);
       record a payment â†’ partial keeps `open` / covering payment auto-marks `paid`; refund of the
       covering payment re-opens the invoice; invalid transitions/amounts â†’ `422`.
     - Access: Grace `+254700000001` / Jane `+254700000010` / a guard â†’ **`403`** on every
       `/platform/billing*` route; unauthenticated â†’ **`302 /login`**; no hard deletes.
     - Past-due CLI (server): `php scripts/billing-past-due.php --dry-run` (no writes) then apply
       on a test estate â†’ overdue `open` invoices become `past_due` once (idempotent second run = 0
       new).
   - `GET /manifest.json` â†’ `200`, `Content-Type: application/manifest+json`, DashPASS
     name/short-name and 192/512 icons.
   - `GET /service-worker.js` â†’ `200`, `Content-Type: application/javascript`,
     body contains `VERSION = 'v7'` (`mtaapass-shell-v7`/`mtaapass-static-v7`), and the file itself is not cached
     (`Cache-Control: no-cache`/`must-revalidate`).
   - `GET /admin` logged out â†’ login redirect. Log in as **Grace Wanjiku
     `+254700000001`** (`estate_admin`) â†’ `/admin` dashboard `200` with real stats;
     Buildings, Households, Residents, Guards, Gates and Settings render without errors
     and no demo/preview rows. Then **David Njoroge `+254700000014`** â†’ Riverside only
     (cross-tenant check).
   - **Mobile install self-check** (Chrome Android, or desktop DevTools â†’ Application):
     HTTPS, install prompt/â€œAdd to Home screenâ€ available, manifest parses with no errors,
     icons render, standalone launch works, offline shell loads, and
     *Application â†’ Service Workers* shows the **v7** worker activated.
   - `.env`/dotfiles denial: `/.env` â†’ `404`; `/storage/logs/` â†’ `403/404`, never a listing.
7. **Rebuild the bundle** after the next route/DB-changing gate â€” the **Inc-10 build** is produced
   after the final suite/â€œbundle goâ€ (migration 10 â†’ 165 routes / 9 migrations / 39 tables); the
   next content gate after that is **Inc-11** (estate billing read + reports/exports) â€” see the Â§1
   rebuild rule.

> **LIVE PREVIEW â€” Inc-13/Inc-12 WIP (2026-11-12, owner decision: NO ROLLBACK):** the owner pulled
> the WIP code via **git pull** and migrations **000011 + 000012** are applied â€” live `/health` is
> now **applied=11 / pending=0** (**42 tables / 84 FKs / 52 permissions / 13 roles / 217 links**),
> **routes 183 at the E2E run** (current tree **195** = 151 web + 44 API after the schedule/Inc-11
> batch). Firebase login is **ACTIVE**; **billing, notice-image and
> incident routes are live**. **Code gate ACCEPTED** (Inc-11/12/13 acceptance records; baseline6
> **890/890 PASS, exit 0**) â€” **not yet a production deploy**: live preview stays applied=11 and
> production activation is the owner Â§1b step. **Release bundle:** pin **`4d492b5`** (`484` entries /
> sha256 `A36A71CF222B5442119975F5CFF3DF8CB37BF3B3F5FE6497F38C2E9C7CA90822`); **previous rollback
> pin `f11367c`** = Inc-10 bundle artifact `storage/temporary/mtaapass-deploy.zip` / archive
> `storage/temporary/f11367c.zip` â€” schema is additive-compatible and migrations forward-only, so a
> code rollback needs no DB downgrade. The **UI hotfix is effectively live** (code included) and
> `storage/temporary/ui-hotfix-inc10.zip` is retained for rollback/reference. Remaining preview
> gaps: **P2 settings forms polish**, **resident Account card removals / estate Appearance paused**.
>
> **Artifact stale after the resident cleanup (2026-11-12):** the release bundle pin
> **`4d492b5`** (routes 195) predates commits **`25920c5`/`0438467`/`8c407e5`**. The
> current tree HEAD is **latest `main`** with **routes 216 (172 web + 44 API)** and no
> migration/permission/config change, so the recorded `484`-entry / `A36A71CFâ€¦`
> artifact is **not current** â€” rebuild at the next **`bundle go`** and re-verify
> count/sha256 before deploying. Targeted suites are green; the exclusive full
> suite has **not** been re-run for this tree. Live preview remains `applied=11`;
> deploying is still the owner's step.
>
> **UI/branding snapshot (2026-11-12, current tree):** manifest `name` = **`<Product> Skip the Queue`**
> (`short_name` unchanged), guest tagline **â€œSkip the Queueâ€**, offline title **â€œYou are offline Â·
> Skip the Queueâ€**; footer **`<Product> <version> Â· Secure, Fast & Accountable.`** + contact
> **hello@dashpass.africa** (no `support@â€¦`); sign-in copy has the developer helper + **â€œNot registered yet? Ask the Estate Administrator to add your phone number, then sign in here.â€** with a single theme toggle (no duplicate); **service worker v7** (`mtaapass-shell-v7`/`mtaapass-static-v7`); bolder
> directional chevrons; admin gates/guards action rows inline (`form-actions--row`). CSV export is
> final: BOM + static headers, cap **50,000 â†’ 422 `EXPORT_TOO_LARGE` at 50,001+**, PII-free
> `report.exported` audit.
>
> **Verified live state (2026-11-11, after the Inc-9 deploy + owner fixes):** live runs the
> **Inc-9 build** (routes **158**), `/health` reports version **`0.2.1-dashpass`**,
> `environment: production`, **applied=8 / pending=0**; Inc-6/7/8/9 routes are live (unauth â†’
> `302 /login`, no 404s); current assets `app.js` **59,566 B** / `app.css` **138,526 B**.
> `/service-worker.js` Cloudflare **bypass confirmed working**: `cf-cache-status: DYNAMIC`,
> `cache-control: no-cache, max-age=0`, `service-worker-allowed: /`. Security probes:
> dotfiles/logs â†’ **403**, source dirs â†’ **404**, nothing exposed.
>
> **Inc-10 status (DELIVERED 2026-11-11):** the owner pulled the code and the Lead applied
> **migration 10** on live via `deploy-sync install` (applied_now `20261111_000010â€¦`, seeded=true) â€”
> live `/health` is **applied=9 / pending=0** (DB `tokeqvlx_dashpass_db`; `/platform/billing` â†’
> **302 `/login`** unauth), so **billing routes are live and the DB is seeded** (expected **2
> invoices + 1 payment**; confirm with the read-only integrity check). The **release bundle is now
> rebuilt**: `storage/temporary/mtaapass-deploy.zip` = `484` entries / sha256
> `A36A71CF222B5442119975F5CFF3DF8CB37BF3B3F5FE6497F38C2E9C7CA90822`, pin **`4d492b5`**, final suite **890/890 PASS (baseline6)** â€” it is the release
> artifact/rollback record (live code arrived by repo pull + install).
>
> **Live Firebase is ACTIVATED (2026-11-11, verified):** the owner applied the server `.env`, and
> the live `/login` now serves Firebase markers (provider=firebase, appId `â€¦ad3900â€¦`, measurement
> `G-D9JVCQJG9V`, SDK 13.2.0 module + reCAPTCHA); probe `activationDetected=true`. Firebase needed
> no migration (separate from Inc-10).
>
> **Deploy-token rotation DONE and verified (2026-11-11):** the owner applied the rotated token on
> the server; the **old (shared) token now returns `403 Forbidden.`** and the **rotated 48-hex token
> returns `200`** (`success:true`, `migrations_applied:8`, `migrations_pending:0`, database
> `tokeqvlx_dashpass_db`, environment `production`); `/health` remains `0.2.1-dashpass` /
> applied=8 / pending=0. The new value lives only in the server `.env` (`chmod 600`) + local
> gitignored secrets / hand-off file â€” **never print or paste it**; re-rotate immediately if it is
> ever exposed again ([`deployment-rollback.md`](deployment-rollback.md) Â§6a).
>
> **Launch blockers (before onboarding real residents):** (1) ~~OTP provider â€” Firebase phone auth~~
> **DONE/ACTIVATED 2026-11-11** â€” owner applied the server `.env`; live `/login` shows the Firebase
> markers and the probe reports `activationDetected=true` (T138 403 guard still active; no
> migration). Keep the Firebase console checklist/quota monitoring in place; rollback remains
> `AUTH_OTP_PROVIDER=server` + PHP reload;
> (2) **deploy-token rotation** â€” **DONE/verified 2026-11-11** (old token 403, rotated token 200).

If any step in Â§3d or any verification below fails, do not patch production by hand â€” follow
[`docs/deployment-rollback.md`](deployment-rollback.md) (code/DB rollback, backup restore,
per-increment checklist, escalation).

## 4. Verify the deployment

| Check | Expected |
|---|---|
| `https://dashpass.africa/health` | `200` JSON, `"status":"ok"`, `migrations.applied=9`, `pending=0`, `storage ok` (**39 tables** / 217 permission links / 52 permissions / 2 invoices + 1 payment) |
| `https://dashpass.africa/health` (post-Inc-10 target) | `applied=9`, `pending=0`; DB **9 migrations / 39 tables / 52 permissions / 13 roles / 217 links / 72 FKs / 2 invoices + 1 payment**; routes **165** |
| `https://dashpass.africa/health` (live, verified 2026-11-11) | `200` JSON: `"version":"0.2.1-dashpass"`, `"environment":"production"`, **`migrations.applied=9`**, `pending=0` (Inc-10 code + migration 10 applied on live; bundle zip is still the Inc-9 build â€” T113 pending) |
| `curl -sI https://dashpass.africa/service-worker.js` (live, verified) | `cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`, `service-worker-allowed: /` â€” Cloudflare bypass working; body `VERSION = 'v7'` |
| Assets (live, verified) | `/assets/js/app.js` = **59,566 B**, `/assets/css/app.css` = **138,526 B** (byte-current build) |
| Inc-6/7/8/9/10 surfaces (live, unauth) | `/app/notices`, `/app/staff`, `/app/incidents`, `/gate/notices`, `/admin/notices`, `/admin/parking`, `/gate/parking/violations`, `/platform*`, **`/platform/billing`** â†’ `302 /login` (never `404`; Inc-10 routes/migration applied on live) |
| Security exposures (live, verified) | `/.env`, `/.env.example`, `/.git/HEAD`, `/storage/logs/`, `/deploy-sync.php` â†’ **403**; `/database/`, `/vendor/`, `/README.md`, `/scripts/` â†’ **404**; never `200` |
| Database integrity (CLI, read-only) | `php -d display_startup_errors=0 scripts/db-integrity-check.php` â†’ `OK â€” database integrity checks passed (26 checks)`, exit `0`; **post-Inc-10 expected counts: 9 migrations / 39 tables / 52 permissions / 13 roles / 217 links / 72 FK constraints / 5 settings + 2 invoices / 1 payment** (pre-Inc-10 was 8/37/â€¦/64 FK) â€” see [`db-integrity-check.md`](db-integrity-check.md) |
| `https://dashpass.africa/` | **reference-clone single-screen landing** (200): brand/nav header; **Check in** + **Scan QR** CTAs; helper â€œSign in to check in or scan a visitor pass.â€; estate image card **28px radius / 4:3** with diamond+dot accents; slim footer with contact **hello@dashpass.africa**; CSS/JS 200 |
| `https://dashpass.africa/about` | **302 â†’ `/#about`** (`AboutController`; stable-link redirect, no rendered About page) |
| `https://dashpass.africa/login` | phone + OTP screen |
| `https://dashpass.africa/manifest.json` | `200`, `application/manifest+json`, DashPASS name/icons |
| `https://dashpass.africa/service-worker.js` | `200`, `application/javascript`, `VERSION = 'v7'`, not cached |
| `https://dashpass.africa/admin` (logged out) | `302` to `/login`; after login as Grace â†’ dashboard `200` |
| `https://dashpass.africa/admin/parking` (logged in as Grace) | `200` overview; zones/bays/allocations/violations pages `200` |
| `https://dashpass.africa/platform` (logged in as Daniel `+254700000020`) | `200` platform dashboard + audit table; `/platform/estates`, `/platform/plans`, `/platform/subscriptions` `200`; estate users (Grace/Jane/guard) `403` |
| `https://dashpass.africa/platform/billing` (logged in as Daniel, post-Inc-10) | `200` overview with money stats + seeded invoices; `/platform/billing/invoices/new` `200`; invoice detail + payments/status actions `200`/`409`/`422`; estate users `403`, unauth `302` |
| `https://dashpass.africa/app/notices` (logged in as Jane) | `200` notices list (published + audience + unexpired only) |
| `https://dashpass.africa/app` (logged out) | `302` to `/login` |
| OTP delivery (pilot) | operator reads the code from `storage/logs/sms.log` on the server |
| Guard flow | log in as a guard â†’ `/gate` â†’ verify a PIN |

Full per-increment post-deploy checklist (Inc-4 admin â†’ Inc-5 mail/PWA â†’ Inc-6 staff/incidents â†’
Inc-7 notices â†’ Inc-8 parking admin), expected counts, rollback steps and escalation: see
**[`docs/deployment-rollback.md`](deployment-rollback.md)**.

Quick CLI health check:

```bash
cd /home/<user>/mtaapass && php -r 'require "app/bootstrap.php"; echo "bootstrap ok\n";'
```

## 5. Apache / PHP settings

- `public/.htaccess` is included: `Options -Indexes`, front-controller rewrite, dotfile denial.
  `mod_rewrite` is enabled by default on cPanel.
- Set `display_errors=Off` and `log_errors=On` for production in cPanel â†’ *MultiPHP INI Editor*.
- The app writes sessions to `storage/temporary/sessions` (outside the web root); make sure it is
  writable. No system temp dependency.
- SSL: cPanel *AutoSSL* and/or Cloudflare Full (strict) must be active â€” `APP_URL` and cookies
  assume HTTPS.

## 6. Cloudflare

- Keep the proxy on for TLS/HTTP2. Cloudflare does **not** proxy MySQL, so the app must run on
  this cPanel server where the database is local.
- Do not cache HTML/PHP or `/app`, `/api/*`, `/login`, `/health`. If a â€œCache Everythingâ€ rule
  exists, add exceptions (or a Cache Rule: bypass for those paths). Purge cache after deploys
  so the old service-worker/assets are not served.
- **Re-check the `/service-worker.js` cache rule after every deploy** â€” the worker must reach
  clients with `Cache-Control: no-cache`/`must-revalidate` (and `Service-Worker-Allowed`); a Cache
  Rule/Page Rule that caches the worker keeps an old service worker alive. Verify with
  `curl -sI https://dashpass.africa/service-worker.js` before/after each deploy, and purge/bump if
  the headers or `VERSION` are stale. **Verified live 2026-11-11:** `cf-cache-status: DYNAMIC`,
  `cache-control: no-cache, max-age=0`, `service-worker-allowed: /` (Cloudflare bypass active).
- The ngrok tunnel used in development is **not** part of production; `APP_URL` must be the
  public domain.

## 7. Cron jobs (later increments)

Not required for this deployment. When the scheduler increment lands:
`*/1 * * * * php /home/<user>/mtaapass/scripts/expire-invitations.php` and the queue worker.

## 8. Rollback

**Full runbook: [`docs/deployment-rollback.md`](deployment-rollback.md)** â€” code rollback via cPanel
Git or bundle restore, forward-only DB policy + backup restore, per-increment verification, Cloudflare
/ `.env` checklist and incident escalation. Short version:

1. Keep the previous release directory (`mtaapass_2026-11-11`) and/or the previous
   `mtaapass-deploy-<sha>.zip` bundle, and switch/restore back to it; or check out the last known-good
   commit in the cPanel Git repository.
2. Database: take a phpMyAdmin export **before** each migration; restore that dump if a migration
   must be rolled back (migrations are forward-only by design â€” never hand-edit `schema_migrations`).
3. `APP_KEY` must stay stable â€” changing it invalidates encrypted QR tokens and sessions.
4. After any rollback: purge Cloudflare, verify `/health`, `/`, `/login`, **run the read-only
   database integrity check** (`php -d display_startup_errors=0 scripts/db-integrity-check.php`,
   exit `0` required â€” see [`db-integrity-check.md`](db-integrity-check.md)), then run the increment
   checklist in `docs/deployment-rollback.md` Â§3.

## 10. Troubleshooting

### "/" shows `/cgi-sys/defaultwebpage.cgi` (cPanel "Default Web Site Page")

This is cPanel's **placeholder page**, served when the domain's document root exists but contains
**no index file** (typically an empty `public_html`). It is not the application, not an application
error and not a Cloudflare cache problem (`cf-cache-status: DYNAMIC`, verified with cache-busting
queries). The app files have not been deployed to the folder the domain serves, so there is nothing
for Apache to run. Fix in this order:

1. cPanel â†’ **File Manager** â†’ open the domain's Document Root (usually `public_html`) â€” it should
   contain `index.php`, `assets/`, `manifest.json`, etc. If it is empty/default, nothing is deployed.
2. Deploy the files (Git Version Control clone or bundle upload) **first**, then point the document
   root at the application's `public/` folder (see Â§3b). Creating the docroot before the clone leaves
   Apache serving a missing path.
3. Verify `https://dashpass.africa/index.php` is no longer a cPanel 404 and
   `https://dashpass.africa/health` returns JSON.
4. Purge Cloudflare (**Caching â†’ Purge Everything**) if the old default page lingers.
5. `.env` is irrelevant until step 3 succeeds â€” PHP cannot read a configuration file Apache is not
   serving.

### "404 Not Found â€” cp_errordocument.shtml" (the reported symptom)

This is **cPanel/Apache's own error page**, not an application error. It means Apache could not
find a file at the configured document root. Cases:

1. **The repository has not been cloned in cPanel yet** â†’ the document root
   (`â€¦/repositories/dashpass.africa/public`) does not exist. Do step Â§3b.1 first.
2. **Document root is wrong** (points at the repository root instead of its `public/` folder, or at
   `public_html`, or has a typo). Expected value: `/home/<cpanel-user>/repositories/dashpass.africa/public`.
3. **Document root still `public_html` and `public_html` is empty** â†’ `/` shows the cPanel default
   page and other paths show this 404. None of the app files are being served.
4. **Deployed but only `public/` was uploaded** without `app/`, `config/`, `database/`, `routes/`,
   `scripts/` one level above â†’ PHP cannot find `../app/bootstrap.php` (usually a 500, but a missing
   `index.php` in the docroot also gives a 404).

Verify from a terminal/browser:

```bash
curl -sI https://dashpass.africa/ | head -5
curl -s  https://dashpass.africa/health          # after deploy: 200 JSON
```

If `/health` is 404 while `/index.php` is also 404, the app files are simply not under the document
root yet. Fix the document root or upload the bundle (`storage/temporary/mtaapass-deploy.zip`,
built locally, no secrets) via cPanel File Manager â†’ Extract.

### Other common failures

| Symptom | Likely cause / fix |
|---|---|
| 500 on every page | `storage/` not writable, `.env` missing/invalid, PHP < 8.2, or app files missing one level above `public/`. Check cPanel error log + `storage/logs/app-*.log`. |
| `Database connection failed` | Wrong DB name/user in `.env`; user not attached to DB; host must be `localhost`. |
| `/deploy-sync.php` â†’ 404 | Endpoint not deployed yet or document root wrong; or `DEPLOY_SYNC_TOKEN` unset (it answers 404 by design). |
| `/deploy-sync.php` â†’ 403 | Token mismatch; copy the exact value from `.env`. |
| `/health` shows `pending > 0` | Call the sync URL with `action=migrate`. |
| Old page/404 still shown in browser | Cloudflare cache: cPanel â†’ Cloudflare â†’ Purge Everything, or append `?v=now`. |
| `.env` downloadable over HTTP | Document root must be `public/`, not the repository root; `.htaccess` also denies dotfiles. |

## 11. Security checklist before a real pilot

- [ ] `.env` not reachable via HTTP (`/.env` â†’ 404) and not in git (`git log --all -- .env` empty).
- [ ] `APP_DEBUG=false`, `UI_PREVIEW_CONTENT=false`.
- [x] **Post-share rotation â€” DONE 2026-11-11 (verified):** `DEPLOY_SYNC_TOKEN` was rotated after
  the earlier chat exposure â€” old token now `403 Forbidden.`, rotated 48-hex token
  `deploy-sync.php?action=status` `200` (`success:true`, applied=8/pending=0), `/health` unchanged
  (`0.2.1-dashpass`), local gitignored `.env`/`.env.production.local` updated, server `.env` `chmod
  600`; value never printed. Re-rotate on any future exposure (see
  [`deployment-rollback.md`](deployment-rollback.md) Â§6a).
- [ ] Real SMS provider configured (or operator-assisted pilot explicitly agreed).
- [ ] Demo data removed/replaced before real residents are onboarded.
- [ ] Daily cPanel backups enabled for files + database.
- [ ] Cloudflare WAF/rate limiting considered for `/login` and `/v/*`.
- [ ] Guard devices locked down; supervisor reconcile for walk-ins/offline (later increment).

## 12. Known live-deployment finding (dashpass.africa, 2026-11-11)

**Symptom:** `https://dashpass.africa/deploy-sync.php` â†’ web-server 404, while
`https://dashpass.africa/public/deploy-sync.php` works; `https://dashpass.africa/` shows a directory
listing (`Index of /`) with `app/ config/ database/ docs/ public/ routes/ scripts/ storage/ tests/`,
and `https://dashpass.africa/storage/logs/app-*.log` is downloadable.

**Cause:** the domain document root is the repository root (`public_html`), not the application's
`public/` directory. The app therefore runs at the `/public/...` URL prefix, its router sees the
wrong request path (so `/public/health` returns the app's own 404), and every non-public repository
file is in the web root (directory listings + log download = information disclosure).

**Fix (required):** cPanel â†’ **Domains** â†’ `dashpass.africa` â†’ **Document Root** =
`/home/<cpanel-user>/<app-root>/public` (for a repository clone: `â€¦/repositories/dashpass.africa/public`;
for a public_html upload: `/home/<user>/public_html/public`). Save, then verify:

```bash
curl -sI https://dashpass.africa/ | head -3          # 200, no directory listing
curl -s  https://dashpass.africa/health              # JSON, migrations applied
curl -s  https://dashpass.africa/deploy-sync.php?action=status   # with X-Deploy-Token: JSON
curl -sI https://dashpass.africa/storage/logs/        # 404/403, never a listing
```

**Temporary mitigation if the document root cannot be changed immediately:** upload the repository's
root `.htaccess` and `storage/.htaccess` to the web root. The root `.htaccess` routes requests into
`public/` (so the app and `/deploy-sync.php` work at root paths) and denies direct access to app code,
logs, docs, dotfiles and data files. Defence-in-depth only â€” the document-root change remains the
correct fix.

**Also required after fixing:** delete the previously exposed logs under `storage/logs/` on the
server (they are gitignored and regenerated), purge Cloudflare cache, and re-deploy the latest code
(the previously uploaded bundle was a pre-Increment-3 snapshot: the live DB showed 5 of 6
migrations). Rebuild and upload the current `mtaapass-deploy.zip` (Inc-4 â€¦ Inc-9; Inc-8 pin
`742a9ad`, Inc-9 pin `be9b410`) using the Â§3d checklist. If
verification or the DB sync fails, follow
[`docs/deployment-rollback.md`](deployment-rollback.md) (rollback, backup restore, incident steps).

### Git pull fails: "untracked working tree files would be overwritten by merge: .htaccess"

This happens when the Option-B fallback `.htaccess` was created manually in the repository working tree,
and a later commit (the repo now tracks the root `.htaccess` and `storage/.htaccess`) tries to bring the
tracked version in. The manual and tracked files contain the same routing/security rules, so the tracked
version should win. Resolve it without losing the site:

**File Manager route (simplest):**
1. cPanel â†’ **File Manager** â†’ open the repository path shown in **Gitâ„¢ Version Control** (usually
   `/home/<user>/repositories/<repo>`; for a `public_html` clone use `/home/<user>/public_html`).
2. Rename `.htaccess` â†’ `.htaccess.manual-backup` (do NOT delete `public/.htaccess`).
3. If the same error mentions `storage/.htaccess`, rename that one too.
4. cPanel â†’ **Gitâ„¢ Version Control** â†’ the repository â†’ **Update from Remote** (pull). The tracked
   `.htaccess` is restored from the repository.
5. Verify `https://<domain>/` and `https://<domain>/health` still work, then delete the `.manual-backup` file.

**Terminal/SSH route:**
```bash
cd /home/<user>/repositories/<repo>     # path shown in Git Version Control
git stash push --include-untracked -- .htaccess storage/.htaccess
git pull --ff-only
git status                              # clean; tracked .htaccess present
# site keeps working because the tracked .htaccess has the same rules
```

**Why this is safe:** the tracked root `.htaccess` serves the app through `public/` and denies direct
access to app code, logs, docs, dotfiles and data files â€” identical behaviour to the Option-B file.
Never `git checkout -- .` blindly over unknown local edits; back up first.

**Prevention:** after this pull, `.htaccess`/`storage/.htaccess` are tracked, so future pulls update them
normally and this conflict cannot recur. If you later prefer a *real* document-root change to
`<repo>/public`, remove the root `.htaccess` deliberately via a commit (it is ignored outside the web root
anyway).
