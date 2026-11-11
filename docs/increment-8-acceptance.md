# Increment 8 — Acceptance record (parking administration + overstay)

**Date:** 2026-11-11 · **Status: ACCEPTED** — final full suite **676/676 PASS** at the Inc-9 close
(2026-11-11; 158 routes / 8 migrations / 37 tables / 217 links)
**Contract:** [`parking-administration-contract.md`](parking-administration-contract.md) (binding)
· **Blueprint:** parking administration + overstay handling
**Product name:** config-driven (dev MtaaPass, prod DashPASS)

> Draft note: all gate evidence below is recorded from the Inc-8 route/data gate and the
> independent E2E run. The **full automated suite is intentionally pending** — do not treat this
> record as final acceptance until the Lead runs the exclusive suite and updates the status.

## Delivered scope

- **DB — no schema change:** still **8 migrations / 37 tables / 52 permissions / 217 role links**
  (migration 8 + 9 from Inc-6/Inc-7; Inc-8 adds nothing). The only data delta is the idempotent
  seed setting **`parking_overstay_grace_minutes = 30`** for Greenview (allowlisted 0–120,
  editable on `/admin/settings`).
- **Routes:** **147** total (web **103** + API **44**) — 16 new parking web routes (131 → 147
  gate patch applied by the Lead), all with the admin-area/role gates:
  - Admin (`/admin` + parking permissions): `/admin/parking` (overview), `/admin/parking/zones`
    (list/create/edit; zones are not archivable by design), `/admin/parking/bays`
    (list/create/edit/archive→`maintenance` + in-use guard), `/admin/parking/allocations`
    (list/create/end; one active allocation per household/bay), `/admin/parking/violations`
    (queue/create/resolve; terminal 409).
  - Guard (`/gate` + session gate): `/gate/parking/violations` (report + own list).
- **Admin behaviour:** real overview stats (zones/bays/active allocations/open violations);
  bay archive is soft (`maintenance`) and refused with `BAY_IN_USE`/422 when reserved or in an
  active session/allocation; archived bays disappear from visitor availability and allocation
  selectors; allocation end is idempotent and retains the ended row; violations require notes
  for resolve, support dismiss, reject a second transition to a terminal state
  (`VIOLATION_CLOSED` 409), unknown uuids give a friendly flash, and are never hard-deleted.
- **Guard behaviour:** guard reports a parking violation at the assigned gate (plate normalized,
  session-gated), sees its own list only; admin resolves it from the queue.
- **Settings:** new allowlisted key `parking_overstay_grace_minutes` (0–120) exposed on the admin
  settings screen (demo value **30**, confirmed on a fresh gate instance).
- **Overstay job:** `php scripts/parking-overstay.php [--dry-run] [--estate=<uuid>]` — flags
  active sessions past invitation validity + grace as `overstay`, creates **exactly one**
  violation per session, audits `parking.overstay_flagged`, is idempotent, and runs server-side
  (cPanel Terminal/cron; no web trigger).
- **UI wiring:** admin nav "Parking" entry and guard dashboard link; parking pages use the admin
  shell/CSS, empty states, CSRF on every form; XSS-escaped notes/plates.
- **Security/audit:** permission matrix enforced per route (admin-area gate + parking permissions),
  tenant isolation (David Riverside-only), CSRF on all POSTs, PII-free audit metadata, no hard
  deletes; setup/QA fixtures were cleaned up by the dev reset after the gate.

## Gate evidence

| Check | Result |
|---|---|
| Route/DB gate (`storage/temporary/inc8-gate-readiness.json`) | **ready=true** — routes **147** (web 103 / API 44), all **16** new parking routes registered with correct `controller@action` + permission, 13 module/CLI/view files present, settings allowlist has grace 0–120, `DemoSeeder` seeds **30**, seeded bays R-01…R-08 / V-01…V-06 (incl. accessible + maintenance), CLI supports `--dry-run`/`--estate`, `parking.overstay_flagged` audit present, admin-area gate enforced; HTTP readiness: `/health` applied=8 pending=0, **37 tables / 217 links**, `/admin/settings` renders grace **30**, `/admin/parking/bays` lists the seeded bays (V-06 maintenance), overview stat cards render |
| Fresh / grace proof | Seeded demo value 30 visible on a **fresh throwaway instance** (`/admin/settings` HTTP readiness) and used by the job (`Grace setting: 30 minutes (estate settings override)`) |
| Backend proof (T60) | **48/48 PASS** — parking admin services (zones/bays/allocations/violations/rules, permissions, audit, no hard deletes) + **12/12** UI form-binding proof |
| UI live smoke (T61 phase 2) | **96/96 PASS** — admin parking pages as Grace (overview/zones/bays/allocations/violations lifecycle), guard report as John, David Riverside-only, nav links |
| Independent E2E (qa-e2e, Inc-8) | **101 passed / 0 failed / 1 skipped (102 checks)** on a throwaway instance — real sessions + CSRF + dev-OTP: admin CRUD + archive/in-use guards, allocation lifecycle/idempotent end, violation resolve/dismiss + 409 + XSS, guard report, overstay fixture + idempotent second apply, negative/404/422 cases. The single skip is the bundled Inc-5/Inc-6/Inc-7 regression run (deferred: dev-OTP limit 5/900 s per phone) |
| Overstay CLI proof (`storage/temporary/inc8-ov-*.txt`) | dry-run: `estates=1 scanned=3 overstayed=1 flagged=0 violations_created=0`; apply #1: `flagged=1 violations_created=1`; apply #2: `flagged=0 violations_created=0` (**idempotent**); unknown estate uuid → clean `FAIL unknown estate uuid.` error |
| Regressions (pre-final) | Inc-5 E2E re-run **70/71** with 1 existing assertion drift on the parking-reservation expectation (`storage/temporary/inc8-reg-inc5.log`); Inc-6 regression log partial (route/auth checks PASS, no final summary — `storage/temporary/inc8-reg-inc6.log`). **To be confirmed by the pending full suite.** |
| Full test suite | **676/676 PASS** (2026-11-11, `tests/_final-full.log`, 0 fail) |
| Bundle/secret screen | Inc-8 bundle was **387 entries**, sha256 `EB12D67A…` (historical; superseded by the Inc-9 bundle **392 entries**, sha256 `8F8C511A…`); secret screens CLEAN (no values in bundle) — see [`changelog.md`](changelog.md) / [`git-release-process.md`](git-release-process.md) |

### Product fixes found and fixed during the gate

1. **Fatal `pullErrors`/`pullOld` visibility** in the parking admin controller status-transition
   path — variables were referenced outside the scope where they were assigned; initialised so
   validation/flash handling cannot fatal.
2. **Missing `bays` select payload** on the violation-create form — the controller now passes the
   bay list (estate-scoped) so the admin/guard violation form renders its bay selector.

### UI seam fixes (validated by the E2E checks “UI fix #1/#2/#3”)

1. **Resolve hidden status** — the resolve form now carries hidden `status=resolved` (previously
   the backend default path could mis-handle the transition).
2. **Dismiss posts to `/resolve`** — the dismiss action posts the same endpoint with hidden
   `status=dismissed` instead of a non-existent route.
3. **`plate_number` on violation forms** — both admin and guard violation forms now post
   `plate_number` (the backend field), so plates persist and normalize.

## Deploy requirement

**No migration.** Owner deploy: pin the gated commit (**Inc-8 batch `742a9ad`**, or a later
Inc-9 pin once that gate opens) → `POST /deploy-sync.php?action=install` with `X-Deploy-Token`
(idempotent; applies the grace-setting seed) → `/health` stays **applied=8 / pending=0 /
37 tables / 217 links** → purge Cloudflare → smoke `/admin/parking` (+ zones/bays/allocations/
violations as Grace), `/gate/parking/violations` (John), and
`php scripts/parking-overstay.php --dry-run` then apply on a test estate (idempotent second run).
Bundle: `storage/temporary/mtaapass-deploy.zip`; owner quickstart
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) §4f; rollback/DB policy in
[`deployment-rollback.md`](deployment-rollback.md).

## Known limitations / deferred (documented)

- **Bay archive guard scope:** the in-use guard for allocations/sessions/reservations is enforced
  server-side with a 422; the UI label only distinguishes the `allocated` case, so a
  reservation/session-held bay refuses with the generic “not available” flash.
- **Zones cannot be archived** — no status column/archive control by contract; bays archive to
  `maintenance` instead.
- **Overstay is a server-side job** — no web trigger; it needs a cPanel cron/Terminal invocation
  (`php scripts/parking-overstay.php`), and only flags sessions (no fines/payments/notifications).
- **No barriers/ANPR/plate-camera integration** and no automatic fines; violations are a manual
  workflow.
- **Guard parking incidents** remain report/list-only (incident detail/triage stays admin-only,
  inherited from Inc-6).
- **Dev proof artifacts** (QA zones/bays/allocations/violations, overstay fixture) are scratch data
  created during the gate and cleaned by the reset; never present on a fresh install.
- **Suite pending:** this acceptance record must be re-issued as ACCEPTED after the final full
  suite; treat the E2E and per-layer proofs as the current gate evidence only.

## Next

- **Increment 9 — platform super-admin portal** (routes **158**, no schema change).
- **Increment 10 — billing/invoices** (routes **165**, **migration 10** → 9 migrations /
  39 tables).
- Release/bundle refresh follows each gate (see [`changelog.md`](changelog.md) and
  [`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md)).

## Evidence artifacts (gitignored scratch, local)

`storage/temporary/inc8-gate-readiness.json`, `storage/temporary/inc8-e2e-result.json`,
`storage/temporary/inc8-e2e-run.log`, `storage/temporary/inc8-parking-e2e-checklist.md`,
`storage/temporary/inc8-overstay-fixture.*`, `storage/temporary/inc8-ov-dry.txt`,
`storage/temporary/inc8-ov-apply1.txt`, `storage/temporary/inc8-ov-apply2.txt`,
`storage/temporary/inc8-ov-bad.txt`, `storage/temporary/inc8-reg-inc5.log`,
`storage/temporary/inc8-reg-inc6.log`, `storage/temporary/gates/GATES.md`.
