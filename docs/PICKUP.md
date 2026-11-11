# MtaaPass / DashPASS — Pickup memory (resumed session)

**Resumed at:** 2026-11-11 21:52 EAT (Africa/Nairobi) · **Status:** RESUMED — no stale agent processes;
**Firebase filter GREEN (34/34 PASS)**; exclusive full suite **710/710 PASS, 0 FAIL**
(`tests/_resume-full.log`, 1769.53s — new Firebase+Inc-9 baseline, superseding the pre-Firebase
676/676); `.rnd` artifact removed from tracking and gitignored; test lock now free.
**Repo:** `C:\xampp\htdocs\www\MtaaPASS` · **Dev:** http://127.0.0.1:8010 · **Live:** https://dashpass.africa

**Remaining sequence:** Inc-10 **DB + route gates PASSED** (9 migrations / 39 tables / 52 perms /
13 roles / 217 links / 72 FKs / 2 invoices + 1 payment; **165 routes**; t77 55/55, t98 24/24) →
billing UI (T111) + billing-test fold (T112) + qa-e2e reports → **final full suite** →
**T113 bundle** (Lead “bundle go”: rebuild + secret screen + pin) → owner deploy → close-out.
Firebase live activation is separately pending (half-activated; see §1). See §3.

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
- **Local dev env is now `AUTH_OTP_PROVIDER=server`** (classic OTP + dev panel) so `/platform` can be tested locally via dev-OTP login; the local `.env` still holds the public Firebase config. **Production target remains Firebase**: `.env.production.local` (and the server `.env` once activated) uses `AUTH_OTP_PROVIDER=firebase` + `FIREBASE_SMS_ENABLED=true`, SDK **13.2.0 modular**, public config **appId `1:99186909972:web:ad3900fddee22fe6b91801`** / **measurementId `G-D9JVCQJG9V`**; `.env.example` remains placeholders (`AUTH_OTP_PROVIDER=server`). **One-line revert to Firebase locally:** set `AUTH_OTP_PROVIDER=firebase` (+ `FIREBASE_SMS_ENABLED=true`) in local `.env`, save, reload — no restart needed (env is read per request).
- **Live Firebase state (2026-11-11) — HALF-ACTIVATED, login locked out:** `AUTH_OTP_PROVIDER=firebase` **is active** server-side (T141 audit: API `request-otp`/`verify-otp` `{}` → **403 `AUTH_PROVIDER_MISMATCH`**, T138 code live), but `FIREBASE_SMS_ENABLED` + public `FIREBASE_*` are **not active in the server `.env`** → `/login` still renders the legacy UI, so the owner sees **“Code sign-in is not available. Verify your phone number to continue.”** and **no one can sign in live**. Deployed fingerprint `c6ecee9` (22:04; PHP/auth current); HEAD `ebe607e` only adds a newer `firebase-auth.js` (pin it when deploying; static `?v=` + 4 h `max-age=14400` → CF purge + SW hard refresh). **Unblock order: `docs/firebase-sms-otp.md` §1b** — keep provider=firebase, set `FIREBASE_SMS_ENABLED=true` + all public `FIREBASE_*` (from local `.env.production.local`), reload PHP-FPM/opcache, deploy `ebe607e`+ (**no migration**; `/health` stays 8/0), CF Purge Everything, unregister SW/hard-refresh, re-probe `activationDetected=true`, test-number/real OTP → `/app`, `/platform` 200 as Daniel, audit `auth.firebase_login`. Immediate unblock: temporarily `AUTH_OTP_PROVIDER=server` + reload. `/platform` unauth 302 and `/platform/billing` 404 (Inc-10 gate) are expected.

### Accepted increments (closed)
- **Inc-1…Inc-9 all ACCEPTED.** **Current approved baseline: 710/710 PASS, 0 fail** (Firebase +
  Inc-9; `tests/_resume-full.log`, 1769.53s), superseding the pre-Firebase **676/676** at the Inc-9
  close (`tests/_final-full.log`; 172 unit / 438 integration / 66 security, 80 files). Both are
  valid references — use **710** as the current bundle/docs baseline.
- Acceptance docs: `docs/increment-{3,4,5,6,7,8,9}-acceptance.md` (Inc-8/Inc-9 finalised 2026-11-11).
- Board tasks completed: T59 (Inc-7), T63 (Inc-8), T67 (Inc-9); T130/T133/T146 (Firebase docs) done;
  Inc-8/9 gate evidence recorded.

---

## 2. Work paused mid-flight (resume points)

### A. Firebase OTP — code done, **tests FIXED/GREEN (34/34)**, full suite **710/710 PASS** (HIGHEST PRIORITY to resume)
- **Landed:** server `config/firebase.php` + `FirebaseTokenVerifier` (Google public certs, RS256, iss/aud/exp/iat/auth_time/phone_number, cert cache, fail-closed 503; **no service-account JSON**) integrated into the existing `POST /login/verify` (no new routes); login UI + `public/assets/js/firebase-auth.js` (**SDK 13.2.0 modular, ESM imports from gstatic**, external `type="module"`, reCAPTCHA, posts hidden `firebase_token`, analytics intentionally omitted); CSP extended in `app/Middleware/SecurityHeaders.php`; docs `docs/firebase-sms-otp.md` rewritten (T130) and refreshed for the new appId/measurement + SDK 13.2.0 (T133/T146).
- **Tests: GREEN.** The RSA-key fixture failure was fixed with a static test-only PEM fixture;
  `php tests/run.php --filter=Firebase` → **34/34 PASS**. The exclusive full suite then passed
  **710/710 (0 fail, 1769.53s, `tests/_resume-full.log`)** — new Firebase+Inc-9 baseline.
- **Live is HALF-ACTIVATED** (provider=firebase active, web config missing → legacy `/login`; see
  the live-state bullet in §1 + unblock order in `docs/firebase-sms-otp.md` §1b). Next after
  activation: post-activation probe (`activationDetected=true`) + one OTP login.

### B. Inc-10 Billing — **GATES PASSED**, awaiting final suite + bundle
- **DB gate PASSED:** migration 10 on disk + applied on dev (`/health` **applied=9 / pending=0**);
  gate report: **9 migrations / 39 tables / 52 permissions / 13 roles / 217 links / 72 FKs /
  2 invoices + 1 payment**; integrity **26/26**, cleanroom **28/28**; demo seed `DemoBillingSeeder`
  (paid `INV-202610-0001` + confirmed `DEMO-MPESA-001` ledger payment, current-month open invoice,
  Greenview only; idempotent).
- **Route gate PASSED:** source routes **165** (web 121 + API 44) = 158 + 7 `/platform/billing*`
  routes (`['auth']` + controller platform-area gate + `billing.invoice.view`/`payment.manage`; no
  APIs). Gate readiness `storage/temporary/inc10-gate-readiness.json` → `ready=true` (Daniel 200,
  Grace 403, unauth 302, seeded invoices/ledger visible, nav+quick-link flipped).
- **Backend proofs:** `t77_verify.php` **55/55 PASS** (numbering/status machine/partial-payments/
  auto-paid/refunds/terminal 409s/past-due CLI dry→apply→idempotent/frozen keys/no hard deletes/
  PII-free audit — `t77_out.txt`); `t98_verify.php` **24/24 PASS** (numeric bounds — `t98_out.txt`).
- **Billing UI (T111):** nav/quick-link flags are `true` in the tree; formal pwa-shell smoke report
  pending. **Billing tests (T112):** 25 tests in `storage/temporary/pending-tests/Billing*Test.php`
  — fold pending. **E2E:** `inc10-e2e.js` (~85 checks) readiness-gated; no result yet.
- **Remaining:** final suite → T113 bundle rebuild + secret screen + pin-able commit (Lead “bundle
  go”) → Inc-10 deploy (`apply=9`, routes 165). No deploy yet; live stays applied=8.
- **Acceptance doc:** `docs/increment-10-acceptance.md` (GATED — pending final suite).

### C. Other queued/ready
- **T138 auth-provider enforcement + billing hardening (landed in dev, docs updated):** with
  `AUTH_OTP_PROVIDER=firebase` the legacy server-OTP endpoints are closed (API → **403
  `AUTH_PROVIDER_MISMATCH`**, web `/login/otp` generic redirect, `/login/verify` requires
  `firebase_token`; guards pre-validation/rate-limit, audit reason-only); dev-only
  `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` (default false, never production). Billing hardening:
  locked invoice-number read + 1062-only retry, `save_draft` boolean, CLI empty `--estate` exit 2.
- `docs/db-integrity-check.md` + `scripts/db-integrity-check.php` (26 read-only checks; pre-Inc-10 expected 8/37/52/13/217/64 FK/5 settings). Run after every deploy.
- `docs/ui-acceptance-checklist.md`, `docs/changelog.md` (Inc-10 marked unreleased), `docs/deploy-now-inc6-inc7.md` (now Inc-6→Inc-9 packet), deployment/rollback docs reconciled with verified live state.
- Inc-11 brief: `docs/increment-11-brief.md` (estate billing read + reports/exports; 12 open decisions for Lead).

---

## 3. Resume plan (do in this order — step 1 done)

1. ~~**Fix Firebase test fold**~~ — **DONE**: static RSA/cert test fixture → `--filter=Firebase` **34/34 green**; `tests/_resume-full.log` is the in-flight full suite (0 FAIL so far). When it finishes, record count/exit here + Firebase gate.
2. **Firebase gate**: owner/console + deploy:
   - Firebase console project `komiuta`: enable **Phone** sign-in; authorized domains `dashpass.africa`, `www.dashpass.africa`, `brim-liberty-liqueur.ngrok-free.dev`, `localhost`, `127.0.0.1`; reCAPTCHA Enterprise/v3; optional test numbers (e.g. `+254700000020` / `123456`); restrict public API key by referrers.
   - Deploy the Firebase commit (no migration), add Firebase vars + `AUTH_OTP_PROVIDER=firebase` to **server** `.env`, keep `APP_ENV=production`, run `deploy-sync install` (idempotent; `/health` stays applied=8), verify one real OTP login on HTTPS, then authenticated smoke.
3. ~~**Inc-10 DB gate** (T109)~~ — **DONE/PASSED**: migration 10 applied, **9 migrations / 39 tables / 52 perms / 13 roles / 217 links / 72 FKs / 2 invoices + 1 payment**, dev `/health` applied=9/pending=0; integrity 26/26, cleanroom 28/28.
4. ~~**Inc-10 route gate** (T110)~~ — **DONE/PASSED**: routes **165** (121+44), 7 billing routes + gates; `t77_verify.php` **55/55**, `t98_verify.php` **24/24**.
5. **Billing UI gate** (T111): nav/quick-link flags are `true` in the tree; formal pwa-shell smoke report pending (gate readiness already shows 200/403/302).
6. **Billing tests** (T112): fold the 25 parked Billing tests, `--filter=Billing`, then the **final full suite** (Firebase+Inc-10 baseline; pre-Inc-10 = 710/710).
7. **Release bundle/docs** (T113): **docs written** (`increment-10-acceptance.md`, changelog, deploy docs/packet, this file); **bundle rebuild + full secret screen + pin await the Lead's “bundle go”** after the final suite — no zip refresh yet.
8. **Close-out**: final live matrix + authenticated smoke after the owner deploys `applied=9`; close remaining tasks (incl. Firebase activation).

---

## 4. Owner-pending actions (do not lose)

1. ~~**Apply rotated `DEPLOY_SYNC_TOKEN` on the server**~~ — **DONE and verified 2026-11-11**: the owner applied the rotation; old (shared) token now returns **403 Forbidden.**, rotated 48-hex token returns **200** (`success:true`, applied=8/pending=0, DB `tokeqvlx_dashpass_db`, production) and `/health` is unchanged. New value only in the server `.env` (`chmod 600`) + local gitignored `.env`/`.env.production.local` + `storage/temporary/deploy-token-new.txt` (hand-off file — delete when no longer needed). Never print it; if it is ever exposed again re-run the §6a procedure (rotation helper).
2. **Firebase console setup + server web config** (see §1 live-state + `docs/firebase-sms-otp.md` §1b) — provider=firebase is already active, only `FIREBASE_SMS_ENABLED` + public `FIREBASE_*` are missing; this is the only thing blocking live login.
3. **Deploy the newest pin (`ebe607e`+)** and run the §1b activation order (no migration; PHP-FPM reload; CF purge; SW refresh; re-probe; one OTP login).
4. **Inc-10 deploy (pending T113 bundle):** after “bundle go” + final suite — pin the announced commit, `deploy-sync install` → **applied=9 / 39 tables / 217 links / 2 invoices + 1 payment**, routes **165**; smoke billing (Daniel 200 / estate users 403 / unauth 302; invoice+payment lifecycle; past-due CLI dry→apply idempotent).
5. Public-launch hygiene: demo-data cleanup decision, keep `APP_KEY` stable, monitor SMS/reCAPTCHA quota.

---

## 5. Secrets & hygiene
- `.env`, `.env.production.local`, `storage/temporary/**` are **gitignored** — never commit or paste token values.
- The old deploy token (shared earlier in chat) is **revoked/dead** — the server returns **403**; the rotated 48-hex token was **verified 200** (2026-11-11, applied=8/pending=0) and lives only in the server `.env` (`chmod 600`) + local gitignored `.env`/`.env.production.local` + `storage/temporary/deploy-token-new.txt` (delete the hand-off file after use). Never print/paste it; re-rotate immediately after any future exposure (§6a).
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
- Acceptance: `docs/increment-{8,9}-acceptance.md` (ACCEPTED), `docs/increment-10-acceptance.md` (**GATED — pending final suite**)
- Owner: `docs/deploy-now-inc6-inc7.md` (Inc-6→Inc-10 packet, owner) / `docs/deployment-cpanel.md`, `docs/deployment-rollback.md`, `docs/db-integrity-check.md`, `docs/ui-acceptance-checklist.md`, `docs/changelog.md`, `docs/firebase-sms-otp.md`, `docs/database-schema.md`
- Gate staging: `storage/temporary/inc10/`, `storage/temporary/gates/` (`GATES.md`, `inc10-routes.patch`), `storage/temporary/pending-tests/`
- Proof scripts: `storage/temporary/t64_verify.php`, `t77_verify.php`, `t98_verify.php`, `inc9_inc10_gate_status.php`
- E2E: `storage/temporary/{e2e-common,inc8-e2e,inc9-e2e,inc10-e2e}.js` + reports
- Live evidence: `storage/temporary/live-final-verification.{md,json}`, `live-post-purge-*`
- Release bundle: `storage/temporary/mtaapass-deploy.zip` is still the **Inc-9 build** (392 entries, sha256 `8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`, pin `be9b410`) — **Inc-10 rebuild (T113) NOT started**; it waits for the Lead's **“bundle go”** after the final suite. Checklist: `docs/git-release-process.md` §4b (Inc-10 bundle refresh).

---

## 8. Known issues / gotchas
- **Live login is blocked by the half-activated Firebase state** (provider=firebase active, `FIREBASE_SMS_ENABLED`/public web config missing → legacy UI; owner sees the guard message) — see §1 live-state + `docs/firebase-sms-otp.md` §1b for the unblock order; temporary unblock = provider=server.
- **Local deploy-sync token ≠ server token** until owner applies rotation (403 `Forbidden.` is token mismatch, not a code bug).
- **Firebase tests are GREEN (34/34)** and the full suite is **710/710 PASS** (`tests/_resume-full.log`); the RSA-key fixture issue is fixed with a static test-only PEM.
- **Inc-10 gates PASSED but not released/deployed** — migration 10 applied on dev (9/39/52/13/217/72 FK/2+1), routes 165, t77 55/55 + t98 24/24; final suite, billing UI/test/E2E reports and the T113 bundle are pending. **Do not deploy Inc-10 until the bundle + pin are announced** (live stays applied=8).
- Billing nav/quick-link flags are now `true` in the tree (T111 flip) — `/platform/billing` is wired at 165 routes; it will 404 on live until the Inc-10 deploy. Billing is platform-only (estate users 403); estate billing read is Inc-11.
- **Legacy server-OTP endpoints are closed under `AUTH_OTP_PROVIDER=firebase`** (API 403 `AUTH_PROVIDER_MISMATCH`, web generic redirect); `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` is dev/tooling-only and force-disabled in production. Rolling back to server OTP = set `AUTH_OTP_PROVIDER=server` (needs a real SMS adapter in production).
- Inc-10 migration must keep the fixed order (ALTER → invoices → payments); `GATES.md`/runbook record it.
- Shared test DB: only ONE runner at a time (stable lock). Full suites are exclusive; filtered runs are short.
- `.rnd` OpenSSL artifact at repo root is **removed from tracking and gitignored** — never commit it; if it reappears it is local noise only.
- Git watcher (`scripts/git-autopush.ps1`, pid ~1444) auto-commits/pushes working-tree changes every ~25–60s; check `git log/status` and pull before editing if resuming on another machine.
- XAMPP PHP prints `Module "openssl" is already loaded` on every call — harmless.

---

## 9. Resuming on a fresh machine/session (quick start)
1. `git pull` (the watcher may have committed WIP files — review `git log --oneline -5` and `git status`).
2. Start dev server + verify `http://127.0.0.1:8010/health` → applied=8.
3. Firebase tests are green (34/34) and the full suite is **710/710 PASS** (`tests/_resume-full.log`); no need to re-run unless code changed.
4. Continue §3 in order (step 1 done → Firebase gate).
5. Read this file's §4 before touching live.
