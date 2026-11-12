# MtaaPass / DashPASS — Pickup memory (resumed session)

**Resumed at:** 2026-11-11 21:52 EAT (Africa/Nairobi) · **Status:** RESUMED — no stale agent processes;
**Firebase filter GREEN (34/34 PASS)**; exclusive full suite **710/710 PASS, 0 FAIL**
(`tests/_resume-full.log`, 1769.53s — new Firebase+Inc-9 baseline, superseding the pre-Firebase
676/676); `.rnd` artifact removed from tracking and gitignored; test lock now free.
**Repo:** `C:\xampp\htdocs\www\MtaaPASS` · **Dev:** http://127.0.0.1:8010 · **Live:** https://dashpass.africa

**Resume state (2026-11-11, latest):** **Inc-10 ACCEPTED/DELIVERED** — final suite **757/757 PASS
(0 FAIL, 1576.72s, exit 0)**, DB gate **9/39/52/13/217/72 FK/2 invoices + 1 payment**, routes
**165**, T111 **49/49**, E2E **70/72 (0 FAIL, 2 SKIP)**, t77 **55/55**, t98 **24/24**; bundle
**409 entries**, sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, pin
**`f11367c`**; **live applied=9** (bundle = release/rollback artifact); Firebase **ACTIVATED**.
**Inc-13 IN PROGRESS (DRAFT — no release claim) — agents paused by Lead:** ✅ **P0 gate
10/42/84/52/13/217**; ✅ **P2a notices→bell backend + UI**; ✅ Home announcements auto-scroll;
✅ Inbox+Notices+bell IA; ✅ **P1 automation CLI 29/29** (CLI tests refold pending); ✅ **P4
route/controller slice landed** — settings (notifications/payments), automation summary, pay-link
create, 2 CSRF-exempt webhooks, resident bills, admin utility, notifications `webIndex`/`webRead`,
`/app/navigation`, `/app/account`. **Routes 183 (139 web + 44 API)** incl. the Inc-12
`/notices/{uuid}/image` route. **Status: GATED — pending final suite** — full E2E **130 checks →
129 PASS / 0 FAIL / 1 SKIP** (only SKIP = `expired-at-publish` fixture) with **Inc-5…Inc-10
regressions PASS**; acceptance docs drafted: [`increment-13-acceptance.md`](increment-13-acceptance.md)
+ [`increment-12-acceptance.md`](increment-12-acceptance.md) (GATED). **Remaining:** final combined
baseline suite (`tests/_baseline2-full.log` running) → ACCEPTED flip + bundle at **“bundle go”**;
paused UI gaps = P2 settings polish, resident Account card removals / estate Appearance. **Inc-11**
contract drafted DRAFT (`docs/estate-billing-reports-contract.md`; ~182→~186 routes, design-only).
See §3.

This file is the single source of truth for resuming. Read it fully before starting any task.

**LIVE PREVIEW (2026-11-12, owner decision — no rollback):** live runs the **Inc-13/Inc-12 WIP**
(code deployed via **git pull**) with migrations **applied=11 / pending=0** (**42 tables / 84 FKs /
52 permissions / 13 roles / 217 links**), **routes 183** (139 web + 44 API); Firebase login
**active**; billing / **notice-image** / incident routes live. **NOT an accepted release** — the
baseline suite, P2/P3 slices and Inc-13 acceptance are still pending. **Rollback path:** release pin
**`f11367c`** — Inc-10 bundle artifact `storage/temporary/mtaapass-deploy.zip` (sha256
`18B6FB03…90E1`; archive copy `storage/temporary/f11367c.zip`), schema additive-compatible and
migrations forward-only. The **UI hotfix is effectively live** (code included) and the hotfix zip
`storage/temporary/ui-hotfix-inc10.zip` is retained for rollback/reference. **Known gaps:** **P2
settings forms**, **resident Account card removals / estate Appearance (paused)**, **Inc-11
pending**. See §1.

---

## 1. Where the product stands

### Live production — Inc-13/Inc-12 **PREVIEW** (2026-11-12; NOT an accepted release)
- `/health`: `version 0.2.1-dashpass`, `environment production`, **applied=11 / pending=0** (DB
  `tokeqvlx_dashpass_db`); **42 tables / 84 FK constraints / 52 permissions / 13 roles / 217 links**;
  **routes 183** (139 web + 44 API).
- **Deployed by `git pull`** (owner decision: live stays on the preview, **no rollback**): Inc-13
  automated-billing WIP + Inc-12 notice images (`20261111_000012_add_estate_notice_images.sql`)
  are live; **billing, notice-image and incident routes are live**; Firebase login is **ACTIVE**.
- **Not an accepted release:** the baseline/full suite, P2/P3 slices and the Inc-13 acceptance
  record are still pending — do not claim Inc-12/Inc-13 as released.
- **Rollback path:** release pin **`f11367c`** = Inc-10 bundle artifact
  `storage/temporary/mtaapass-deploy.zip` (409 entries / sha256
  `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`; archive
  `storage/temporary/f11367c.zip`). Schema is additive-compatible and migrations are
  forward-only, so rolling code back to the pin does not require a DB downgrade.
- **UI hotfix is effectively live** (its code is included in the deployed tree); the hotfix zip
  `storage/temporary/ui-hotfix-inc10.zip` is retained for rollback/reference.
- **Known gaps on the preview:** **P2 settings forms** not finished, **resident Account card
  removals / estate Appearance paused**, **Inc-11** (estate billing read + reports/exports) still
  pending.
- Historical: Inc-9 build (`158 routes`, applied=8) → Inc-10 (`165 routes`, applied=9, bundle pin
  `f11367c`) → current Inc-13/Inc-12 preview (183 routes, applied=11).

### Dev environment
- Routes **165** (web 121 + API 44). DB: **9 migrations / 39 tables / 52 permissions / 13 roles / 217 role links / 72 FKs / 2 invoices + 1 payment** (Inc-10 applied).
- Dev DB has QA residue right now (e.g. staff 6, incidents 10, notices 9). Reset with the hardened tool when needed:
  `$env:ALLOW_DEV_RESET='1'; php scripts/dev-reset.php --dry-run` then `--yes --force` (or `--force` + env; see tool `--help`). Default path = DROP/CREATE database (safe; fixes stale InnoDB FK dictionary).
- **Local dev env is now `AUTH_OTP_PROVIDER=server`** (classic OTP + dev panel) so `/platform` can be tested locally via dev-OTP login; the local `.env` still holds the public Firebase config. **Production target remains Firebase**: `.env.production.local` (and the server `.env` once activated) uses `AUTH_OTP_PROVIDER=firebase` + `FIREBASE_SMS_ENABLED=true`, SDK **13.2.0 modular**, public config **appId `1:99186909972:web:ad3900fddee22fe6b91801`** / **measurementId `G-D9JVCQJG9V`**; `.env.example` remains placeholders (`AUTH_OTP_PROVIDER=server`). **One-line revert to Firebase locally:** set `AUTH_OTP_PROVIDER=firebase` (+ `FIREBASE_SMS_ENABLED=true`) in local `.env`, save, reload — no restart needed (env is read per request).
- **Live Firebase state (2026-11-11) — ACTIVATED (verified ~22:34 EAT):** the owner applied the
  server `.env`; live `/login` now renders Firebase mode (`data-firebase-auth`, provider=firebase,
  appId `1:99186909972:web:ad3900fddee22fe6b91801`, measurement `G-D9JVCQJG9V`, SDK **13.2.0**
  module + reCAPTCHA) and `firebase-live-probe.js` → **`activationDetected=true`**; the T138 guard
  (API `request-otp`/`verify-otp` → **403 `AUTH_PROVIDER_MISMATCH`**) remains. Rollback remains
  `AUTH_OTP_PROVIDER=server` + PHP reload. **Inc-10 live state:** code + migration 10 are applied on
  live (`/health` applied=9, billing routes live, seed expected 2 invoices + 1 payment) but the
  release packet (bundle/pin/acceptance) is still pending.

### Accepted increments (closed)
- **Inc-1…Inc-9 all ACCEPTED.** **Current approved baseline: 710/710 PASS, 0 fail** (Firebase +
  Inc-9; `tests/_resume-full.log`, 1769.53s), superseding the pre-Firebase **676/676** at the Inc-9
  close (`tests/_final-full.log`; 172 unit / 438 integration / 66 security, 80 files). Both are
  valid references — use **710** as the current bundle/docs baseline.
- Acceptance docs: `docs/increment-{3,4,5,6,7,8,9}-acceptance.md` (Inc-8/Inc-9 finalised 2026-11-11).
- Board tasks completed: T59 (Inc-7), T63 (Inc-8), T67 (Inc-9), **T109 (Inc-10 DB gate), T110 (route
  gate), T111 (billing UI 49/49)**, T130/T133/T146 (Firebase docs); **T112 (billing tests) pending**;
  Inc-8/9/10 gate evidence recorded.

---

## 2. Work paused mid-flight (resume points)

### A. Firebase OTP — code done, tests **GREEN (34/34)**, full suite **710/710 PASS**, **ACTIVATED ON LIVE**
- **Landed:** server `config/firebase.php` + `FirebaseTokenVerifier` (Google public certs, RS256, iss/aud/exp/iat/auth_time/phone_number, cert cache, fail-closed 503; **no service-account JSON**) integrated into the existing `POST /login/verify` (no new routes); login UI + `public/assets/js/firebase-auth.js` (**SDK 13.2.0 modular, ESM imports from gstatic**, external `type="module"`, reCAPTCHA, posts hidden `firebase_token`, analytics intentionally omitted); CSP extended in `app/Middleware/SecurityHeaders.php`; docs `docs/firebase-sms-otp.md` rewritten (T130) and refreshed for the new appId/measurement + SDK 13.2.0 (T133/T146).
- **Tests: GREEN.** The RSA-key fixture failure was fixed with a static test-only PEM fixture;
  `php tests/run.php --filter=Firebase` → **34/34 PASS**. The exclusive full suite then passed
  **710/710 (0 fail, 1769.53s, `tests/_resume-full.log`)** — new Firebase+Inc-9 baseline.
- **Live is ACTIVATED and verified:** owner applied the server `.env` (web config + public values;
  provider=firebase was already active), live `/login` shows the Firebase markers and the probe
  reports **`activationDetected=true`**; `/health` unchanged (applied=8; no migration). T138 403
  guard remains. Rollback remains `AUTH_OTP_PROVIDER=server` + PHP reload. **Next:** one real
  test-number/real OTP login smoke + `auth.firebase_login` audit (owner/qa), then no further
  Firebase action needed for Inc-10.

### B. Inc-10 Billing — **ACCEPTED / DELIVERED** (bundle pin `f11367c`)
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
- **Billing UI (T111): DONE/PASSED 49/49** (nav+quick-link, list/new/detail/payment/auto-paid/
  void-terminal, 403/302/404 matrix; harnesses 127/95/46/25/76). **E2E (qa-e2e): 70/72 (0 FAIL,
  2 SKIP)** with regressions Inc-5 71/71 · Inc-6 95/96 · Inc-7 78/80 · Inc-8 101/102 · Inc-9 67/68.
  **Billing tests (T112): 25 tests folded**; **final Inc-10 suite 757/757 PASS, 0 FAIL, 1576.72s,
  exit 0** (`tests/_inc10-final.log`, `_inc10-final-exit.txt` = FULL-EXIT=0).
- **DELIVERED (T113):** bundle `storage/temporary/mtaapass-deploy.zip` = `409` entries /
  `1,203,313` B / sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, frozen at pin **`f11367c`**, secret screen **CLEAN**;
  excludes `.env*`/`storage/**`/`.git`/logs/scratch and Inc-12/Inc-13 staging. **Live already has
  code + migration 10 applied** (applied=9); bundle is the release/rollback artifact.
- **Acceptance docs:** `docs/increment-10-acceptance.md` (**ACCEPTED — DELIVERED**),
  `docs/increment-13-acceptance.md` + `docs/increment-12-acceptance.md` (**GATED — pending final
  suite**; flip to ACCEPTED after the final combined baseline suite + “bundle go”).

### C. Other queued/ready
- **Automated billing & utility bills (Inc-13, contract `automated-billing-contract.md`) — GATED —
  pending final suite; NOT released.** Built + E2E green: full E2E **130 checks → 129 PASS / 0 FAIL
  / 1 SKIP** (only SKIP = `expired-at-publish` fixture) with **Inc-5…Inc-10 regressions PASS**; gates
  **11 migrations / 42 tables / 84 FKs / 52 perms / 13 roles / 217 links**, **routes 183 (139 web +
  44 API)**; acceptance doc [`increment-13-acceptance.md`](increment-13-acceptance.md). Landed: ✅ P0
  DB gate; ✅ P1 automation CLI (29/29); ✅ P2a notices→bell backend+UI; ✅ home auto-scroll; ✅
  Inbox+Notices+bell IA; ✅ P4 route/controller slice (settings, automation, pay-links, 2 CSRF-exempt
  webhooks, resident bills, admin utility, notifications `webIndex`/`webRead`, `/app/navigation`,
  `/app/account`). **Remaining:** final combined baseline suite (`tests/_baseline2-full.log` running)
  → ACCEPTED flip + bundle at **“bundle go”**; paused gaps = P2 settings polish, resident Account
  card removals / estate Appearance. **Inc-12 notice images** = GATED acceptance
  [`increment-12-acceptance.md`](increment-12-acceptance.md) (migration 000012 applied on preview,
  13/13 image E2E checks PASS). **Consolidated freeze (T164–T168)
  applied:** **no new permissions** (billing families reused → 52 permissions / 13 roles / **217
  links unchanged**); **routes 183 total (139 web + 44 API = +18 web)**: platform settings
  notifications/payments (GET+POST), platform automation summary, platform pay-link create, public
  `/pay/{token}`, **2 webhooks** `/webhooks/payments/mpesa|card`, resident **My Bills**
  list/detail/pay-link, **notifications inbox/read**, admin utility overview; **estate settings fold
  into the existing `/admin/settings` (no new route)**; CLI `scripts/billing-automate.php`;
  **utility basis selectable per utility per estate** — **flat / metered / both + tariff** (estate
  admin sets); **period keys UTC**, monthly anchor **1st** with month-end clamp, `lead_days` before
  period start, **due-in 14d**, reminders **3 days before + on due**, **schedule defaults + past-due
  escalation editable by estate admin**; **notification channels**: super admin sets global
  availability (email/in-app always; **SMS + WhatsApp only when a real gateway is configured**),
  estate admin selects per estate; **collection = M-Pesa & Card payment links** (provider config +
  mode test/live, estate method enablement, **signed stateless links TTL 24h default / max 7d**,
  **webhooks signature + replay protection → idempotent `billing_payments`**, manual fallback) — not
  manual-only; **webhook CSRF-exempt route mechanism required** (route-level allowlist, no global
  disable); resident read/pay via **auth + household-membership ownership (no permission node)**,
  guard **none**.
  **T160/schema:** **+3 tables** — `billing_schedules` (scope `estate_subscription`|`utility_bill`)
  + `utility_charges` + **`platform_settings`**; `billing_invoices.created_by_user_id` becomes
  **nullable** for automation/system rows (audit actor **system**); `notification_logs` extended
  (`sent_at`/`reminder_sent_at`, no outbox/inbox table); single migration
  **`20261111_000011_add_billing_schedules_and_utility_billing.sql`**, `000012` reserved for Inc-12
  images; expected DB gate **10 migrations / 42 tables / 84 FKs / 52 permissions / 13 roles /
  217 links**. Docs-only until the build gate; remaining owner questions in contract §14.
- **Planned increment — estate billing read-only + reports/exports (Inc-11, DRAFT contract, never
  built)**: [`estate-billing-reports-contract.md`](estate-billing-reports-contract.md) — **DRAFT /
  design-only; build after Inc-12/Inc-13; no release claim**. Scope: estate admin/manager read-only
  own-estate subscription + invoices + payment ledger, finance reports (billing summary,
  outstanding/past-due, payments received, utility bills), date-range filters, streamed CSV export
  (BOM/RFC4180/injection-safe/masked PII) + browser print/PDF; **no writes, no new permissions
  (52/13/217), no new tables**. Candidate routes `/admin/billing`, `/admin/billing/invoices/{uuid}`,
  `/admin/billing/reports`, `/admin/billing/export.csv` (+optional utility report) → **~182→~186**
  (exact freeze at Inc-11 gate); audit only `report.exported` (PII-free). Open decisions in contract
  §12.
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

1. ~~**Fix Firebase test fold**~~ — **DONE**: static RSA/cert test fixture → `--filter=Firebase` **34/34 green**; the exclusive full suite passed **710/710** (`tests/_resume-full.log`).
2. ~~**Firebase gate**~~ — **DONE / ACTIVATED ON LIVE (verified 2026-11-11)**: owner applied the server `.env` (web config + public values; provider=firebase already active), live `/login` shows the Firebase markers and the probe reports **`activationDetected=true`**; `/health` unchanged (applied=8; no migration). Rollback = `AUTH_OTP_PROVIDER=server` + PHP reload.
3. ~~**Inc-10 DB gate** (T109)~~ — **DONE/PASSED**: migration 10 applied, **9 migrations / 39 tables / 52 perms / 13 roles / 217 links / 72 FKs / 2 invoices + 1 payment**, dev `/health` applied=9/pending=0; integrity 26/26, cleanroom 28/28.
4. ~~**Inc-10 route gate** (T110)~~ — **DONE/PASSED**: routes **165** (121+44), 7 billing routes + gates; `t77_verify.php` **55/55**, `t98_verify.php` **24/24**.
5. ~~**Billing UI gate** (T111)~~ — **DONE/PASSED 49/49**: nav + dashboard quick-link visible; list/new/detail/payment/auto-paid/void-terminal; 403/302/404 matrix; render/alignment harnesses 127/95/46/25/76.
6. **Billing tests** (T112): fold the 25 Billing tests (latest `--filter=Billing` iteration 25/25), then the **final full suite** (Firebase+Inc-10 baseline; pre-Inc-10 = 710/710).
7. **Release bundle/docs** (T113): **docs written** (`increment-10-acceptance.md`, changelog, deploy docs/packet, this file); **bundle rebuild + full secret screen + pin await the Lead's “bundle go”** after the final suite — no zip refresh yet.
8. **Close-out**: final live matrix + authenticated smoke after the owner deploys `applied=9`; close remaining tasks (incl. Firebase activation).

---

## 4. Owner-pending actions (do not lose)

1. ~~**Apply rotated `DEPLOY_SYNC_TOKEN` on the server**~~ — **DONE and verified 2026-11-11**: the owner applied the rotation; old (shared) token now returns **403 Forbidden.**, rotated 48-hex token returns **200** (`success:true`, applied=8/pending=0, DB `tokeqvlx_dashpass_db`, production) and `/health` is unchanged. New value only in the server `.env` (`chmod 600`) + local gitignored `.env`/`.env.production.local` + `storage/temporary/deploy-token-new.txt` (hand-off file — delete when no longer needed). Never print it; if it is ever exposed again re-run the §6a procedure (rotation helper).
2. ~~**Firebase console setup + server web config**~~ — **DONE/ACTIVATED 2026-11-11**: owner applied the server `.env`; live `/login` Firebase markers + probe `activationDetected=true` verified; T138 403 guard remains.
3. ~~**Deploy the newest pin (`ebe607e`+) and run the activation order**~~ — **DONE** as part of activation (no migration; probe/adapter checks passed).
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
- Release bundle: `storage/temporary/mtaapass-deploy.zip` is now the **Inc-10 build** — `409` entries, `1,203,313` B, sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, frozen at source pin **`f11367c`**; secret screen **CLEAN**; **release/rollback artifact** (live already applied=9 via repo pull + install). Checklist: `docs/git-release-process.md` §4b (Inc-10 bundle refresh). Previous Inc-9 build: 392 entries / sha256 `8F8C511A…` / pin `be9b410`.

---

## 8. Known issues / gotchas
- **Live Firebase is ACTIVATED** (owner applied the server `.env`; live `/login` Firebase markers + probe `activationDetected=true`); rollback = `AUTH_OTP_PROVIDER=server` + PHP reload. No migration needed.
- **Local deploy-sync token ≠ server token** until owner applies rotation (403 `Forbidden.` is token mismatch, not a code bug).
- **Firebase tests are GREEN (34/34)** and the full suite is **710/710 PASS** (`tests/_resume-full.log`); the RSA-key fixture issue is fixed with a static test-only PEM.
- **Inc-10 is DELIVERED** — bundle pin **`f11367c`** (sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, `409` entries); live already has code + **migration 10 applied** (`/health` **applied=9/pending=0**; billing routes live, `/platform/billing` 302 unauth; expected seed 2 invoices + 1 payment). Gate proofs: 9/39/52/13/217/72 FK, routes 165, t77 55/55, t98 24/24, T111 49/49, E2E 70/72 (0 FAIL, 2 SKIP), final suite **757/757 PASS**. Acceptance: `docs/increment-10-acceptance.md` (**ACCEPTED — DELIVERED**). **Next: Inc-11** (estate billing read + reports/exports).
- Billing nav/quick-link flags are `true` (T111 flip); billable pages are **live** (`/platform/billing`
  302 unauth; 403 for estate users) with migration 10 applied. Billing is platform-only; estate
  billing read is Inc-11.
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

---

## SESSION PAUSE — 2026-11-12 03:32 EAT (owner will resume tomorrow)
**State when paused (all agents stopped):**
- **Live preview:** Inc-13/Inc-12 WIP on production; `/health` applied=11/pending=0; routes **183** (139 web + 44 API); 42 tables / 84 FKs / 52 perms / 13 roles / 217 links; Firebase login active. Not an accepted release; rollback pin `f11367c` (Inc-10 bundle `18B6FB03…90E1`).
- **Acceptance:** E2E full run **0 FAIL** (Inc-13 129 PASS / 0 / 1 SKIP; Inc-5…10 all PASS). Inc-12/13 acceptance docs drafted **GATED — PENDING FINAL SUITE**.
- **Final baseline suite:** `_baseline2-full.log` = 865 total / 859 passed / **6 failed** (all assigned folds): PwaInstallStaticTest SW v5→v6 expectation, ResidentPwaHomeTest missing `assertMatchesRegularExpression` helper, ResidentPwaHomeTest stale estate-details count, FirebaseSecurityTest allowlist flagging the Material Icons attribution URL, ParkingReservationFlow mock-SMS flake, OtpSecurityTest mock-SMS count 0 (investigate product vs test env).
- **UI commits pushed/pending push (watcher):** carousel `f192489` (SW v6 + hashed assets), hero restore `beeb320`, hero text placement `57ab0c6`, hero contains greeting/bell `550f53d`, greeting contrast `a9cd0fa`, estate-name font `5f10460`, media aspect `a38c5a7`, single-line name `1f98131`, announcements spacing `518f4b6`, AI-style placeholders `f1303f4`, vibrant hero image `e034dd4`.
- **Dev DB:** clean reseeded (11 migrations/42 tables/84 FKs/52/13/217, notices 4, invoices 3, payments 1, notifications 3, audit 0); integrity 26/26 PASS; test lock free.
**Resume steps:**
1. `git pull` (ensure `origin/main` includes the UI commits above; watcher pushes).
2. tests: fold the 6 baseline reds → run one exclusive suite (`_baseline3-full.log`) target **865/865** → README exact counts.
3. On green: flip Inc-12/13 acceptance to **ACCEPTED** → **bundle go** (rebuild bundle, secret screen, sha/pin) → deploy packet.
4. Deploy live: cPanel Git pull latest + (if UI still stale) **restart PHP/LiteSpeed** + Cloudflare purge + clear PWA site data.
5. Remaining backlog: P2 settings forms polish, resident Account card removals + estate-admin Appearance (backend paused), Inc-11 read-only billing/reports.
