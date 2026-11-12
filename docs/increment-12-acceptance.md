# Increment 12 — Acceptance record (estate notice images) — **ACCEPTED (code gate)**

**Date:** 2026-11-12 · **Status: ACCEPTED (code gate) — NOT YET DEPLOYED** (image slice E2E is green; the final
combined baseline suite is pending — baseline3 was killed mid-run, baseline4 planned). **Do not claim a release until the suite is green and the
Lead's “bundle go” lands.**
**Contract:** [`estate-notices-contract.md`](estate-notices-contract.md) (Inc-12 extends notices with
image payload) · **Design note:** `storage/temporary/inc12/DESIGN-NOTES.md` · **Base:** Inc-10
ACCEPTED/DELIVERED + Inc-13 preview in progress · **Product:** config-driven (dev MtaaPass, prod

**FINAL EVIDENCE (2026-11-12, code gate):** final combined baseline **890/890 PASS, 0 FAIL, exit 0** (tests/_baseline6-full.log, 2062.70s); routes **195 (151 web + 44 API)**; DB **11 migrations / 42 tables / 84 FK constraints / 52 permissions / 13 roles / 217 links**; dev /health **applied=11 / pending=0**; **Inc-15 E2E 39/39 PASS** (CSV final contract incl. 50,001→422, carousel no-hover, account→/app/estates, /about 302 + landing #about); landing-clone verify **26/26 PASS**; UI verify **46/46 PASS**; release bundle **pin 4d492b5** — **484 entries / 1,665,049 B compressed / 5,080,696 B raw / sha256 `A36A71CF222B5442119975F5CFF3DF8CB37BF3B3F5FE6497F38C2E9C7CA90822`** (previous rollback pin `f11367c`). **ACCEPTED for the code gate — NOT yet deployed**: live preview stays applied=11; production activation remains the owner's §1b step.
DashPASS)

> **Gate status:** image checks are part of the green full E2E — `inc13-e2e-result.json`:
> **130 checks → 129 PASS / 0 FAIL / 1 SKIP**, including **13 image checks all PASS**; **Inc-5…Inc-10
> regressions PASS**. Gates verified: **11 migrations / 42 tables / 84 FK constraints / 52
> permissions / 13 roles / 217 links**; routes **183 at the E2E run** with the image route
> (current tree **195** = 151 web + 44 API after the post-E2E Inc-13 schedule/Inc-11 batch).
> **Live preview (owner decision, no rollback):** migration **000012** is applied on live
> (`applied=11 / pending=0`) — this record is **ACCEPTED for the code gate** (bundle rebuilt at pin `4d492b5`); production activation remains the owner section 1b step.
> The single **SKIP** is the unrelated `expired-at-publish fanout` fixture (see Inc-13 record).

## Delivered scope

- **DB — migration 12** (`20261111_000012_add_estate_notice_images.sql`, staged then applied;
  **additive, column-only**): `ALTER estate_notices ADD COLUMN image_path VARCHAR(255) NULL AFTER
  body, ADD COLUMN image_alt VARCHAR(160) NULL AFTER image_path`. **No new table, no FK, no index, no
  data/backfill, no destructive changes**; existing rows keep `NULL` = “no image”. Expected/served
  counts: **11 migrations / 42 tables / 84 FKs / 52/13/217** (tables unchanged by this migration).
- **Notice image route** — `GET /notices/{uuid}/image` (`NoticeImageController::show`,
  `NoticeImageService`): `auth + estate + permission:notice.view`; streams the file from
  `storage/uploads/notices` (never a public/CDN path); only **jpeg/png/webp** content types;
  `Cache-Control: private, max-age=3600`; `X-Content-Type-Options: nosniff`; **archived, missing or
  foreign-estate** images → **404**; anonymous request → login redirect (no public leak); route is
  included in the frozen route table (183 at E2E; **195** currently, incl. `/about` redirect).
- **Admin notice UI (existing notices screens)** — multipart image upload on create/edit, replace
  image, alt text (`image_alt`), remove image on **draft** (clears `image_path`), **published-notice
  edit lock** (removing/replacing on a published notice does not mutate until the existing publish
  flow allows it), published notice without image renders no image element.
- **Resident PWA** — notice list and detail render the image + alt text for authorized residents of
  the estate; no image element when absent; no public image URLs.
- **Security/tenant** — estate-scoped queries; resident cannot use the admin upload route (**403**);
  foreign-estate user gets **404** on the image route; archived notice image rejected; no permission
  changes (no new permission nodes → links stay **217**); file type/size handled server-side;
  path traversal blocked by resolving only stored paths under the uploads root.
- **No UI/route churn for Inc-13** — the image feature is additive to the notices surfaces and does
  not change permissions, roles, billing or notification behavior.

## Gate evidence

| Check | Result |
|---|---|
| **DB gate** | **11 migrations / 42 tables / 84 FKs / 52 permissions / 13 roles / 217 links** — migration 000012 applied (`applied=11/pending=0`); two nullable columns only; no new table/FK/index; no new permission nodes |
| **Route gate** | **183 routes at the E2E run** (current tree **195**); image route `/notices/{uuid}/image` present with frozen controller/permission; gate readiness `ready=true`; required routes 0 missing |
| **Full E2E — image checks** | **13/13 PASS** (`inc13-e2e-result.json`; see `inc13-full-run.log`): admin multipart create (stores `image_path`), replace stores new path+alt, publish with image, resident list shows image, resident detail shows image+alt, authorized image served with image content-type, anonymous image request → login redirect (no public leak), remove image on draft clears DB `image_path`, published notice without image renders no element, published-notice edit lock blocks removal mutation, foreign-estate user image access → 404, resident blocked from admin upload route (403), archived notice image route rejected (404/403) |
| **Regressions** | **Inc-5…Inc-10 harnesses all PASS** on the same instance (`regression.ok=true`) |
| **Security/audit** | Image access is estate-scoped; no public storage path; audit rows PII-free; no hard deletes of notice rows; advisory checks green (only pre-existing `/health` CSRF exemptions flagged) |
| **Final combined baseline suite** | **PASS — 890/890 PASS, 0 FAIL, exit 0** (baseline6, 2062.70s, `tests/_baseline6-full.log`) |

## Known limitations / constraints

- **Only SKIP is unrelated to Inc-12** — the `expired-at-publish fanout` fixture (notifications
  fan-out); it is documented + non-blocking in the Inc-13 record.
- **Image serving is private and synchronous** — files are streamed from `storage/uploads/notices`
  with private caching; **no CDN/public directory**, no thumbnails/resizing pipeline, no async image
  processing; allowed types **jpeg/png/webp** only (no SVG). `storage/uploads/notices` must be
  writable by the app and is excluded from the release bundle runtime dirs.
- **Columns only** — no image gallery/ordering, no multiple images per notice, no lightbox beyond the
  existing detail view; `image_alt` is the only accessibility metadata.
- **Live preview is not a release** — live has migration 000012 applied via git pull + install
  (applied=11), but Inc-12 acceptance/bundle flip are pending the final suite + “bundle go”.
- **Bundle**: release artifact is still the Inc-10 bundle (pin `f11367c`); Inc-12/13 bundle rebuild
  pending. Rollback path = pin `f11367c` (additive-compatible; migrations forward-only).

## Deploy requirement

Apply migrations **000011 + 000012** via `POST /deploy-sync.php?action=install` (idempotent; 000012
after 000011) → `/health` **applied=11 / pending=0**, **42 tables / 84 FKs / 52 permissions / 13
roles / 217 links**, routes **195 current (183 at E2E)**. Ensure `storage/uploads/notices` exists and is writable (the
installer/seed creates it; keep it **out** of the release bundle runtime dirs). Smoke: admin create
notice with multipart image → publish → resident list/detail shows image+alt; direct image request
authorized resident **200 image content-type**, anonymous → login, foreign estate → **404**; draft
image removal clears the column; archived notice image rejected. Rollback: redeploy pin `f11367c`
code (schema additive-compatible).

## Next

- **ACCEPTED on baseline6 (890/890)** - code gate passed; bundle rebuilt at pin `4d492b5`.
- **Deploy is the owner step:** live preview stays applied=11; production activation remains the owner's section 1b order (no migration; Firebase env + cache refresh). Keep the rollback pin `f11367c` (previous bundle) available.
- No release claim until the owner deploys; Inc-11 is also ACCEPTED at the code gate.