# Increment 7 — Acceptance record (estate notices + announcement centre)

**Date:** 2026-11-11 · **Status: ACCEPTED**
**Contract:** `docs/estate-notices-contract.md` (binding) · **Blueprint:** §announcements / notification centre

## Delivered scope
- **DB:** migration 9 `estate_notices` (8 migrations, **37 tables**, 52 permissions, **217 role links** after wildcard fix), `DemoNoticeSeeder` (4 Greenview notices: 3 published incl. urgent+expiry, 1 draft; Riverside none), idempotent.
- **Routes:** **131** at Inc-7 gate (10 notice routes: resident 2 + guard 1 + admin 7; `notice.view`/`notice.manage`), later 147 with Inc-8.
- **Admin:** queue with status/category/priority filters + counts; create draft; **edit draft inline in detail** (no extra route); publish / archive with 409 semantics (`NOTICE_ALREADY_PUBLISHED`, `NOTICE_LOCKED`, `NOTICE_CLOSED`) surfaced as PRG flashes on web; validation 422; audit `notice.created/updated/published/archived` PII-free; no hard deletes.
- **Resident:** `/app/notices` + detail (published, audience residents/all, unexpired; priority urgent→important→normal ordering; draft/archived/expired/foreign 404); Home real announcement feed (≤3, real notices always win over preview demo); account “Estate notices” entry; Home “Notices & Updates” tile now a real link.
- **Guard:** `/gate/notices` read-only (audience all/staff only; resident-only hidden).
- **Wiring:** `ResidentController` uses `NoticeService::homeAnnouncements(3)` with `announcements_real`/`announcements_demo` flags; `AdminBaseController` admin-area gate includes `notice.manage`; seeded demo notice bodies cleaned (no “Demo notice -” prefix).

## Gate evidence
| Check | Result |
|---|---|
| Dev + cleanroom DB | 8 migrations / 37 tables / 52 permissions / **217 links** / 4 notices; install twice idempotent; `/health` applied=8 |
| Backend proof (T53) | **51/51 PASS** — creatis/edit/publish/archive + 409s, validation 422, CSRF, resident/guard visibility + priority order, home feed ≤3 urgent-first, 404 scoping, PII-free audits, no hard deletes. **Real bug found+fixed:** priority ordering DESC→ASC. |
| UI live smoke (T54) | **81/81 PASS** — Grace full lifecycle (409 flashes), Jane visibility/Home View All, John audience filtering, David Riverside 404/empty |
| Independent E2E (qa-e2e) | **78/80 PASS, 0 FAIL** (2 SKIP) — admin workflow, audience/expiry, priority order, guard, David scoping, CSRF/XSS; no product gaps |
| Regressions | Inc-5 71/71, Inc-6 95/96 (0 FAIL) on same instance |
| Fixes folded from proofs | priority ordering ASC; `NoticePresenter` unknown-enum humanisation (“Some Future”); seeded demo body prefix removed; stale Home tile toast removed |

## Deploy requirement
Owner deploy: pin gated commit (Inc-7 batch, `9c4fe06` era) → `POST /deploy-sync.php?action=install` with `X-Deploy-Token` (migration 9) → `/health` **applied=8 / 37 tables / 217 links** → smoke `/admin/notices` (create/publish/archive + 409s), `/app/notices` (published/audience only; draft/archived/foreign 404), `/gate/notices`, Home real cards + View All. Bundle: `storage/temporary/mtaapass-deploy.zip`; quickstart `docs/deploy-now-inc6-inc7.md`.

## Deferred (documented)
Notices are in-app only (no push/email), no attachments/read-receipts, guard list has no per-notice detail (design), audience=staff scoped to guard surface, demo data purge at launch.

## Next
Increment 8 — parking administration + overstay (routes 147, no migration) → Increment 9 platform portal (158) → Increment 10 billing (165, migration 10).
