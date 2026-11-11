# MtaaPass — cPanel deployment runbook (dashpass.africa)

Target: cPanel hosting for `dashpass.africa` (currently showing the cPanel default page).
Application: plain PHP 8.2+/8.3, PDO MySQL/MariaDB, no Composer/Node build step.

> **Secrets rule:** never commit `.env`. The real production values live only in a
> gitignored `.env.production.local` on the development machine (and in cPanel).
> The GitHub auto-sync watcher excludes `.env*`, `.tools/`, logs and private keys.

## 0. Preconditions (from the product owner)

- cPanel account for `dashpass.africa` (File Manager or SFTP; ideally Terminal/cron access).
- PHP 8.2 or 8.3 selectable in cPanel → *MultiPHP Manager* (PHP 8.2+ is required).
- MySQL database + user created in cPanel → *MySQL Databases*:
  - database: `<cpanel-user>_dashpass_db`
  - user: `<cpanel-user>_dashpass_db` (the product owner has supplied these; keep them out of git)
  - the user must be added to the database with **ALL PRIVILEGES**.
- DNS/Cloudflare: `dashpass.africa` currently resolves through Cloudflare. Keep the
  Cloudflare proxy **on**, but make sure HTML/PHP responses are not cached (see §6).

## 1. Upload layout (recommended: app outside the docroot)

Upload the app to `/home/<cpanel-user>/mtaapass/` (or any non-public folder), then point the
domain document root at the app's `public/` directory:

1. cPanel → *Domains* → `dashpass.africa` → **Document Root** → `/home/<cpanel-user>/mtaapass/public`.
2. Or use a subdirectory-safe layout: upload `public/*` into `public_html/` and the rest into
   `/home/<cpanel-user>/mtaapass/`, then edit `public/index.php` so it requires
   `/home/<cpanel-user>/mtaapass/app/bootstrap.php` instead of `dirname(__DIR__) . '/app/bootstrap.php'`.
   (Document root pointing at `public/` is cleaner and is what this runbook assumes.)

Expected tree on the server:

```
/home/<user>/mtaapass/
├── app/           config/       database/    public/   <- document root
├── routes/        scripts/      storage/     tests/    (tests optional on prod)
├── .env           (created on the server; never in git/zip)
└── .env.example
```

The deployment bundle `storage/temporary/mtaapass-deploy.zip` (created locally, gitignored)
is rebuilt by the release agent from the current working tree. Latest snapshot
(2026-11-11, source HEAD `045884b`): **~327 entries, ~0.8 MB compressed (~2.6 MB raw)** —
includes Increments 4–5 and Increment-6 phase 1 (notices). Counts grow with each
increment, so read the entry count printed by the rebuild, not this approximate figure.
It excludes `.git/`, `.tools/`, `.env`/`.env.*` (only `.env.example` with empty placeholders
ships), runtime storage (`logs|temporary|uploads|exports|cache`), test scratch files and any
`*.log`/`*.zip`. Upload it with cPanel *File Manager* (Upload → Extract), or SFTP/`git pull`
the repository contents.

> **Rebuild rule:** the zip is a point-in-time snapshot, not a streaming deploy. Rebuild it
> after every increment gate that lands routes/views/modules (in particular after the
> Increment-6 routes phase 2 and Increment-7 work) — see §3d and §1. Before uploading,
> verify the entry count/size and run the release secret screen.

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
SMS_DRIVER=log                    # no real SMS provider configured yet
SMS_DEV_EXPOSE_OTP=false          # never expose OTPs publicly
LOG_LEVEL=warning
```

Permissions: `.env` 600, `storage/` (and `storage/logs`, `storage/temporary`) writable by the
PHP user (typically 755 for dirs, 644 for files; use 775 only if the PHP user differs from your
cPanel user).

> If `APP_ENV=production` is combined with `SMS_DRIVER=log`, the app starts but login OTPs are
> written to `storage/logs/sms.log` instead of being sent — an operator-assisted pilot works;
> resident self-service does not. A real SMS adapter (e.g. Africa's Talking) must be configured
> before a public pilot.

## 2b. Branding (per-deployment product name)

The product name is configuration-driven, so the same codebase can run as
**MtaaPass** in development and **DashPASS** in production:

```dotenv
APP_NAME="DashPASS"          # titles, PWA manifest, install prompts, emails
APP_SHORT_NAME="DashPASS"    # compact home-screen name (defaults to APP_NAME)
APP_SUPPORT_EMAIL=support@dashpass.africa
```

`/manifest.json` is generated from these values (dynamic route, not a static
file), so no asset edits are needed per deployment. The development `.env`
keeps `APP_NAME="MtaaPass"`.

## 3. Run migrations + seed

cPanel → *Terminal* (or a cron “once” job / `php -r` wrapper):

```bash
cd /home/<user>/mtaapass
php -v                          # must be 8.2+
php scripts/migrate.php --status
php scripts/migrate.php         # applies 5 migrations
php scripts/seed.php            # demo estate + role catalogue (idempotent)
```

`install.php` is the shortcut (`migrate.php` + `seed.php`, idempotent, never drops the DB).

The seed creates the **demo Greenview Residences** estate, demo users and visitor fixtures.
Before onboarding real residents, purge/replace the demo rows (or add the estate-admin module
in the next increment and create the real estate there). The README lists demo accounts.

## 3b. cPanel Git™ Version Control (recommended deploy path)

The repository is public, so cPanel can clone it directly — no credentials needed:

1. cPanel → **Git™ Version Control** → **Create** → *Clone a Repository*.
   - Clone URL: `https://github.com/bodaboraapp-commits/dashpass.africa.git`
   - Repository Path: `/home/<cpanel-user>/repositories/dashpass.africa`
   - Branch: `main`
2. Set the domain document root to the cloned `public/` folder:
   cPanel → **Domains** → `dashpass.africa` → **Document Root** →
   `/home/<cpanel-user>/repositories/dashpass.africa/public`.
3. Create `.env` in the repository root (File Manager) from `.env.production.local` (§2).
4. Make `storage/` writable: `storage/logs`, `storage/temporary` (the app creates
   `storage/temporary/sessions` on first run).
5. First database install (no Terminal needed): call the sync URL in §3c with
   `action=install` — it applies the 5 migrations and seeds the demo estate.
6. Verify `/health` returns `migrations.applied=5`, `pending=0`.

Updates flow (every time the local app changes):

```
local auto-sync watcher  →  GitHub main  →  cPanel "Update from Remote" (git pull)
                                          →  cPanel "Deploy HEAD Commit"
                                          →  POST /deploy-sync.php?action=migrate
                                             (ONLY when the increment adds migrations — NOT for Inc-4/Inc-5)
                                          →  purge Cloudflare cache (changed CSS/JS/SW)
                                          →  smoke /health, /manifest.json, /service-worker.js, /admin
```

Increments 4 (estate/admin portal) and 5 (SMTP mail, PWA/greeting) add **no database
migrations**, so their redeploy needs only the git pull + deploy + cache purge — do not
run `action=migrate`. See §3d for the step-by-step redeploy checklist and §3c for the sync
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
- Serialised with a file lock; safe for a deploy hook. Never returns SQL, paths,
  credentials or stack traces. Rotate the token by editing `.env` and re-running
  the command; no code change needed.
- The production token is stored **only** in the gitignored `.env.production.local`
  on the development machine (and in the server's `.env`). It is never committed.

> **Pilot login mode:** the prepared `.env.production.local` uses `APP_ENV=staging`
> with `SMS_DEV_EXPOSE_OTP=true` so the labelled development-OTP panel works on the
> test site (no SMS provider yet). Switch to `APP_ENV=production` and
> `SMS_DEV_EXPOSE_OTP=false` before a real pilot with residents; at that point a
> real SMS provider adapter is required for login to work.

## 3d. What's new to redeploy (Inc-4 admin, Inc-5 SMTP/PWA/greeting, Inc-6 phase 1)

Since the last deployed snapshot the live app is missing:

* **Increment 4 — estate/admin portal**: `/admin` dashboard plus buildings, households
  (and members), residents, guards, gates and estate-settings screens;
  `layouts/admin.php` and `assets/css/admin.css`. Estate admins see their **active estate
  only**; forms are server-rendered (no JSON admin API).
* **Increment 5 — email + PWA/greeting**: `app/Integrations/Mail/*` (Resend HTTP API,
  Resend SMTP, log driver), `config/mail.php`, `EmailAlertService` alert hooks, hardened
  `ManifestController`, greeting on the `/app` home screen, service-worker **v4** cache
  bump, and `public/.htaccess` hardening.
* **Increment 6 phase 1 — notices**: notice create/publish/archive admin screen, guard and
  resident notice lists/detail. Later phase-2 routes/views are **not** in this snapshot —
  rebuild and redeploy again after that gate (see the §1 rebuild rule).

Redeploy steps:

1. **Pull** — cPanel → **Git™ Version Control** → the repository → **Update from Remote**.
   If the pull stops on `.htaccess`, resolve it per §12 (rename the manual file to
   `.htaccess.manual-backup`, keep the tracked `.htaccess` and `public/.htaccess`; do not
   delete `public/.htaccess`).
2. **Deploy HEAD Commit** — writes the deploy marker only; the domain serves `public/`
   directly from the clone.
3. **No database sync for Inc-4/Inc-5** — neither increment adds migrations; `/health`
   should stay `pending=0` with `migrations.applied=6`. Do **not** call
   `deploy-sync.php?action=migrate` for this redeploy. Future migration-bearing increments
   must run it (§3b/§3c).
4. **Mail configuration (server `.env` only)** — set the values from
   `.env.production.local` on the server: `MAIL_DRIVER=smtp` (or `resend`),
   `MAIL_FROM_ADDRESS`, `MAIL_SMTP_HOST|PORT|USERNAME|PASSWORD` or `RESEND_API_KEY`.
   Never commit these; `.env.example` keeps them empty. If the host caches environment at
   PHP process start, restart the site's PHP pool. Optionally verify with
   `php scripts/mail-test.php --check` and `php scripts/mail-test.php --to=<address>`.
5. **Cache purge** — cPanel → Cloudflare → **Purge Everything** (new `admin.css`,
   `app.js` and service worker). Existing clients pick up service-worker v4 on next load;
   old caches are deleted on activate. Hard-refresh the admin pages once for CSS.
6. **Verify** (full table in §4):
   - `GET /health` → `200`, `migrations.applied=6`, `pending=0`.
   - `GET /manifest.json` → `200`, `Content-Type: application/manifest+json`, DashPASS
     name/short-name and 192/512 icons.
   - `GET /service-worker.js` → `200`, `Content-Type: application/javascript`,
     body contains `VERSION = 'v4'`, and the file itself is not cached
     (`Cache-Control: no-cache`/`must-revalidate`).
   - `GET /admin` logged out → login redirect. Log in as **Grace Wanjiku
     `+254700000001`** (`estate_admin`) → `/admin` dashboard `200` with real stats;
     Buildings, Households, Residents, Guards, Gates and Settings render without errors
     and no demo/preview rows. Then **David Njoroge `+254700000014`** → Riverside only
     (cross-tenant check).
   - **Mobile install self-check** (Chrome Android, or desktop DevTools → Application):
     HTTPS, install prompt/“Add to Home screen” available, manifest parses with no errors,
     icons render, standalone launch works, offline shell loads, and
     *Application → Service Workers* shows the **v4** worker activated.
   - `.env`/dotfiles denial: `/.env` → `404`; `/storage/logs/` → `403/404`, never a listing.
7. **Rebuild the bundle** after the next route/DB-changing gate (Inc-6 phase 2, Inc-7) —
   see the §1 rebuild rule.

## 4. Verify the deployment

| Check | Expected |
|---|---|
| `https://dashpass.africa/health` | `200` JSON, `"status":"ok"`, `migrations.applied=6`, `pending=0`, `storage ok` |
| `https://dashpass.africa/` | welcome page (navy), CSS/JS 200 |
| `https://dashpass.africa/login` | phone + OTP screen |
| `https://dashpass.africa/manifest.json` | `200`, `application/manifest+json`, DashPASS name/icons |
| `https://dashpass.africa/service-worker.js` | `200`, `application/javascript`, `VERSION = 'v4'`, not cached |
| `https://dashpass.africa/admin` (logged out) | `302` to `/login`; after login as Grace → dashboard `200` |
| `https://dashpass.africa/app` (logged out) | `302` to `/login` |
| OTP delivery (pilot) | operator reads the code from `storage/logs/sms.log` on the server |
| Guard flow | log in as a guard → `/gate` → verify a PIN |

Quick CLI health check:

```bash
cd /home/<user>/mtaapass && php -r 'require "app/bootstrap.php"; echo "bootstrap ok\n";'
```

## 5. Apache / PHP settings

- `public/.htaccess` is included: `Options -Indexes`, front-controller rewrite, dotfile denial.
  `mod_rewrite` is enabled by default on cPanel.
- Set `display_errors=Off` and `log_errors=On` for production in cPanel → *MultiPHP INI Editor*.
- The app writes sessions to `storage/temporary/sessions` (outside the web root); make sure it is
  writable. No system temp dependency.
- SSL: cPanel *AutoSSL* and/or Cloudflare Full (strict) must be active — `APP_URL` and cookies
  assume HTTPS.

## 6. Cloudflare

- Keep the proxy on for TLS/HTTP2. Cloudflare does **not** proxy MySQL, so the app must run on
  this cPanel server where the database is local.
- Do not cache HTML/PHP or `/app`, `/api/*`, `/login`, `/health`. If a “Cache Everything” rule
  exists, add exceptions (or a Cache Rule: bypass for those paths). Purge cache after deploys
  so the old service-worker/assets are not served.
- The ngrok tunnel used in development is **not** part of production; `APP_URL` must be the
  public domain.

## 7. Cron jobs (later increments)

Not required for this deployment. When the scheduler increment lands:
`*/1 * * * * php /home/<user>/mtaapass/scripts/expire-invitations.php` and the queue worker.

## 8. Rollback

1. Keep the previous release directory (`mtaapass_2026-11-11`) and switch the document root back.
2. Database: take a phpMyAdmin export before each migration; restore that dump if a migration
   must be rolled back (migrations are forward-only by design).
3. `APP_KEY` must stay stable — changing it invalidates encrypted QR tokens and sessions.

## 10. Troubleshooting

### "/" shows `/cgi-sys/defaultwebpage.cgi` (cPanel "Default Web Site Page")

This is cPanel's **placeholder page**, served when the domain's document root exists but contains
**no index file** (typically an empty `public_html`). It is not the application, not an application
error and not a Cloudflare cache problem (`cf-cache-status: DYNAMIC`, verified with cache-busting
queries). The app files have not been deployed to the folder the domain serves, so there is nothing
for Apache to run. Fix in this order:

1. cPanel → **File Manager** → open the domain's Document Root (usually `public_html`) — it should
   contain `index.php`, `assets/`, `manifest.json`, etc. If it is empty/default, nothing is deployed.
2. Deploy the files (Git Version Control clone or bundle upload) **first**, then point the document
   root at the application's `public/` folder (see §3b). Creating the docroot before the clone leaves
   Apache serving a missing path.
3. Verify `https://dashpass.africa/index.php` is no longer a cPanel 404 and
   `https://dashpass.africa/health` returns JSON.
4. Purge Cloudflare (**Caching → Purge Everything**) if the old default page lingers.
5. `.env` is irrelevant until step 3 succeeds — PHP cannot read a configuration file Apache is not
   serving.

### "404 Not Found — cp_errordocument.shtml" (the reported symptom)

This is **cPanel/Apache's own error page**, not an application error. It means Apache could not
find a file at the configured document root. Cases:

1. **The repository has not been cloned in cPanel yet** → the document root
   (`…/repositories/dashpass.africa/public`) does not exist. Do step §3b.1 first.
2. **Document root is wrong** (points at the repository root instead of its `public/` folder, or at
   `public_html`, or has a typo). Expected value: `/home/<cpanel-user>/repositories/dashpass.africa/public`.
3. **Document root still `public_html` and `public_html` is empty** → `/` shows the cPanel default
   page and other paths show this 404. None of the app files are being served.
4. **Deployed but only `public/` was uploaded** without `app/`, `config/`, `database/`, `routes/`,
   `scripts/` one level above → PHP cannot find `../app/bootstrap.php` (usually a 500, but a missing
   `index.php` in the docroot also gives a 404).

Verify from a terminal/browser:

```bash
curl -sI https://dashpass.africa/ | head -5
curl -s  https://dashpass.africa/health          # after deploy: 200 JSON
```

If `/health` is 404 while `/index.php` is also 404, the app files are simply not under the document
root yet. Fix the document root or upload the bundle (`storage/temporary/mtaapass-deploy.zip`,
built locally, no secrets) via cPanel File Manager → Extract.

### Other common failures

| Symptom | Likely cause / fix |
|---|---|
| 500 on every page | `storage/` not writable, `.env` missing/invalid, PHP < 8.2, or app files missing one level above `public/`. Check cPanel error log + `storage/logs/app-*.log`. |
| `Database connection failed` | Wrong DB name/user in `.env`; user not attached to DB; host must be `localhost`. |
| `/deploy-sync.php` → 404 | Endpoint not deployed yet or document root wrong; or `DEPLOY_SYNC_TOKEN` unset (it answers 404 by design). |
| `/deploy-sync.php` → 403 | Token mismatch; copy the exact value from `.env`. |
| `/health` shows `pending > 0` | Call the sync URL with `action=migrate`. |
| Old page/404 still shown in browser | Cloudflare cache: cPanel → Cloudflare → Purge Everything, or append `?v=now`. |
| `.env` downloadable over HTTP | Document root must be `public/`, not the repository root; `.htaccess` also denies dotfiles. |

## 11. Security checklist before a real pilot

- [ ] `.env` not reachable via HTTP (`/.env` → 404) and not in git (`git log --all -- .env` empty).
- [ ] `APP_DEBUG=false`, `UI_PREVIEW_CONTENT=false`.
- [ ] Real SMS provider configured (or operator-assisted pilot explicitly agreed).
- [ ] Demo data removed/replaced before real residents are onboarded.
- [ ] Daily cPanel backups enabled for files + database.
- [ ] Cloudflare WAF/rate limiting considered for `/login` and `/v/*`.
- [ ] Guard devices locked down; supervisor reconcile for walk-ins/offline (later increment).

## 12. Known live-deployment finding (dashpass.africa, 2026-11-11)

**Symptom:** `https://dashpass.africa/deploy-sync.php` → web-server 404, while
`https://dashpass.africa/public/deploy-sync.php` works; `https://dashpass.africa/` shows a directory
listing (`Index of /`) with `app/ config/ database/ docs/ public/ routes/ scripts/ storage/ tests/`,
and `https://dashpass.africa/storage/logs/app-*.log` is downloadable.

**Cause:** the domain document root is the repository root (`public_html`), not the application's
`public/` directory. The app therefore runs at the `/public/...` URL prefix, its router sees the
wrong request path (so `/public/health` returns the app's own 404), and every non-public repository
file is in the web root (directory listings + log download = information disclosure).

**Fix (required):** cPanel → **Domains** → `dashpass.africa` → **Document Root** =
`/home/<cpanel-user>/<app-root>/public` (for a repository clone: `…/repositories/dashpass.africa/public`;
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
logs, docs, dotfiles and data files. Defence-in-depth only — the document-root change remains the
correct fix.

**Also required after fixing:** delete the previously exposed logs under `storage/logs/` on the
server (they are gitignored and regenerated), purge Cloudflare cache, and re-deploy the latest code
(the previously uploaded bundle was a pre-Increment-3 snapshot: the live DB showed 5 of 6
migrations). Rebuild and upload the current `mtaapass-deploy.zip` (Inc-4/5 + Inc-6 phase 1) using
the §3d checklist.

### Git pull fails: "untracked working tree files would be overwritten by merge: .htaccess"

This happens when the Option-B fallback `.htaccess` was created manually in the repository working tree,
and a later commit (the repo now tracks the root `.htaccess` and `storage/.htaccess`) tries to bring the
tracked version in. The manual and tracked files contain the same routing/security rules, so the tracked
version should win. Resolve it without losing the site:

**File Manager route (simplest):**
1. cPanel → **File Manager** → open the repository path shown in **Git™ Version Control** (usually
   `/home/<user>/repositories/<repo>`; for a `public_html` clone use `/home/<user>/public_html`).
2. Rename `.htaccess` → `.htaccess.manual-backup` (do NOT delete `public/.htaccess`).
3. If the same error mentions `storage/.htaccess`, rename that one too.
4. cPanel → **Git™ Version Control** → the repository → **Update from Remote** (pull). The tracked
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
access to app code, logs, docs, dotfiles and data files — identical behaviour to the Option-B file.
Never `git checkout -- .` blindly over unknown local edits; back up first.

**Prevention:** after this pull, `.htaccess`/`storage/.htaccess` are tracked, so future pulls update them
normally and this conflict cannot recur. If you later prefer a *real* document-root change to
`<repo>/public`, remove the root `.htaccess` deliberately via a commit (it is ignored outside the web root
anyway).
