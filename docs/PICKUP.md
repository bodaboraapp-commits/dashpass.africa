# MtaaPass / DashPASS — Pickup memory (resumed session)

**Resumed at:** 2026-11-11 21:52 EAT (Africa/Nairobi) · **Status:** RESUMED — no stale agent processes;
**Firebase filter is GREEN (34/34 PASS)**; the exclusive full suite is running now
(`tests/_resume-full.log`, 0 failures so far); `.rnd` artifact removed from tracking and gitignored;
test lock held by the running suite.
**Repo:** `C:\xampp\htdocs\www\MtaaPASS` · **Dev:** http://127.0.0.1:8010 · **Live:** https://dashpass.africa

**Remaining sequence:** Firebase gate → Inc-10 DB/route gates → T111 (billing UI gate) →
T112 (billing tests) → T113 (Inc-10 bundle/docs) → live activation. See §3.

This file is the single source of truth for resuming. Read it fully before starting any task.

---

## 1. Where the product stands

### Live production (verified 2026-11-11 20:19)
- `/health`: `version 0.2.1-dashpass`, `environment production`, **applied=8, pending=0**.
- Assets current: `app.js` 59,566 B, `app.css` 138,526 B, manifest hardened (DashPASS, theme `#070B16`, 2 shortcuts, 3 icons).
- **Cloudflare `/service-worker.js` bypass confirmed working:** `cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`, `Service-Worker-Allowed: /`; SW v4.
- Deployed code = **Inc-9 build** (158 routes; Inc-6/7/8/9 pages return 302→login; `.env`/`.git`/logs 403, code dirs 404, CSP/HSTS present).
- **BLOCKER: live login is disabled by design** — production + `SMS_DRIVER=log` → `SmsManager` fail-closed → `/login/otp` returns 503 “Sign-in is temporarily unavailable.” Live `/login` is the **pre-Firebase** build (no `data-firebase-auth`) because the Firebase code has not been deployed yet. → Until Firebase is deployed + configured (or a real SMS gateway is added), **nobody can sign in live, including super admin**.

### Dev environment
- Routes **158** (web 114 + API 44). DB: **8 migrations / 37 tables / 52 permissions / 13 roles / 217 role links**.
- Dev DB has QA residue right now (e.g. staff 6, incidents 10, notices 9). Reset with the hardened tool when needed:
  `$env:ALLOW_DEV_RESET='1'; php scripts/dev-reset.php --dry-run` then `--yes --force` (or `--force` + env; see tool `--help`). Default path = DROP/CREATE database (safe; fixes stale InnoDB FK dictionary).
- Local `.env` / `.env.production.local` now default to **Firebase OTP**: `AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true`, project `komiuta`, public config incl. **appId `1:99186909972:web:ad3900fddee22fe6b91801`**, **measurementId `G-D9JVCQJG9V`** (SDK **13.2.0 modular**). `.env.example` remains placeholders (`AUTH_OTP_PROVIDER=server`).
- Dev `/login` is in **Firebase mode** (SDK 13.2.0 module, no dev-OTP panel). For a quick dev demo without Firebase console setup, set local `.env` `AUTH_OTP_PROVIDER=server` (one line, no restart) → classic OTP + dev panel.

### Accepted increments (closed)
- **Inc-1…Inc-9 all ACCEPTED.** Last full green **before** the Firebase fold: **676/676 PASS, 0 fail,
  1253s** (`tests/_final-full.log`; 172 unit / 438 integration / 66 security, 80 files) — the
  **resumed full suite is running now** (`tests/_resume-full.log`) and becomes the new baseline when
  it finishes and is recorded in the Firebase gate.
- Acceptance docs: `docs/increment-{3,4,5,6,7,8,9}-acceptance.md` (Inc-8/Inc-9 finalised 2026-11-11).
- Board tasks completed: T59 (Inc-7), T63 (Inc-8), T67 (Inc-9); T130/T133 (Firebase docs) done;
  Inc-8/9 gate evidence recorded.

---

## 2. Work paused mid-flight (resume points)

### A. Firebase OTP — code done, **tests FIXED/GREEN**, full suite running (HIGHEST PRIORITY to resume)
- **Landed:** server `config/firebase.php` + `FirebaseTokenVerifier` (Google public certs, RS256, iss/aud/exp/iat/auth_time/phone_number, cert cache, fail-closed 503; **no service-account JSON**) integrated into the existing `POST /login/verify` (no new routes); login UI + `public/assets/js/firebase-auth.js` (**SDK 13.2.0 modular, ESM imports from gstatic**, external `type="module"`, reCAPTCHA, posts hidden `firebase_token`, analytics intentionally omitted); CSP extended in `app/Middleware/SecurityHeaders.php`; docs `docs/firebase-sms-otp.md` rewritten (T130) and refreshed for the new appId/measurement + SDK 13.2.0 (T133).
- **Tests: GREEN.** The RSA-key fixture failure was fixed with a static test-only PEM fixture;
  `php tests/run.php --filter=Firebase` → **34/34 PASS**. Legacy `--filter=WebFlow`/`Auth` fallback
  covered in the running full suite.
- **Full suite running now:** `tests/_resume-full.log` (exclusive lock held; 0 FAIL so far). When it
  finishes: record the count/exit here + in the Firebase gate, then proceed to the Firebase gate (§3 step 2).

### B. Inc-10 Billing — fully staged, gates not opened
- Staging (gitignored): `storage/temporary/inc10/` — `20261111_000010_create_billing_tables.sql` (order fixed: **ALTER subscriptions unique index → billing_invoices → billing_payments**), `DemoBillingSeeder.php`, `run.php.diff`, `GATE-RUNBOOK.md`, validator **136/0**, manifest drift 0.
- Route patch: `storage/temporary/gates/inc10-routes.patch` → **165 routes** (web 121 + API 44), 7 platform-billing routes, `['auth']` only; applies cleanly to the 158 tree.
- Proofs staged: `t77_verify.php` (billing service/CLI/frozen keys; self-skips pre-migration), `t98_verify.php` (row-level numeric bounds), `t64_verify.php` (platform); gate readiness printer `inc9_inc10_gate_status.php`.
- E2E: `storage/temporary/inc10-e2e.js` (~85 checks) implemented + readiness-gated (refuses until 165/9/39/2/1).
- Tests parked: `storage/temporary/pending-tests/Billing{Invoice,Payment,PastDueJob}Test.php` (25) + `BILLING-FOLD-CHECKLIST.md` (pre-verified, no deltas).
- Billing UI: 3 platform views aligned to frozen keys; **Billing nav/quick-link intentionally hidden behind flags** `$billingNavEnabled=false` / `$billingLinkEnabled=false` in `layouts/platform.php` / `platform/dashboard.php` — flip both at the gate (T111).
- Expected at gate: **9 migrations / 39 tables / 52 perms / 217 links / 2 invoices + 1 payment**, deterministic seed, no-op second run; cleanroom same.

### C. Other queued/ready
- `docs/db-integrity-check.md` + `scripts/db-integrity-check.php` (26 read-only checks; pre-Inc-10 expected 8/37/52/13/217/64 FK/5 settings). Run after every deploy.
- `docs/ui-acceptance-checklist.md`, `docs/changelog.md` (Inc-10 marked unreleased), `docs/deploy-now-inc6-inc7.md` (now Inc-6→Inc-9 packet), deployment/rollback docs reconciled with verified live state.
- Inc-11 brief: `docs/increment-11-brief.md` (estate billing read + reports/exports; 12 open decisions for Lead).

---

## 3. Resume plan (do in this order — step 1 done)

1. ~~**Fix Firebase test fold**~~ — **DONE**: static RSA/cert test fixture → `--filter=Firebase` **34/34 green**; `tests/_resume-full.log` is the in-flight full suite (0 FAIL so far). When it finishes, record count/exit here + Firebase gate.
2. **Firebase gate**: owner/console + deploy:
   - Firebase console project `komiuta`: enable **Phone** sign-in; authorized domains `dashpass.africa`, `www.dashpass.africa`, `brim-liberty-liqueur.ngrok-free.dev`, `localhost`, `127.0.0.1`; reCAPTCHA Enterprise/v3; optional test numbers (e.g. `+254700000020` / `123456`); restrict public API key by referrers.
   - Deploy the Firebase commit (no migration), add Firebase vars + `AUTH_OTP_PROVIDER=firebase` to **server** `.env`, keep `APP_ENV=production`, run `deploy-sync install` (idempotent; `/health` stays applied=8), verify one real OTP login on HTTPS, then authenticated smoke.
3. **Inc-10 DB gate** (T109): copy migration+seeder, apply `run.php.diff`, `php -l`, dev `install.php` → applied 1 of 9 + invoices 2/payments 1, second run no-op, 9/9, health applied=9, integrity checker post-Inc-10 branch (9/39/52/13/217/72 FK/5 + 2/1), flocked cleanroom.
4. **Inc-10 route gate** (T110): apply `inc10-routes.patch` → 165, run `t77_verify.php` + `t98_verify.php`, report.
5. **Billing UI gate** (T111): flip `$billingNavEnabled`/`$billingLinkEnabled` true; re-run T65/T78/T105/T122 harnesses; smoke `/platform/billing*` as Daniel (+403 estate users, 302 unauth).
6. **Billing tests** (T112 → tests): fold parked 25 Billing tests, `--filter=Billing`, then final full suite (billing + Firebase baseline).
7. **Release bundle/docs** (T113): rebuild `storage/temporary/mtaapass-deploy.zip`, full secret screen, update deployment docs/changelog/acceptance, record pin-able commit; owner deploys `applied=9`.
8. **Close-out**: final live matrix + authenticated smoke; close remaining tasks.

---

## 4. Owner-pending actions (do not lose)

1. **Apply rotated `DEPLOY_SYNC_TOKEN` on the server** — token was shared in chat and rotated locally (new 48-char value in `storage/temporary/deploy-token-new.txt`, gitignored). Server `.env` still has the OLD value → local deploy-sync calls will 403 until updated. Steps: cPanel Terminal `cp -a .env ".env.bak-$(date +%Y%m%d-%H%M%S)"`, hidden `read -rs`, `sed` replace `DEPLOY_SYNC_TOKEN=`, `chmod 600`, verify `?action=status` 200 + old token 403. Scripted helper (after server pull): `php scripts/rotate-deploy-token.php --from-file=.deploy-token-new` (docs: `deployment-rollback.md` §6a).
2. **Firebase console setup** (see 2A gate step) — this is the only thing blocking live login.
3. **Deploy Firebase code + server env** (no migration) then verify real OTP login.
4. Later: **Inc-10 deploy** (`deploy-sync install` → applied=9 / 39 tables / 217 links / 2 invoices + 1 payment), smoke billing.
5. Public-launch hygiene: demo-data cleanup decision, keep `APP_KEY` stable, monitor SMS/reCAPTCHA quota.

---

## 5. Secrets & hygiene
- `.env`, `.env.production.local`, `storage/temporary/**` are **gitignored** — never commit or paste token values.
- The old deploy token (`c85698…`, leaked in chat) is dead once the server is updated; new token lives only in local env + `storage/temporary/deploy-token-new.txt` (delete the handoff file after use).
- Firebase web config is **public client config** (safe); no service-account JSON exists or is needed. Do not add private keys.
- API key restriction: Google Cloud → Credentials → restrict by HTTP referrers.

---

## 6. Command cheat-sheet
```powershell
# status
php scripts/migrate.php --status
php scripts/db-integrity-check.php            # add -d display_startup_errors=0 for clean JSON
php -l <file>                                  # lint (openssl warning is noise)

# tests (single exclusive runner; lock = storage/temporary/mtaapass-test.lock)
php tests/run.php
php tests/run.php --filter=Firebase
$env:TEST_LOCK_TIMEOUT='0'; php tests/run.php   # fail-fast if another run holds the lock

# dev DB reset (hardened, DROP/CREATE default)
$env:ALLOW_DEV_RESET='1'; php scripts/dev-reset.php --dry-run
$env:ALLOW_DEV_RESET='1'; php scripts/dev-reset.php --yes --force

# dev server (if not running)
C:\xampp\php\php.exe -S 127.0.0.1:8010 -t public public\index.php

# CLIs
php scripts/parking-overstay.php --dry-run
php scripts/billing-past-due.php --dry-run
php scripts/mail-test.php --check

# live deploy-sync (needs NEW token; via header or ?token=)
POST https://dashpass.africa/deploy-sync.php?action=status|install   (header X-Deploy-Token)
Get-Content storage\temporary\deploy-token-new.txt   # new token (do not print in chat)
```

---

## 7. Key file map
- Contracts: `docs/*-contract.md` (foundation, estate-notices, parking-administration, platform-admin, billing-invoices, staff-incidents, email-pwa-greeting…)
- Acceptance: `docs/increment-8-acceptance.md`, `docs/increment-9-acceptance.md` (ACCEPTED)
- Owner: `docs/deploy-now-inc6-inc7.md` (Inc-6→Inc-9 packet), `docs/deployment-cpanel.md`, `docs/deployment-rollback.md`, `docs/db-integrity-check.md`, `docs/ui-acceptance-checklist.md`, `docs/changelog.md`, `docs/firebase-sms-otp.md`, `docs/database-schema.md`
- Gate staging: `storage/temporary/inc10/`, `storage/temporary/gates/` (`GATES.md`, `inc10-routes.patch`), `storage/temporary/pending-tests/`
- Proof scripts: `storage/temporary/t64_verify.php`, `t77_verify.php`, `t98_verify.php`, `inc9_inc10_gate_status.php`
- E2E: `storage/temporary/{e2e-common,inc8-e2e,inc9-e2e,inc10-e2e}.js` + reports
- Live evidence: `storage/temporary/live-final-verification.{md,json}`, `live-post-purge-*`
- Release bundle: `storage/temporary/mtaapass-deploy.zip` (Inc-9: 392 entries, sha256 `8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`), pin `be9b410` — **Inc-10 refresh (T113) is queued/prepared**; checklist in `docs/git-release-process.md` §4b (Inc-10 bundle refresh).

---

## 8. Known issues / gotchas
- **Live login blocked** (production + `SMS_DRIVER=log`, pre-Firebase build) — see §2A gate; Firebase code is dev-ready but not yet deployed.
- **Local deploy-sync token ≠ server token** until owner applies rotation (403 `Forbidden.` is token mismatch, not a code bug).
- **Firebase tests are GREEN (34/34)**; the RSA-key fixture issue is fixed with a static test-only PEM. Legacy server-OTP fallback is covered by the running full suite.
- Billing nav hidden until Inc-10 gate (flags false) — intentional, `/platform/billing` 404 pre-gate.
- Inc-10 migration must keep the fixed order (ALTER → invoices → payments); `GATES.md`/runbook record it.
- Shared test DB: only ONE runner at a time (stable lock). Full suites are exclusive; filtered runs are short.
- `.rnd` OpenSSL artifact at repo root is **removed from tracking and gitignored** — never commit it; if it reappears it is local noise only.
- Git watcher (`scripts/git-autopush.ps1`, pid ~1444) auto-commits/pushes working-tree changes every ~25–60s; check `git log/status` and pull before editing if resuming on another machine.
- XAMPP PHP prints `Module "openssl" is already loaded` on every call — harmless.

---

## 9. Resuming on a fresh machine/session (quick start)
1. `git pull` (the watcher may have committed WIP files — review `git log --oneline -5` and `git status`).
2. Start dev server + verify `http://127.0.0.1:8010/health` → applied=8.
3. Firebase tests are already green (34/34); check whether the resume full suite finished (`tests/_resume-full.log`) and record its count.
4. Continue §3 in order (step 1 done → Firebase gate).
5. Read this file's §4 before touching live.
