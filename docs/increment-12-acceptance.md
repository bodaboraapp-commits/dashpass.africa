# Increment 12 — Acceptance record (estate notice images) — **GATED**

**Date:** 2026-11-12 · **Status: GATED — PENDING FINAL SUITE** (image slice E2E is green; the final
combined baseline suite is still running). **Do not claim a release until the suite is green and the
Lead's “bundle go” lands.**
**Contract:** [`estate-notices-contract.md`](estate-notices-contract.md) (Inc-12 extends notices with
image payload) · **Design note:** `storage/temporary/inc12/DESIGN-NOTES.md` · **Base:** Inc-10
ACCEPTED/DELIVERED + Inc-13 preview in progress · **Product:** config-driven (dev MtaaPass, prod
DashPASS)

> **Gate status:** image checks are part of the green full E2E — `inc13-e2e-result.json`:
> **130 checks → 129 PASS / 0 FAIL / 1 SKIP**, including **13 image checks all PASS**; **Inc-5…Inc-10
> regressions PASS**. Gates verified: **11 migrations / 42 tables / 84 FK constraints / 52
> permissions / 13 roles / 217 links**; **routes 183 (139 web + 44 API)** with the image route.
> **Live preview (owner decision, no rollback):** migration **000012** is applied on live
> (`applied=11 / pending=0`) — but this is **NOT an accepted release** yet.
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
  included in the frozen **183-route** table.
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
| **Route gate** | **183 routes (139 web + 44 API)**; image route `/notices/{uuid}/image` present with frozen controller/permission; gate readiness `ready=true`; required routes 0 missing |
| **Full E2E — image checks** | **13/13 PASS** (`inc13-e2e-result.json`; see `inc13-full-run.log`): admin multipart create (stores `image_path`), replace stores new path+alt, publish with image, resident list shows image, resident detail shows image+alt, authorized image served with image content-type, anonymous image request → login redirect (no public leak), remove image on draft clears DB `image_path`, published notice without image renders no element, published-notice edit lock blocks removal mutation, foreign-estate user image access → 404, resident blocked from admin upload route (403), archived notice image route rejected (404/403) |
| **Regressions** | **Inc-5…Inc-10 harnesses all PASS** on the same instance (`regression.ok=true`) |
| **Security/audit** | Image access is estate-scoped; no public storage path; audit rows PII-free; no hard deletes of notice rows; advisory checks green (only pre-existing `/health` CSRF exemptions flagged) |
| **Final combined baseline suite** | **PENDING** — baseline2: **865 total / 859 pass / 6 fail** (PWA/SW + FirebaseSecurity allowlist + mock-SMS folds; fixes landed); **baseline3** (`tests/_baseline3-full.log`) running — this record flips to **ACCEPTED** only when baseline3 is green |

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
roles / 217 links**, routes **183**. Ensure `storage/uploads/notices` exists and is writable (the
installer/seed creates it; keep it **out** of the release bundle runtime dirs). Smoke: admin create
notice with multipart image → publish → resident list/detail shows image+alt; direct image request
authorized resident **200 image content-type**, anonymous → login, foreign estate → **404**; draft
image removal clears the column; archived notice image rejected. Rollback: redeploy pin `f11367c`
code (schema additive-compatible).

## Next

- **Final combined baseline suite green** → flip to **ACCEPTED** and rebuild the Inc-12/13 bundle on
  the Lead's **“bundle go”**.
- Then **Inc-11** (estate billing read + reports/exports, DRAFT contract) remains pending.
- Keep live-preview state documented (applied=11, not accepted) until the gate.

## Evidence artifacts (local/gitignored unless noted)

`storage/temporary/inc13-e2e-result.json` (image checks 13/13; full 129/0/1) · `inc13-full-run.log` ·
`inc12/20261111_000012_add_estate_notice_images.sql` (staged copy) · `inc12/DESIGN-NOTES.md`,
`inc12/validate-stage.php`, `inc12/cleanroom-gate.php`, `inc12/probe-image-columns.php` ·
`inc13-sql-image-row.txt` / `inc13-sql-image-uuid.txt` · `tests/_baseline2-full.log` (final suite, in
progress) · this record + [`increment-13-acceptance.md`](increment-13-acceptance.md) +
`docs/changelog.md`.
