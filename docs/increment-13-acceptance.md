# Increment 13 — Acceptance record (automated billing + notifications) — **GATED**

**Date:** 2026-11-12 · **Status: GATED — PENDING FINAL SUITE** (full E2E is green; the final combined
baseline suite is still running). **Do not claim a release until the suite is green and the Lead's
“bundle go” lands.**
**Contract:** [`automated-billing-contract.md`](automated-billing-contract.md) (design freeze +
P0/P1/P2a/P4 build) · **Base:** Inc-10 ACCEPTED/DELIVERED (bundle pin `f11367c`, 409 entries,
sha256 `18B6FB03…90E1`; live applied=9 at that point) · **Product:** config-driven (dev MtaaPass,
prod DashPASS)

> **Gate status:** **E2E full run green** — `storage/temporary/inc13-e2e-result.json`:
> **130 checks → 129 PASS / 0 FAIL / 1 SKIP** (`inc13-full-run.log`), including **Inc-5…Inc-10
> regression harnesses all PASS** on the same instance. Gates verified: **11 migrations / 42 tables
> / 84 FK constraints / 52 permissions / 13 roles / 217 links**; **routes 183 at the E2E run**
> (current tree **194** = 150 web + 44 API after the post-E2E batch: 7 platform schedule routes +
> 4 Inc-11 read-only/CSV routes).
> **Live preview (owner decision, no rollback):** Inc-13 WIP is deployed via `git pull` with
> **applied=11 / pending=0** — this is **NOT an accepted release** yet (this record + bundle pending).
> The **only SKIP** is the `expired-at-publish fanout` fixture (non-blocking; see Known limitations).

## Delivered scope

- **DB — migration 11** (`20261111_000011_add_billing_schedules_and_utility_billing.sql`, additive):
  `billing_schedules` (scopes `estate_subscription`/`utility_bill`, utility basis/tariff, estate-
  editable timing/defaults), `utility_charges` (flat/metered/both + readings + payment markers),
  `platform_settings` (global channel/provider/automation config), `notification_logs` extended with
  `sent_at`/`reminder_sent_at` markers, and `billing_invoices.created_by_user_id` made nullable for
  automation/system rows (audit actor **system**). No destructive changes; no new permission nodes.
- **Routes 165 → 183 at the E2E run** (current tree **194** = 150 web + 44 API after the post-E2E batch: 7 platform schedule routes + 4 Inc-11 read-only/CSV routes): platform billing settings
  (notifications/payments), platform automation summary, signed **pay-link create**, public
  `/pay/{token}`, **2 provider webhooks** (`/webhooks/payments/mpesa|card`, CSRF-exempt via reviewed
  `config/security.php` allowlist + signature/replay auth), resident `/app/bills` list/detail/pay-link,
  notifications `/notifications` + `/{uuid}/read` (`NotificationController::webIndex`/`webRead`),
  `/app/navigation`, `/app/account`, plus the Inc-12 image route (see Inc-12 record).
- **Automation CLI** — `scripts/billing-automate.php` (`--dry-run`, `--date`, `--scope`, `--limit`,
  `--estate`, `--help`): lead-days generation, UTC period keys (`YYYY-MM`, 1st anchor, month-end
  clamp), due-in 14d, reminders `-3/0`, idempotent re-runs (`generated=0`), bounded catch-up,
  feature-flag gate (`AUTOMATED_BILLING_ENABLED`, default off), exit codes 0/1/2, PII-free audit.
- **Notifications/bell/inbox** — notices→bell fan-out for audience residents/staff/all with dedupe
  keys, author excluded, archived/expired handling, unread count/`9+` cap, inbox page + state/type
  filters, idempotent mark-read with server-owned redirect targets (no open redirect), XSS-escaped
  titles, flag-off degraded state, guard shell links, single bell wrapper.
- **Settings & channels** — super-admin global channel availability (email/in-app always; SMS +
  WhatsApp only with a real gateway) and provider config with mode `test`/`live` (secrets masked,
  invalid mode handled, CSRF enforced); estate settings additions for utility basis/channels/
  methods/defaults (no new route — folded into `/admin/settings`).
- **Utility bills + resident view** — estate utility overview `/admin/utility-bills`, flat/metered/
  both charges for active households only, resident **My Bills** list/detail own-household only,
  foreign/unknown bill → 404, honest empty states.
- **Pay links + webhooks** — signed stateless expiring links (TTL 24h default / max 7d), public pay
  page, webhook HMAC signature + timestamp replay window, idempotent confirmed payment rows,
  invalid signature/stale timestamp rejected, unknown invoice 404, manual fallback intact, payment
  and webhook audits PII/secret-free.
- **UI slices** — P2a bell/inbox IA, home announcements auto-scroll, P4 settings/automation/pay/
  resident/utility surfaces; carousel/hero/hotfix commits `f192489` (service-worker v6/carousel
  repush), `beeb320` + `57ab0c6` (home hero details control/estate identity alignment).

## Gate evidence

| Check | Result |
|---|---|
| **DB gate** | **11 migrations / 42 tables / 52 permissions / 13 roles / 217 role links / 84 FK constraints**; dev `applied=11 / pending=0`; `billing_schedules`, `utility_charges`, `platform_settings` present with idempotency unique keys; no new permission nodes (`0` new perms/links) |
| **Route gate** | **183 routes at the E2E run** (current tree **194** = 150 web + 44 API) — gate readiness `ready=true`; frozen P0/P4 routes present (18 required, 0 missing); 2 webhook routes route-level CSRF-exempt (reviewed allowlist only) |
| **Full E2E (slices=all)** | **130 checks → 129 PASS / 0 FAIL / 1 SKIP** (`inc13-e2e-result.json`, `inc13-full-run.log`): automation CLI (help/flag/date/scope/limit/estate/dry-run writes-nothing/apply/idempotent/catch-up/audit), notifications bell+inbox (fan-out audiences, dedupe, archived/expired, mark-read ownership/redirect safety/XSS/flag-off), settings/channels/providers (masking/validation/CSRF), estate settings, utility bills (flat/metered/both, active households, resident own-only), pay links + webhooks (HMAC/replay/idempotent/manual fallback), notice images (Inc-12), security/audit/feature-flag, regression hook |
| **Regressions** | **Inc-5, Inc-6, Inc-7, Inc-8, Inc-9, Inc-10 harnesses all PASS** on the same throwaway instance (`regression.ok=true`) |
| **Advisory / drift (non-blocking)** | Handler drift accepted + documented: `NotificationController::index → webIndex`, `markRead → webRead`; advisory “no other route CSRF-exempt” flags the pre-existing `/health` exemptions (baseline, not new) |
| **Final combined baseline suite** | **PENDING** — baseline2: **865 total / 859 pass / 6 fail** (PWA/SW + FirebaseSecurity allowlist + mock-SMS folds; fixes landed); **baseline3** (`tests/_baseline3-full.log`) running — this record flips to **ACCEPTED** only when baseline3 is green |

### Observations (non-blocking)

- Pre-regression cleanup archived published QA-notice residue so older-increment generic notice
  assertions stay deterministic (test hygiene only).
- Generation residue: `202612` existed from a previous run; the CLI correctly reported
  `generated=0` (idempotent) and period freshness was proven in the earlier clean run.

## Known limitations / constraints

- **Only SKIP = `expired-at-publish fanout` fixture** — the fixture notice is already expired, so the
  publish-time fan-out path is intentionally not exercised with a fresh expired notice; expired/
  archived behavior is covered by the archived-target and SQL/audit checks. Non-blocking.
- **Live preview is not a release** — owner decided live stays on the Inc-13/Inc-12 WIP preview
  (applied=11/pending=0; routes 183 at E2E, **194** in the current tree). It is **not accepted** until this record + the final suite are
  green and the bundle is rebuilt on “bundle go”.
- **Bundle**: the release artifact is still the Inc-10 bundle (pin `f11367c`); the Inc-12/13 bundle
  rebuild + secret screen + acceptance flip are pending the Lead's gate.
- **Rollback path**: pin `f11367c` (Inc-10 bundle artifact; additive-compatible schema, forward-only
  migrations); UI hotfix is effectively live and the hotfix zip is retained.
- **Payment providers are test-mode only** (no real gateway configured); SMS/WhatsApp channels only
  emit when a gateway is configured; no server-side PDF/async export jobs; automation cron is not
  scheduled on live (CLI only, flag default off); email/in-app are the active channels.
- **Remaining UI gaps (owner-paused):** P2 settings form polish and resident Account card removals /
  estate Appearance are paused; **Inc-11** (estate billing read + reports/exports) is still pending.
- **Permissions unchanged** — no new nodes/links (52/13/217); resident access is ownership-based
  (auth + household membership), guard has none.

## Deploy requirement

For any server behind the preview: apply migrations **000011 + 000012** via
`POST /deploy-sync.php?action=install` (idempotent) → `/health` **applied=11 / pending=0**, schema
**42 tables / 84 FKs / 52 permissions / 13 roles / 217 links**, routes **194 current (183 at E2E run)**. Required env/flags:
`AUTOMATED_BILLING_ENABLED` (default **false**), provider config + `test`/`live` mode in
`platform_settings`, channel availability (email/in-app; SMS/WhatsApp only with a gateway), Firebase
login already active. Smoke: platform settings (channels/providers, CSRF + masked secrets),
automation **dry-run** (`generated=0` on second run), resident My Bills own-household only, signed
pay link in test mode + webhook idempotency, notice image authorized serving / anonymous redirect,
estate utility overview. Rollback: deploy pin `f11367c` code (additive-compatible schema; forward-only
migrations).

## Next

- **Final combined baseline suite green** → flip this record to **ACCEPTED** and rebuild the
  Inc-12/13 release bundle on the Lead's **“bundle go”**.
- Remaining paused UI slices (P2 settings polish, Account/Appearance) + **Inc-11** estate billing
  reports contract (DRAFT) after Inc-12/13 are accepted.
- Keep live-preview state documented (applied=11, not accepted) until the gate.

## Evidence artifacts (local/gitignored unless noted)

`storage/temporary/inc13-e2e-result.json` (130 checks: 129/0/1) · `inc13-full-run.log` ·
`inc13-gate-readiness.json` · `inc13-8124-server.log` · `inc13-sql-*.txt` (FKs/tables/perms/links/
audits/flag-off/hard-delete/image-row) · `qa_inc13_*` CLI/bell test logs · `tests/_baseline2-full.log`
(final suite, in progress) · carousel/hero hotfix commits `f192489`/`beeb320`/`57ab0c6` · this record
+ [`increment-12-acceptance.md`](increment-12-acceptance.md) + `docs/changelog.md`.
