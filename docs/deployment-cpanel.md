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
contains 232 entries, no `.env`, no `.tools/`, no logs. Upload it with cPanel *File Manager*
(Upload → Extract), or SFTP the repository contents.

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
                                          →  smoke /health
```

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

## 4. Verify the deployment

| Check | Expected |
|---|---|
| `https://dashpass.africa/health` | `200` JSON, `"status":"ok"`, `migrations.applied=5`, `pending=0`, `storage ok` |
| `https://dashpass.africa/` | welcome page (navy), CSS/JS 200 |
| `https://dashpass.africa/login` | phone + OTP screen |
| `https://dashpass.africa/manifest.json`, `/service-worker.js` | 200, correct MIME |
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
