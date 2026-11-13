# Changelog — MtaaPass / DashPASS

**Current release:** `0.2.1-dashpass` · **Date:** 2026-11-11 (Africa/Nairobi)
**Repo:** <https://github.com/bodaboraapp-commits/dashpass.africa> (branch `main`)
**Environments:** development `http://127.0.0.1:8010` · staging/live `https://dashpass.africa`
**Product name:** config-driven — **MtaaPass** in development, **DashPASS** in production
(`APP_NAME` / `APP_SHORT_NAME`); the same codebase serves both.

Release notes are newest → oldest. Each increment links its binding contract and acceptance
record. Deployment steps live in [`deployment-cpanel.md`](deployment-cpanel.md), the owner
go-live packet in [`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md), and rollback/DB policy
in [`deployment-rollback.md`](deployment-rollback.md).

> **LIVE PREVIEW (2026-11-12, owner decision — no rollback):** live runs the **Inc-13/Inc-12 WIP**
> code deployed via `git pull`, with migrations **applied=11 / pending=0** (**42 tables / 84 FKs /
> 52 permissions / 13 roles / 217 links**) and **routes 183 at the E2E run** (local `main` at
> commit `8c407e5` is now **196** = 152 web + 44 API after the Inc-13 schedule/Inc-11 batch incl.
> the `/about` redirect and the resident Activity/Inbox screens); Firebase login **active**;
> billing, **notice-image** and incident routes live. **Not an accepted release** — the baseline
> suite, P2/P3 slices and Inc-13 acceptance are still pending (Inc-11 also pending).
> **Rollback path:** release pin **`f11367c`** = Inc-10 bundle artifact
> `storage/temporary/mtaapass-deploy.zip` (sha256
> `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`; archive
> `storage/temporary/f11367c.zip`), schema additive-compatible and migrations forward-only. The
> **UI hotfix is effectively live** (code included) and `storage/temporary/ui-hotfix-inc10.zip` is
> retained for rollback/reference. Known gaps: P2 settings forms, resident Account card removals /
> estate Appearance paused.
>
> Increment history on live: Inc-9 build (158 routes, applied=8) → Inc-10 (165 routes, applied=9,
> bundle pin `f11367c`) → current Inc-13/Inc-12 **preview** (183 routes at E2E → **195** current tree, applied=11).

---

## Unreleased — next release (in progress)

> **Not yet released.** Gates are pending; do not treat the expectations below as live until the
> Lead opens the DB/route gates and the release agent's bundle refresh lands.

### FINAL VERIFICATION GATE PASSED — 956/956 on the frozen tree (2026-11-13)

- **Frozen artifact:** commit **`cb244a5`** in an untouched git worktree
  (`storage/temporary/gate-tree`), `git status` empty before and after; the gate itself ran in a
  DB-isolated copy whose only deviation was the test-bootstrap database name
  (`mtaapass_test` → private `mtaapass_gate_test`) with a 549-file sha256 manifest proving every
  other tracked file byte-identical to the frozen snapshot.
- **Exclusive full suite: Total 956 / Passed 956 / Failed 0 / exit 0** (2097.50 s).
  **19/19 targeted filters PASS (165 tests):** LegalPrivacyPagesTest 12, ResidentActivityTest 7,
  ResidentNavigationChevronTest 3, ResidentPwaHomeTest 12, ResidentPwaParkingTest 8,
  ResidentPwaBookingsTest 8, PublicPassPageTest 7, ResidentPwaMarkupTest 7, GuardGateTest 10,
  AiCrawlerSeoTest 7, BlogSeoTest 9, LoginLandingTest 11, RouteRegistrationTest 8,
  HeadersTest 8, BillingChannelsTest 8, HelpersTest 10, PhoneRegistrationCheckTest 7,
  MarketingPagesTest 7, FirebaseSecurityTest 7.
- **Rendered layout gate 29/30:** vehicles add-vehicle `<details>` closed/single summary/form
  inside; activity single-row redesign (avatar/body/aside status+when/View pass); pass hero
  name+status chip and meta order Estate/Host/Household/Visitors; six dashboard tiles and
  hrefs; `/app/bookings?tab=bookings` active tab. The one layout FAIL was the cosmetic em dash
  in a CSS comment, fixed immediately afterwards.
- **Hygiene:** routes **212 (168 web + 44 API)**, migrations **12**, zero em/en dashes in
  app/public/config after the follow-up cleanup, no untracked artifacts.
- Evidence: `storage/temporary/qa-evidence/GATE-REPORT.md`, `baseline-final-full.log` /
  `.PASS.log`, `ATTEMPT5-PASS-NOTE.txt`, `filters/`, `layout-gate-results.json`, plus archived
  attempt-1→4 notes (kill, dirty tree, external-slice mutation, shared-test-DB collision).
- **Post-gate commits** (landed after the frozen snapshot, verified with the targeted filters
  above): `6ad158d` shared article layout + faster OTP sign-in, `94df0df` Firebase error
  reference only for real codes, `b448b8b` two-month date-range calendar, `0c17dd6` action-row
  spacing, plus this cleanup commit (login-copy/CSS-comment em dashes removed; SVG namespace
  constant allow-listed in `FirebaseSecurityTest` for the new date-range script; zero dashes).

### Inline two-month calendar for the invitation validity window (2026-11-13)

- **Owner request:** the create-invitation "Validity window" no longer shows the two
  `Valid from` / `Valid until` boxes. The two-month range calendar is rendered directly in
  the card (pre-opened) so the resident taps the first and last day, with the times still
  editable underneath; the calendar stays open and re-renders in place after the second pick.
- **Progressive enhancement:** `date-range.js` gains an inline mode (`data-date-range-inline`
  plus a `data-date-range-inline-host`), which hides the replaced field boxes while keeping
  the native `datetime-local` inputs in the form as the no-JS fallback. Server validation still
  owns the required check (hidden native controls cannot receive browser focus), and fields
  carrying a server error stay visible.
- Tests: `DateRangePickerTest` 6/6, including the new inline-calendar contract assertions.

### Visitor pass: Gate QR code card first (2026-11-13)

- **Owner request:** the Gate QR code card is now the first card on the visitor pass screen,
  ahead of the visitor summary, Access PIN, Visitor parking and Pass actions, so the code a
  guard scans is immediately visible.
- No route, database or API changes: the QR image/source, copy-link actions, pass link and
  offline-cache hooks are unchanged; only the card order moved.
- Tests: `ResidentPwaQrSheetTest` 6/6, `OfflineAccessStaticTest` 6/6, `VisitorSecurityTest`
  8/8 and `ResidentPwaMarkupTest` 7/7 re-run after the reorder.

### Visitor pass: merge parking into the visitor summary (2026-11-13)

- **Owner request:** the standalone "Visitor parking" card is now merged into the visitor
  summary card. Valid window and Visitor parking sit side by side as compact tiles (parking
  shows bay, zone, window and the Reserved chip); the separate card and its long hint are gone.
- Copy trimmed for a cleaner screen: one-line QR hint, shorter PIN/offline notes, and the
  status sentence is only shown for non-approved passes (the chip already says Approved).
- No route, database or API changes; parking data still comes from the same pass payload and
  all offline/QR hooks are untouched.
- Tests: `ResidentPwaParkingTest` 8/8, `ParkingReservationFlowTest` 8/8,
  `OfflineAccessStaticTest` 6/6 and `ResidentPwaMarkupTest` 7/7.

### Legal & privacy centre: policy pages, opt in/out and delete-my-data requests (2026-11-13)

- **Owner request:** the landing-page footer now carries **Privacy Policy**, **Terms & Conditions** and
  **Data Policy** links; each opens its own public page. The footer deliberately stays at those three
  notices (owner feedback: five links were too crowded) — the opt in/opt out and delete-my-data pages
  are one tap away from the Privacy Policy page and the sticky legal sidebar.
- **New public pages:** `/privacy` (Kenya Data Protection Act, 2019-aligned notice), `/terms`,
  `/data-policy` (controller/processor roles, data inventory, retention schedule, security
  controls, sub-processors, request handling), `/privacy/choices` (opt in/opt out plus
  access/correction forms) and `/data-deletion` (dedicated erasure request page with an explicit
  acknowledgement).
- **Requests are real, not cosmetic:** `POST /privacy/choices` validates and stores each request
  in the new `privacy_requests` table with an opaque `DP-YYYYMMDD-XXXXXX` reference and notice
  version, writes a PII-free audit entry, and emails the configured privacy inbox
  (`MAIL_PRIVACY_TO` → `PRIVACY_EMAIL` → contact/alert/support fallback). The visitor only ever
  sees the opaque reference.
- **Hardening:** CSRF, `throttle:privacy,5,600`, per-form honeypot, phone normalisation,
  explicit deletion acknowledgement and no external assets/scripts (strict CSP intact).
- **Config:** `config/privacy.php` centralises the legal entity, registered address, privacy/DPO
  emails, response window (30 days), governing law and notice version; `.env.example` documents
  the new variables.
- **SEO:** the four notice pages are added to `sitemap.xml`.
- **Shared long-form layout:** the same hero + sticky contents/actions sidebar + reading panel
  (generalised from `legal-*` to `doc-*` classes) now also renders every blog article at
  `/blog/{slug}`, with an on-this-page link list generated from the post section headings.
- **DB/route delta:** +1 migration (`20261111_000013_create_privacy_requests.sql`; 43 tables,
  84 FKs unchanged) and +6 routes (212 total). Tests: `LegalPrivacyPagesTest`, plus updated
  `RouteRegistrationTest`, `LoginLandingTest`, `MigrationTest`, `HealthTest`, `BlogSeoTest`,
  `AiCrawlerSeoTest` and `BillingPastDueJobTest`.
### Faster sign-in: OTP autofill, paste parsing and auto-verify (2026-11-13)

- The verification-code field now advertises browser/OS one-time-code autofill
  (`autocomplete="one-time-code"`, numeric keyboard, `enterkeyhint="done"`), parses a pasted SMS
  body down to the actual code (ignoring sender/phone/date digits) and verifies automatically as
  soon as all digits are present — on both the server-OTP and Firebase paths. Duplicate
  auto-submits are guarded so one code is only posted once.
- Development speed-up: with the dev SMS adapter the code is prefilled into the field by the server
  and auto-verified by `app.js`, so a local sign-in completes without retyping. Production never
  renders the code to the browser.
- Error copy: the support-facing `Reference: ...` line is reserved for real Firebase `auth/...`
  codes; internal pre-flight states such as an unregistered number no longer show a reference code.
- Tests: new `OtpSpeedTest` (input autofill attributes, dev prefill + auto-submit, paste/autofill
  script hooks); Firebase/security, web-flow and PWA-install suites re-run.

### Resident card designs: vehicles, visitor pass and activity timeline (2026-11-13)

- **Vehicle cards** (owner design): icon tile, plate with the status chip inline beside it,
  type/colour line underneath and the Deactivate action vertically centred on the right;
  cards stack to two rows at ≤380px so the action can never be pushed off-screen.
- **Visitor pass hero** (owner design): visitor name with the status chip inline, purpose
  line, a four-column meta grid (Estate / Host / Household / Visitors), a stronger
  valid-window panel (uppercase label, bold `from, to` dates, timezone below) and the
  existing hint line; meta collapses to 2 columns ≤767px and 1 column ≤390px.
- **Activity timeline cards** (owner design): single-row card with a navy initials avatar,
  visitor name/event label/household line, the event status chip, the clock/time (and gate
  when present) and a right-aligned **View pass** action; wraps into tidy rows ≤720px.
- **Add-vehicle toggle**: exactly one button is visible at a time — the "+ Add vehicle"
  summary when closed (52px touch target on phones), the form's submit when open;
  validation errors auto-open the form.
- No route/DB/permission changes. Tests: `ResidentPwaParkingTest` 8/8,
  `ResidentPwaHomeTest` 12/12, `ResidentPwaBookingsTest` 8/8, `ResidentPwaQrSheetTest` 6/6,
  `ResidentPwaAccessTest` 5/5, `WebFlowTest` 10/10, `ResidentActivityTest` + navigation
  matrix re-run in the final gate.

### Resident dashboard tiles + collapsible add-vehicle form (2026-11-13)
- **Owner request:** the `/app/vehicles` "Add a vehicle" form is now hidden until the
  resident taps **+ Add vehicle**. It lives inside a native `<details class="add-vehicle">`
  with a button-styled `<summary>` (no JavaScript, so the plain-POST form still works
  without JS); it is closed by default, opens on tap, and server-side validation errors
  auto-open it so the resident sees the field errors. `ResidentPwaParkingTest` asserts
  closed-by-default, the summary toggle, the form-inside-details structure and
  reopen-on-error.
- **Dashboard quick-tile labels (owner request):** Access/**My Pass**, Visitors/**My Guest**
  (tile opens the Visitors list with its Invite CTA), Payments/**My Bills**,
  Bookings/**Facilities**; `ResidentPwaHomeTest` updated (12/12). README dashboard row
  synced.
- No route, DB, permission or config change. Tests: `ResidentPwaParkingTest` 8/8,
  `ResidentPwaHomeTest` 12/12.

### Login pre-flight registration check before Firebase OTP (2026-11-13)

- **Owner requirement:** never waste a Firebase SMS OTP on a phone number that is not
  registered on the platform. The Firebase login UI now calls a new server endpoint
  **`POST /login/check-phone`** (`guest` + `throttle:phone-check,30,60`, CSRF-protected)
  before it calls `signInWithPhoneNumber`.
- **Server:** `AuthController::webCheckPhone` normalises the number (E.164, local `07…`
  accepted) and allows the OTP only when there is an **active user with at least one
  active estate membership**; otherwise it returns `{ok:true,registered:false}` without
  creating any OTP challenge or sending SMS. Invalid numbers return `422`
  `{error:invalid_phone}`. The endpoint never creates OTP challenges itself (asserted by
  tests) and reveals only a boolean registration state.
- **Client (`public/assets/js/firebase-auth.js`):** checks `/login/check-phone` first
  (sending `X-CSRF-Token`); unregistered numbers get "This number is not registered with
  an estate yet. Ask your Estate Administrator to register you…" and **no Firebase call is
  made**. The check fails closed (network/server error → retry message, no OTP attempt) and
  the existing Firebase error mapping/retry behaviour is unchanged for registered numbers.
- **Tests:** new `PhoneRegistrationCheckTest` 7/7 (registered allowed, local format,
  unregistered rejected with no OTP challenge created, suspended user rejected, invalid
  `422`, CSRF `403`, static JS ordering + messages). `RouteRegistrationTest`/`LoginLanding`
  route counts updated 205 → **206 (162 web + 44 API)**; `WebFlowTest` 10/10,
  `FirebaseLoginErrorTest` 7/7 and `FirebaseLoginTest` 10/10 remain green.
- Legacy server-OTP mode is unchanged (no real SMS in that path); the check targets the
  Firebase client flow where OTPs actually cost quota.

### AI-crawler layer, blog cover images, contact-form email + mobile landing (2026-11-13)

- **AI/search crawler layer (routes 202 → 205 = 161 web + 44 API):** new `GET /llms.txt`
  (llmstxt.org-style agent guide: product summary, absolute links to all public pages + the
  six posts, support contact, image-licence note), `GET /llms-full.txt` (full plain-text
  corpus of the six posts for LLM ingestion) and `GET /feed.xml` (valid RSS 2.0, six items,
  `application/rss+xml`). `robots.txt` now explicitly allows the major search/AI agents
  (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, anthropic-ai, PerplexityBot, Googlebot,
  Google-Extended, Bingbot, CCBot, Applebot-Extended) and references the AI guide;
  production meta robots adds `max-snippet:-1, max-image-preview:large,
  max-video-preview:-1`; guest pages carry an RSS alternate link; `sitemap.xml` adds
  sitemap-image entries for the six cover images. The narrow public-cache exception now
  covers only the five SEO/AI GET documents (GET-only; everything else stays no-store).
  Tests: `AiCrawlerSeoTest` 7/7, `BlogSeoTest` 9/9, `RouteRegistrationTest` 8/8,
  `LoginLandingTest` 11/11, `WebFlowTest` 10/10, `HeadersTest` 8/8.
- **Blog cover images replaced (smaller + more relevant):** six local 960×540 WebP covers
  (52–86 KB each, down from 1280×720 at up to 188 KB), re-sourced for relevance (gated
  building security staff, gated estate entrance/guard post, keycard access, Nairobi
  M-Pesa agent, parking boom barrier, CCTV) from Wikimedia Commons; credits/alt/og:image
  updated and documented in `docs/blog-image-credits.md`. The blog index renders them as
  compact thumbnails (desktop thumbnail beside copy, stacked ≤767px) and the post hero is
  capped at 720px.
- **Help contact form email:** the form now sends through the project's Resend SMTP mail
  layer with a dedicated `MAIL_CONTACT_TO` inbox (fallback `MAIL_ALERT_TO` →
  `app.support_email`) and the visitor as Reply-To. `scripts/mail-test.php --check` confirms
  SMTP authentication works; a live send currently returns `550` because
  `onboarding@resend.dev` is a testing-only sender — the fix is verifying
  `dashpass.africa` in Resend and setting a verified-domain `MAIL_FROM_ADDRESS`, documented
  with exact owner steps in `docs/contact-form-email.md`. No DB/migration.
- **Mobile landing + blog thumbnails (app.css):** ≤900px single column with hero copy
  first (fixes the old artwork-first rule), ≤640px phone typography, wrapping guest header
  (PASS·PAY·ABOUT·BLOG·HELP? + Login + theme toggle), stacked full-width CTAs, single
  column value props, scaled accents and non-overflowing footer; tablet keeps two-up CTAs
  and two-column value props; desktop >900px unchanged. Verified at 360×800, 390×844 and
  768×1024 with emulated headless Chrome (scrollWidth == innerWidth at 360px, no
  overflowing elements).
- **Billing channel ledger fix:** `notification_logs.status` is
  `ENUM('queued','sent','failed','delivered')` and has no `skipped` value, so disabled
  SMS/WhatsApp rows were silently dropped (truncation warning swallowed). Skipped channels
  are now normalised to `failed` with the precise reason in `error_code` (same convention as
  `NotificationLogger`), fixing the two pre-existing `BillingChannelsTest` failures (now
  8/8). `HelpersTest` placeholder assertions updated from the removed em-dash to `-` for
  the copy sweep.
- Tests overall for this slice: `BlogSeoTest` 9/9, `AiCrawlerSeoTest` 7/7,
  `BillingChannelsTest` 8/8, `HelpersTest` 10/10, `MarketingPagesTest` 7/7,
  `WebFlowTest` 10/10, `ResidentPwaMarkupTest` 7/7, `RouteRegistrationTest` 8/8,
  `LoginLandingTest` 11/11, `HeadersTest` 8/8. No DB migration, permission or config change.

### SEO blog launch — six long-form posts + crawl infrastructure (2026-11-13)

- **Six AI-written, SEO-optimised blog posts** published at `/blog` and `/blog/{slug}`
  (700–950 words each, Kenya/East-Africa estate context): digital visitor passes vs paper
  registers; the true cost of gate queues; automated access rules (single-use passes,
  recurring schedules, contractor windows); frictionless payment collection; QR/number
  plate/boom-barrier hardware; real-time logs + blacklisting playbook. Content source
  `app/Modules/Marketing/BlogContent.php` (plain-text strings, escaped at render).
- **Blog rendering:** index lists posts newest-first (category, `<time datetime>`, reading
  time, excerpt); post pages have breadcrumbs, sections with bullets, a 3-question FAQ,
  related reading/internal links and CTAs. BlogPosting/BreadcrumbList/FAQPage/Organization
  **schema.org microdata** (no inline JSON-LD — CSP stays `script-src 'self'`).
- **Crawl/SEO infrastructure:** dynamic `GET /sitemap.xml` (all public pages + six post
  URLs + `<lastmod>`, `application/xml`, public `max-age=3600`) and `GET /robots.txt`
  (production allow + Sitemap + private-path disallows; non-production deny-all). SEO head
  block: canonical, robots (forced noindex off production), keywords, Open Graph, Twitter
  card, article published/modified/author. Narrow `SecurityHeaders` exception: only GET
  `/sitemap.xml` + `/robots.txt` skip the global no-store cache override.
- **Cover images:** every post now ships a local 1280×720 WebP cover sourced from
  Wikimedia Commons (CC0 / CC BY / CC BY-SA, cropped and resized, credited on-page), used
  on the blog index, the article hero and as `og:image`/`twitter:image`; full sources and
  licences documented in `docs/blog-image-credits.md`. Descriptive alt text on every image
  for accessibility and image SEO. No external asset hosts (CSP/offline unchanged).
- **Route table 199 → 202 (158 web + 44 API)**; no DB migration, no new permission, no new
  config, no external assets. Tests: `BlogSeoTest` 8/8, `MarketingPagesTest` 7/7,
  `RouteRegistrationTest` 8/8, `WebFlowTest` 10/10, `LoginLandingTest` 11/11,
  `HeadersTest` 8/8 (plus FirebaseSecurityTest + ResidentPwaMarkupTest regressions).

### Public marketing pages + guest nav (2026-11-13)

- **Guest header nav** is now **PASS · PAY · ABOUT · BLOG · HELP?** (bold labels; HELP? is a real page
  instead of the old `mailto:`), with the Login pill unchanged.
- **New/real public pages:** `GET /about` now renders a proper About page (no longer a 302 to the
  removed `/#about` anchor); `GET /blog` renders an honest blog index ("No posts published yet — check
  back soon"); `GET /help` renders 8 native `<details>/<summary>` FAQs plus an accessible contact form
  (CSRF + validation + `throttle:contact,5,600`, hidden honeypot) delivered through the existing mail
  infrastructure (`POST /help/contact`, no database table). All pages use the guest layout, escaped
  output, no inline JS/CSS and no external assets.
- Landing copy/artwork from the same owner pass: new hero lede ("Eliminate queues with DashPASS…"),
  six short Material-icon value props (label-only, no descriptions), and the transparent
  "It's Super Simple!" QR ad artwork shown without the square card.
- **Route table 196 → 199 (155 web + 44 API)**; no migration, no new permission, no new DB table.
  Tests: `MarketingPagesTest` 7/7, `WebFlowTest` 10/10, `RouteRegistrationTest` 8/8,
  `LoginLandingTest` 11/11.

### Resident app — "coming soon" cleanup complete (2026-11-12)

The resident PWA no longer has any "Coming soon" placeholders or Soon toast actions. Everything
previously labelled as a future increment is now a real, server-rendered destination:

- **Payments & Bills** Home quick tile is a real link to **`/app/bills`**; the Home announcements
  **View All** is always a real link to **`/app/notices`**; **Account** gained a
  **Bills & payments** card (**View my bills → `/app/bills`**) — commit `25920c5`.
- **Inbox** in the sidebar and the bottom navigation is a real link to the owner-scoped
  **`/notifications`** inbox (filters, unread counts, mark-read) with an active state on that
  screen — commit `0438467`.
- **Activity** is a new resident screen at **`/app/activity`** (commit `8c407e5`): the signed-in
  resident's own-household visitor timeline, merging gate access events
  (`check_in` / `check_out` / `denied` / `manual_override`) with invitation-issue events, newest
  first (limit 50). Scope is derived only from `App\Core\Context` (active estate + active household
  memberships via `UserHouseholdRepository::idsForUser()`); a user without households gets an honest
  empty state with an **Invite Visitor** CTA and no query ever touches another household. The
  sidebar item and bottom-nav slot are real links with `aria-current` active state; every dynamic
  value is escaped, there is no inline JS/CSS, and the screen reuses the resident design system.
- **Route table 195 → 196 (152 web + 44 API)**; **no new permission node, no migration, no config
  change**. `RouteRegistrationTest` / `LoginLandingTest` counts updated with the route entry.
- **Evidence:** `ResidentActivityTest` 7/7, `ResidentPwaHomeTest` 12/12, `RouteRegistrationTest`
  8/8, `LoginLandingTest` 11/11, `--filter=Resident` **121/121 PASS**, `php -l` clean on every
  changed file; QA baseline `HealthTest` 6/6 + `NotificationBellTest` 12/12 and a 32/32 live smoke
  on the dev host. The **exclusive full suite is still to be re-run** after these commits.
- **Release note:** the accepted code-gate bundle pin **`4d492b5`** (484 entries, sha256 `A36A71CF…`)
  predates commits `25920c5`, `0438467` and `8c407e5` — the release/rollback artifact must be
  rebuilt (bundle go) before any deploy. These commits are code/view/test only (no migration), so
  production activation still only needs the owner's git pull + cache/PWA refresh step.

### Resident offline access pass + navigation polish (2026-11-12)

- **Offline access pass** (`6bba5cf`, contract [`offline-access-contract.md`](offline-access-contract.md)):
  the resident's gate **QR (and PIN when explicitly saved) is available without data** and the app shell
  opens offline. Service worker **v8** (`mtaapass-shell-v8` / `mtaapass-static-v8`) precaches
  `offline-access.js`; navigations stay network-first with `/offline.html` as the **offline Access pass
  card**. The cache is **AES-256-GCM ciphertext in IndexedDB** (`mtaapass-access`) under a
  **non-extractable WebCrypto device key**: QR PNG bytes, plus the PIN only when the resident taps
  *Save PIN on this device* while it is displayed (creation/rotation) — the PIN stays **unrecoverable
  server-side** (HMAC show-once model, no API/migration change). Entries expire at the pass `valid_until`,
  terminal passes are purged, and sign-out (online or offline) wipes the credential cache, device key and
  service-worker caches. `/api/*`, `/health` and `/app/passes/{uuid}/qr.png` remain **never cached or
  intercepted**; `Cache-Control: no-store` and CSP are unchanged. **No migration, route, permission or
  config change.** Static tests: `OfflineAccessStaticTest` (7) + updated `PwaInstallStaticTest`.
- **Resident navigation chevrons** (`632a1a5`): text-only "Back" controls on `bills.php`/`bill.php`
  replaced by the standard `chevron-left` screen-bar action; `staff.php` and `incidents.php` gained the
  missing back-to-account chevron (incidents keeps its report action); `navigation.php` intentionally
  stays a home-level screen with its explicit Back to Home link. New `ResidentNavigationChevronTest`
  asserts a per-screen back-control matrix (exactly one plain-anchor chevron, correct parent href and
  aria-label, none on root screens).
- **Verification status (close-out, 2026-11-12 20:40 EAT):** navigation polishing is green —
  `ResidentNavigationChevronTest` **3/3**, `ResidentPwa` **58/58**, browser functional back-chevron matrix
  **17/17** on the dev host (44×44 plain-anchor chevrons, correct parents, HTTP 200, no JS errors; root
  Home/Account none). Offline statics green — `OfflineAccessStaticTest` **6/6**, `PwaInstallStaticTest`
  **8/8**, `HeadersTest` **8/8**, `VisitorSecurityTest` **8/8**. The exclusive full suite ran
  **897 total / 894 passed / 3 failed**: one failure was the PWA static test running stale code while the
  task-5 edit landed mid-run (passes now), and two are a **pre-existing billing-notification defect**
  (`BillingChannelsTest` skipped-SMS row records an empty `error_code` instead of `sms_not_configured`;
  reproduces in isolation, no billing code was touched this session) — triage queued. A runner-blocking
  test-helper name collision (`dom()` vs `Tests\TestCase::dom()`) was found and fixed (`parsedHtml()`).
  Do **not** claim a full-suite baseline for HEAD until a clean run; the release bundle also still needs
  a rebuild at "bundle go".

### Firebase real-number sign-in — urgent diagnosis + error-surfacing fix (2026-11-12) — **RESOLVED 2026-11-13**

- **RESOLVED (owner confirmed 2026-11-13):** the live failure was a **reCAPTCHA site-key domain
  configuration** problem (key `6LcMZR0U…` owned by the producer Google Cloud project did not accept the
  live login origin; Firebase test numbers bypass app verification, which masked it). The owner corrected
  the console configuration and **real-number sign-in now works on production**. No application code
  change was needed for the fix; the client-side hardening below remains useful for future diagnosis.
- **Owner report:** only Firebase **test phone numbers** could sign in; real numbers failed. Live
  diagnosis (evidence in `storage/temporary/qa-evidence/firebase-login-urgent/`) confirmed the app is
  correct: Firebase mode/public config, deployed JS hash == repo, CSP complete, test-number Identity
  Toolkit `sendVerificationCode` → 200, server token verification has **no test-number bypass**, and
  the legacy server-OTP endpoints stay closed (`AUTH_PROVIDER_MISMATCH`). Test numbers bypass
  reCAPTCHA **and** real SMS delivery/quota, so the cause is Firebase console config: **billing/Blaze
  + SMS quota**, **reCAPTCHA/app verification**, **SMS region policy** (Kenya +254) or authorized
  domains — runbook added to [`firebase-sms-otp.md`](firebase-sms-otp.md) §9b with the exact owner steps.
- **Code fix (`public/assets/js/firebase-auth.js`):** added missing/actionable error mappings
  (`auth/billing-not-enabled`, `auth/app-verification-failed`, `auth/missing-app-credential`, improved
  captcha/quota/domain/operation-not-allowed/referrer wording), a non-sensitive
  `Reference: auth/<code>` line in the error box for support, and a fix for the real-number-only
  reCAPTCHA retry bug ("reCAPTCHA has already been rendered in this element") that could lock users
  out after changing/re-sending a number.
- **Tests:** new `FirebaseLoginErrorTest` (7 static/served-page checks) and `--filter=Firebase`
  **44/44 PASS**; no migration, route, permission or config change.

### OTP channel — Firebase phone auth (approved default; implemented in dev, live activation pending tests)

- **Default OTP gateway changed to Firebase phone auth**: `AUTH_OTP_PROVIDER=firebase`,
  `FIREBASE_SMS_ENABLED=true`, project **`komiuta`**. The browser Firebase SDK sends/verifies the
  SMS; the server verifies the Firebase **ID token** submitted through the existing
  `POST /login/verify` (hidden `firebase_token`) against Google's public x509 certificates — **no
  service-account JSON/secret** and no server SMS credentials required. Failures map to
  `INVALID_FIREBASE_TOKEN` (401) / `FIREBASE_PHONE_INVALID` (422) / `SERVICE_UNAVAILABLE` (503);
  success audits `auth.firebase_login`, failures `auth.login_failed` (provider/reason only).
- **CSP:** Firebase/reCAPTCHA origins added; `style-src 'unsafe-inline'` is deliberately scoped to
  the reCAPTCHA badge **only** — the script policy stays strict (no inline scripts/eval).
- **Updated web config + SDK (T131/T133):** login UI now uses **Firebase modular SDK 13.2.0**
  (static ESM imports from gstatic via an external `type="module"` script, no inline config, no
  compat globals; **analytics intentionally omitted**). Refreshed public values:
  `FIREBASE_APP_ID=1:99186909972:web:ad3900fddee22fe6b91801`,
  `FIREBASE_MEASUREMENT_ID=G-D9JVCQJG9V` (apiKey/authDomain/projectId/storage/messagingSender
  unchanged). Project id is unchanged, so **server token verification is unaffected**; CSP script
  policy stays strict (gstatic already allowed).
- **Owner setup required before production login works**: Firebase console → Phone sign-in
  enabled; authorized domains `dashpass.africa`, `www.dashpass.africa`,
  `brim-liberty-liqueur.ngrok-free.dev`, `localhost`, `127.0.0.1`; reCAPTCHA configured; budget/quota
  checked; then one real OTP round-trip. Full guide + troubleshooting:
  [`firebase-sms-otp.md`](firebase-sms-otp.md).
- **Fallback/rollback:** `AUTH_OTP_PROVIDER=server` keeps the server-generated OTP path; a real SMS
  gateway (Africa's Talking/Twilio etc.) still needs provider credentials + an adapter (only
  `log`/`mock` adapters exist today), so Firebase is the default. No DB change — `/health` stays
  applied=8.
- **Status:** implemented in dev (T127/T128) and **ACTIVATED ON LIVE (verified 2026-11-11):** the
  owner applied the server `.env` (`AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true` + the
  public `FIREBASE_*` values, project `komiuta`). Live `/login` now renders the Firebase markers
  (`data-firebase-auth`, provider `firebase`, appId `1:…:web:ad3900fddee22fe6b91801`, measurement
  `G-D9JVCQJG9V`, SDK **13.2.0** module script + reCAPTCHA container) and the read-only probe
  reports **`activationDetected=true`**; the T138 guard (API `request-otp`/`verify-otp` → **403
  `AUTH_PROVIDER_MISMATCH`**) remains in force. **This is separate from Inc-10, which is NOT
  deployed** — live `/health` is still applied=8 and `/platform/billing` returns 404 until the
  Inc-10 deploy (expected). No Inc-10 release claim.

### Auth-provider enforcement + billing hardening (T138, unreleased)

- **Auth-provider enforcement:** with `AUTH_OTP_PROVIDER=firebase` the legacy server-OTP entry
  points are closed — API `POST /api/v1/auth/request-otp` / `verify-otp` → **403
  `AUTH_PROVIDER_MISMATCH`**, web `POST /login/otp` → generic redirect to `/login`, web
  `POST /login/verify` requires the hidden `firebase_token`; `provider=server` is unchanged. Guards
  run before validation/rate-limits, messages are generic (no account enumeration) and audit
  records `auth.login_failed` with reason `PROVIDER_MISMATCH` + endpoint only (no PII). A
  dev/tooling-only `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` (default `false`) can re-enable the legacy
  path outside production; it is **force-disabled in production** — never enable it on live.
- **Billing hardening (pre-gate):** invoice-number allocation reads the per-estate latest number
  with a row lock (`SELECT … FOR UPDATE`) inside the create transaction and retries **only** on a
  duplicate-key error 1062 (`UNIQUE(estate_id, invoice_number)`, max 5 attempts); any other DB
  failure surfaces immediately. Invoice creation accepts a `save_draft` boolean (creates `draft`
  instead of `open`), and `scripts/billing-past-due.php --estate=` with an empty value now exits
  **2** (usage error) instead of falling back to all estates.
- **Status:** both are unreleased hardening inside the gated Firebase/Inc-10 work — no release
  claim; Inc-10 remains gated/unreleased.

### Increment 10 — Billing / invoices (DELIVERED — gated bundle pin `f11367c`)

*Contract:* [`billing-invoices-contract.md`](billing-invoices-contract.md) · *Acceptance:*
[`increment-10-acceptance.md`](increment-10-acceptance.md) (**ACCEPTED — DELIVERED**) ·
*Status:* **released as the Inc-10 gated bundle** — final suite **757/757 PASS, 0 FAIL, 1576.72s,
exit 0** (`tests/_inc10-final.log`); bundle `storage/temporary/mtaapass-deploy.zip` =
`409` entries / `1,203,313` B / sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, frozen at pin
**`f11367c`**. Live already received the code (repo pull) and the Lead applied **migration 10** via
`deploy-sync install` — `/health` is **applied=9 / pending=0** (DB `tokeqvlx_dashpass_db`, seeded;
`/platform/billing` → **302 `/login`** unauth; expected seed 2 invoices + 1 payment — confirm with
the integrity check). The bundle is the release artifact + rollback record for fresh deploys and
re-deploys. Live Firebase is separately **ACTIVATED**.

- **Migration 10** (`20261111_000010_create_billing_tables.sql`): two additive tables —
  `billing_invoices` + `billing_payments` (append-only payment ledger) — plus an additive
  `subscriptions (id, estate_id)` unique index for the invoice composite FK. No other table/seed
  row is touched; no hard deletes. After the gate: **9 migrations / 39 tables / 52 permissions /
  13 roles / 217 role links / 72 FK constraints / 2 invoices + 1 payment** (Greenview only).
- **Routes 158 → 165** (web **121** + API 44): 7 platform-only billing routes
  (`/platform/billing`, invoices new/create/show/status, payments record/status) — `['auth']` +
  controller platform-area gate + `billing.invoice.view` / `billing.payment.manage`; estate users
  **403**, unauthenticated **302**; no new APIs.
- **Past-due sweep:** `php scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]` moves overdue
  `open` → `past_due` (audited, idempotent, server-side/cron; empty `--estate=` exits **2**).
- **Gate evidence:** final suite **757/757 PASS / 0 FAIL / 1576.72s / exit 0** (`_inc10-final.log`,
  `_inc10-final-exit.txt`); Lead DB gate **9/39/52/13/217/72 FK/2 invoices + 1 payment** with
  integrity **26/26** and cleanroom **28/28**; route gate readiness `ready=true` (165 routes, all 7
  billing routes + gates, seeded invoices/ledger, Daniel 200 / Grace 403 / unauth 302); backend
  proof **55/55** and numeric-bounds **24/24**; **T111 billing UI/smoke 49/49 PASS**
  (nav+quick-link, list/new/detail/payment/auto-paid/void-terminal, 403/302/404 matrix; harnesses
  127/95/46/25/76); **qa-e2e E2E 70/72 (0 FAIL, 2 SKIP)** with regressions Inc-5 71/71 · Inc-6
  95/96 · Inc-7 78/80 · Inc-8 101/102 · Inc-9 67/68 — two non-blocking observations tracked as
  **T147** (platform audit viewer excludes `billing.*`; month filter uses created-month).
- **Deploy:** `POST /deploy-sync.php?action=install` **required** for servers behind migration 10 →
  `/health` **applied=9 / pending=0**, **39 tables / 217 links / 2 invoices + 1 payment**, routes
  **165**; current live already applied=9 (bundle = release/rollback artifact). Estate billing read
  surfaces are deferred to **Inc-11**.

### Increment 13 — Automated billing & notifications (GATED — pending final suite)

*Contract:* [`automated-billing-contract.md`](automated-billing-contract.md) · *Acceptance:*
[`increment-13-acceptance.md`](increment-13-acceptance.md) (**GATED — pending final suite**) ·
*Status:* **built, E2E green, NOT released** — full E2E **130 checks → 129 PASS / 0 FAIL / 1 SKIP**
(`inc13-e2e-result.json`; only SKIP = `expired-at-publish fanout` fixture) with **Inc-5…Inc-10
regressions PASS**; gates **11 migrations / 42 tables / 84 FKs / 52 permissions / 13 roles / 217
links**, **routes 183 at the E2E run** (current tree **195** = 151 web + 44 API). Live preview (owner decision, no rollback) has it
deployed via git pull at **applied=11/pending=0** — but this is **not an accepted release** until
the final combined baseline suite is green (`tests/_baseline2-full.log` running) and the
Inc-12/13 bundle is rebuilt on “bundle go”. Includes migration 11 (`billing_schedules`,
`utility_charges`, `platform_settings`, notification markers, nullable `created_by_user_id`),
`scripts/billing-automate.php` (29/29 CLI checks), notices→bell fan-out + inbox, settings/channels/
providers (test-mode), utility bills + resident My Bills, signed pay links + CSRF-exempt HMAC webhooks,
and the carousel/hero hotfixes (`f192489`/`beeb320`/`57ab0c6`). Rollback = pin `f11367c` (Inc-10
bundle; additive schema/forward-only migrations); UI hotfix effectively live. Known gaps: P2 settings
form polish, resident Account card removals / estate Appearance paused, Inc-11 pending.

### Increment 12 — Estate notice images (GATED — pending final suite)

*Contract:* [`estate-notices-contract.md`](estate-notices-contract.md) (+ `storage/temporary/inc12/
DESIGN-NOTES.md`) · *Acceptance:* [`increment-12-acceptance.md`](increment-12-acceptance.md)
(**GATED — pending final suite**) · *Status:* **built, E2E green, NOT released** — migration
**`20261111_000012_add_estate_notice_images.sql`** (additive column-only: `estate_notices.image_path
VARCHAR(255)` + `image_alt VARCHAR(160)`; no new table/FK/index/backfill) is applied on the live
preview (**applied=11**, 42 tables / 84 FKs / 52/13/217 unchanged). Route
`GET /notices/{uuid}/image` (`NoticeImageController::show`: auth + estate + `notice.view`; streams
from `storage/uploads/notices`, jpeg/png/webp only, `private, max-age=3600`, `nosniff`;
archived/missing/foreign → 404) is in the frozen route table (183 at E2E; **195** current); **13/13 image E2E checks PASS**
(multipart upload/replace/publish, resident list+detail image+alt, authorized serving, anonymous
redirect, draft removal, published-edit lock, foreign 404, resident blocked, archived rejected).
**Not accepted** until the final suite + “bundle go”. Rollback = pin `f11367c`.

### Planned — automated billing & utility bills (Inc-13, DRAFT contract, DESIGN FROZEN)

- Skeleton contract drafted: [`automated-billing-contract.md`](automated-billing-contract.md) —
  **DRAFT / DESIGN FROZEN / BUILD IN PROGRESS — not released** (P0 DB gate, P1 CLI, P2a notices→bell,
  P4 route/controller slice landed; slice tests/E2E pending; do not claim released). **Consolidated
  freeze (T164–T168) applied:** **no new permissions** (billing families reused → 52 permissions /
  13 roles / **217 links unchanged**); **routes 195 total (150 web + 44 API = +30 web)**: platform
  settings notifications/payments (GET+POST), platform automation summary, platform pay-link create,
  public `/pay/{token}`, **2 webhooks** `/webhooks/payments/mpesa|card`, resident **My Bills**
  list/detail/pay-link, **notifications inbox/read**, **`/app/navigation`**, **`/app/account`**,
  **`/notices/{uuid}/image`** (Inc-12), admin utility overview; **estate settings fold into the existing `/admin/settings` (no new route)**; CLI
  `scripts/billing-automate.php`; **utility basis selectable per utility per estate** — **flat /
  metered / both + tariff** (estate admin sets); **period keys UTC**, monthly anchor **1st** with
  month-end clamp, `lead_days` before period start, **due-in 14d**, reminders **3 days before + on
  due**, **schedule defaults + past-due escalation editable by estate admin**;
  **notification channels**: super admin sets global availability (email/in-app always; **SMS +
  WhatsApp only when a real gateway is configured**), estate admin selects per estate;
  **collection = M-Pesa & Card payment links** (provider config + mode test/live, estate method
  enablement, **signed stateless links TTL 24h default / max 7d**, **webhooks signature + replay
  protection → idempotent `billing_payments`**, manual fallback) — not manual-only; **webhook
  CSRF-exempt route mechanism required** (route-level allowlist, no global disable); resident
  read/pay via **auth + household-membership ownership (no permission node)**, guard **none**.
  **T160/schema:** **+3 tables** — `billing_schedules` (scope `estate_subscription`|`utility_bill`)
  + `utility_charges` + **`platform_settings`**; `billing_invoices.created_by_user_id` **nullable**
  for automation/system rows (audit actor **system**); `notification_logs` extended
  (`sent_at`/`reminder_sent_at`, no outbox/inbox); single migration
  **`20261111_000011_add_billing_schedules_and_utility_billing.sql`** (`000012` = Inc-12 announcement
  images — staged additive `ALTER estate_notices ADD image_path/image_alt`; after apply **11
  migrations / 42 tables / 84 FKs / 52/13/217**); expected DB gate **10 migrations / 42 tables / 84
  FKs / 52 permissions / 13 roles / 217 links**; cron + dry-run-first + provider test-mode rollout.
  Remaining owner questions in contract §14. No release claim.

### Planned — estate billing read-only + reports/exports (Inc-11, DRAFT contract, never built)

- Draft contract: [`estate-billing-reports-contract.md`](estate-billing-reports-contract.md) —
  **DRAFT / design-only; build after Inc-12/Inc-13; no release claim**. Scope: estate admin/manager
  **read-only** own-estate subscription + invoices + payment ledger; finance reports (billing
  summary, outstanding/past-due, payments received, utility bills) with date-range filters;
  **streamed CSV** (UTF-8 BOM, RFC 4180, injection-safe, masked PII) + **browser print/PDF**;
  **no writes, no new permissions (52/13/217), no new tables**. Candidate routes `/admin/billing`,
  `/admin/billing/invoices/{uuid}`, `/admin/billing/reports`, `/admin/billing/export.csv`
  (+optional utility report; not added) → **current base 195 (151 web + 44 API); final count confirmed by tests
  at the gate** (195 currently; the batch landed); audit only
  `report.exported` (PII-free). Open owner decisions in contract §12; planned-acceptance skeleton:
  [`increment-11-acceptance.md`](increment-11-acceptance.md) (DRAFT).

### Landed — settings/appearance + platform schedule routes + Inc-11 reports/CSV (DRAFT docs, no claim)

- **Reference-clone landing** (2026-11-12, routes now **195** = 151 web + 44 API): `/` rebuilt as a **single-screen** reference clone (brand/nav header, two-line hero, **“Check in”** + **“Scan QR”** CTAs, helper **“Sign in to check in or scan a visitor pass.”**, estate image card **28px radius / 4:3** with **diamond + dot accents**, slim footer with contact); **`/about` → HTTP 302 `/#about`** (`AboutController`; previous multiline welcome/`#how-it-works` sections superseded).\n- **UI/branding + CSV final polish** (2026-11-12, routes still **195**): support contact **hello@dashpass.africa** everywhere (no `support@…`); brand **“Skip the Queue”** (guest tagline, manifest `name`, offline title; `short_name` unchanged); footer **`<Product> <version> · Secure, Fast & Accountable.`** + contact; sign-in copy = developer helper + **“Not registered yet? Ask the Estate Administrator…”** (single theme toggle, no duplicate); service worker **v7** (`mtaapass-shell-v7`/`mtaapass-static-v7`); bolder chevrons; admin gates/guards action rows inline. CSV export **final contract**: UTF-8 BOM + static per-report headers (empty = headers only), row cap **50,000 → 422 `EXPORT_TOO_LARGE` at 50,001+**, PII-free `report.exported` audit (`report/format/row_count/estate_id/from/to/from_utc/to_utc/timezone`).\n- Backend **polish landed** (2026-11-12, routes still **195**): schedule payload keys `last_invoice_number` / `anchor_day` (1–28) / `anchor_month` (1–12) / `clamp_to_month_end` (default true) / `amount_source` (`plan_price|fixed_amount|manual_draft`) + form defaults; **run-now is a preview** (`dry_run=true`, `scanned/due/generated=0/skipped`, `invoices[]` preview rows incl. `period_key/next_run_at/scope/amount_cents/currency/unit`, `message`, audited `billing.schedule_run_preview`); estate settings **`billing_meta`** (utility basis/tariffs, channels, payment methods, schedule defaults, `ui.appearance_default` with value/source/overridable); billing/report filters **`from`/`to`** (estate TZ) → **`from_utc`/`to_utc`**, `timezone`, 422 validation; neutral **billing 403 page**. Tests: baseline3 killed mid-run → **baseline4** planned.\n- Backend batch **landed** (modules, 2026-11-12): **estate appearance default**, **admin billing
  settings wiring**, **7 platform schedule routes**, **4 Inc-11 read-only/CSV routes**
  (`/admin/billing`, `/admin/billing/invoices/{uuid}`, `/admin/billing/reports`,
  `/admin/billing/export.csv`). Route table is now **195 (151 web + 44 API: 183 at E2E + 12 incl. the `/about` redirect)**;
  appearance default + admin billing settings wiring are folded into existing settings routes (no
  new route). Docs updated DRAFT: `automated-billing-contract.md` (routes 195 + schedule routes),
  `estate-billing-reports-contract.md` (Inc-11 routes landed), `increment-11-acceptance.md`
  (**GATED — pending final suite**), PICKUP. Tests: baseline2 was **865/859 with 6 known reds**
  (PWA/SW, FirebaseSecurity allowlist, mock-SMS folds); **baseline3 was killed mid-run** (no exit marker) — **baseline4** planned —
  Inc-11/12/13 acceptance stay **GATED** until it is green, then flip + bundle on “bundle go”.
  **No release claim.**

---

## `0.2.1-dashpass` — 2026-11-11

State at this release: **158 routes** (114 web + 44 API), **8 migrations / 37 tables /
52 permissions / 217 role links**, service-worker **v4**, `/health` → **applied=8**, `pending=0`,
live version **`0.2.1-dashpass`** (environment `production`).
Latest automated suite: **710/710 PASS** (Firebase + Inc-9 baseline; 0 fail, 1769.53s,
`tests/_resume-full.log`), superseding **676/676** at the Inc-9 close (`tests/_final-full.log`).
Gated commits to
deploy: **`742a9ad`** (Inc-8 gate) and **`be9b410`**
(Inc-9 gate); deployment bundle `storage/temporary/mtaapass-deploy.zip` (392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`).
Live enforcement was verified on 2026-11-11: `/health` version `0.2.1-dashpass`, environment
`production`, applied=8/pending=0; Inc-6/7/8/9 routes live; `/service-worker.js` Cloudflare bypass
working (`cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`).

### Increment 9 — Platform super-admin portal
*Contract:* [`platform-admin-contract.md`](platform-admin-contract.md) ·
*Acceptance:* TBD (gate record) · *Routes:* 147 → **158** (114 web + 44 API; 11 platform web
routes) · *Migration:* **none** (DB stays 8 migrations / 37 tables / 217 links; `/health` applied=8).

**What's new** — a separate `/platform` shell for platform operators: dashboard with real SaaS
stats + the **dashboard-only platform audit** viewer (no `/platform/audit` route); estates list +
provisioning form (account + estate + admin user + default settings + optional plan subscription in
one transaction); estate detail + status form (active/suspended/archived, no hard deletes); plans
list + add + retire; subscriptions list + change plan/status (cancelled/expired terminal →
`409 SUBSCRIPTION_CLOSED`); audited `platform.*` mutations with PII-free metadata; platform-area gate
(platform membership required — estate users 403, unauth 302).

**What owners will notice** — the operator manages all estates/plans/subscriptions from
`/platform`; estate admins, residents and guards cannot access it; provisioning a new estate creates
a working admin login in one step.

**Deploy** — no migration: pin **`be9b410`** (or later) → `deploy-sync install` is a safe no-op →
`/health` stays applied=8 / 37 tables / 217 links → purge + smoke per
[`deployment-cpanel.md`](deployment-cpanel.md) §3d. **Delivered live 2026-11-11** (bundle
`storage/temporary/mtaapass-deploy.zip`, 392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`); `/platform*` routes verified
live (302 unauth, 403 for estate users) and `/health` version `0.2.1-dashpass` / environment
`production` / applied=8 confirmed.

### Increment 8 — Parking administration + overstay
*Contract:* [`parking-administration-contract.md`](parking-administration-contract.md) ·
*Acceptance:* **TBD** (gate record in progress) · *Routes:* 131 → **147** · *Migration:* **none**
(8 migrations / 37 tables / 217 links unchanged; adds seed setting
`parking_overstay_grace_minutes=30` for Greenview).

**What's new**
- Admin parking surfaces: `/admin/parking` overview + zones, bays, allocations (create/end) and
  violations (create/resolve) — soft archive/end/resolve only, no hard deletes.
- Guard violation reporting at `/gate/parking/violations`; admin resolution queue.
- **Overstay detection** as a server-side CLI: `php scripts/parking-overstay.php [--dry-run]`
  flags active sessions past invitation validity + grace as `overstay` and creates exactly one
  violation per session (idempotent; run from cPanel Terminal or a cron entry).
- Estate settings allowlist gains `parking_overstay_grace_minutes` (0–120, seeded 30).

**What owners will notice**
- Parking is now manageable in the admin portal instead of seed/API only; guard devices can log
  parking violations; overdue sessions can be flagged by a scheduled job (no automatic fines).

**Deploy:** no new migration — pull the gated build; `deploy-sync.php?action=install` remains
safe (idempotent) and applies the grace-setting seed; `/health` stays `applied=8`.
Smoke: [`deployment-cpanel.md`](deployment-cpanel.md) §3d (Inc-8) and
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) §4f.

### Increment 7 — Estate notices / announcement centre
*Contract:* [`estate-notices-contract.md`](estate-notices-contract.md) ·
*Acceptance:* [`increment-7-acceptance.md`](increment-7-acceptance.md) · *Routes:* 121 → **131** ·
*Migration:* **9** (`estate_notices`; 8 migrations / 37 tables / 52 permissions / 217 links).

**What's new**
- Admin composer: create draft, edit draft inline, publish, archive — with 409 semantics
  (`NOTICE_ALREADY_PUBLISHED`, `NOTICE_LOCKED`, `NOTICE_CLOSED`) surfaced as flash messages.
- Resident notices list + detail (`/app/notices`, `/app/notices/{uuid}`) with priority ordering
  (urgent → important → normal), audience filtering and expiry; guard read-only list
  (`/gate/notices`).
- **Home announcement feed is now real**: up to 3 published, unexpired notices with a
  “View All” link; the demo/preview announcement content is retired.
- `DemoNoticeSeeder`: 4 Greenview demo notices (3 published incl. one urgent with expiry,
  1 draft; Riverside empty state), idempotent.

**What owners will notice** — notices are created/published in the admin portal and appear on
resident Home + Notices screens and guard devices immediately; drafts, expired and archived
notices are never exposed; cross-tenant views show an honest empty state.

**Deploy:** required `POST /deploy-sync.php?action=install` (migration 9) →
`/health` **applied=8 / 37 tables / 217 links**. Smoke per [`increment-7-acceptance.md`](increment-7-acceptance.md).

### Increment 6 — Household staff directory + incidents
*Contract:* [`staff-incidents-contract.md`](staff-incidents-contract.md) ·
*Acceptance:* [`increment-6-acceptance.md`](increment-6-acceptance.md) · *Routes:* 99 → **121** ·
*Migration:* **8** (7 migrations / 36 tables / 204 links).

**What's new**
- Resident household staff directory (`/app/staff`: create/list/deactivate/reactivate; masked
  phones; no user accounts/invites); admin estate-wide staff view.
- Incidents: residents report + see only their own; guards report at the gate and see their own
  list (`/gate/incidents`); admin queue/filters/detail with `open → investigating →
  resolved/dismissed`, required resolution notes and terminal 409; no hard deletes.
- High/critical incidents raise an **email alert** (`MAIL_ALERT_TO`, masked log, audited,
  non-blocking) using the Inc-5 mail infrastructure.
- `DemoStaffIncidentSeeder`: 3 demo staff + 3 demo incidents (Greenview).

**What owners will notice** — households manage domestic staff in-app; security incidents get a
tracked workflow instead of ad-hoc messages; alert emails reach the configured ops inbox.

**Deploy:** required `POST /deploy-sync.php?action=install` (migration 8) →
`/health` **applied=7 / 36 tables / 204 links**.

### Increment 5 — Email alerts, PWA install enforcement, time-based greeting
*Contract:* [`email-pwa-greeting-contract.md`](email-pwa-greeting-contract.md) ·
*Acceptance:* [`increment-5-acceptance.md`](increment-5-acceptance.md) · *Routes:* 99 (unchanged)
· *Migration:* **none**.

**What's new**
- Mail abstraction: `log` / `SMTP` (implicit TLS 465 / STARTTLS 587) / `Resend` HTTP providers;
  security alert emails for visitor check-in/out and walk-ins (`MAIL_ALERT_TO` fallback),
  post-commit, masked recipients, failure audited and non-blocking; `scripts/mail-test.php --check|--to=`.
- Hardened PWA manifest (id/display_override/categories/shortcuts/icons) and app-wide install
  enforcement (banner, iOS/Android guidance, account “Installation check” card).
- Service worker **v4**; `public/.htaccess` sets `no-cache` + `Service-Worker-Allowed` for the
  worker; **status-bar theming** matches the app surface (theme-color metas + iOS
  `black-translucent`), and the theme toggle updates the meta live.
- Server-rendered time-based greeting (estate-local timezone, “Good morning/afternoon/evening/
  Hello” fallback).

**What owners will notice** — install prompts/instructions and an installation self-check on
account; a greeting that follows the estate clock; security emails from a verified sender.

**Deploy:** no migration; set the server-only mail keys (`MAIL_DRIVER=smtp`,
`smtp.resend.com:465`, username `resend`, key as password, verified sender, `MAIL_ALERT_TO`) and
purge caches.

### Increment 4 — Estate/admin management portal
*Contract:* [`estate-admin-management-contract.md`](estate-admin-management-contract.md) ·
*Acceptance:* [`increment-4-acceptance.md`](increment-4-acceptance.md) · *Routes:* 73 → **99** ·
*Migration:* **none** (tables already existed; soft archive only).

**What's new** — web admin portal for `estate_admin`: dashboard with real stats + recent audit;
buildings; households + members; residents (create/link/suspend/reactivate); guards + gate
assignments; gates; allowlisted estate settings. 18 modules / 10 screens / `admin.css`; every
`/admin*` handler behind an admin-area permission gate; tenant-scoped (David Riverside-only);
no hard deletes.

**What owners will notice** — estate operations are manageable from a browser instead of
seed/CLI; suspended residents lose access; audit trail visible.

**Deploy:** no migration; no `deploy-sync` needed for this increment alone.

### Increment 3 — Vehicles & parking
*Contract:* [`vehicles-parking-contract.md`](vehicles-parking-contract.md) ·
*Acceptance:* [`increment-3-acceptance.md`](increment-3-acceptance.md) · *Routes:* 61 → **73** ·
*Migration:* **7** (`vehicles`, parking zones/bays/allocations/reservations/sessions/violations).

**What's new** — household vehicles (add/list/deactivate, plate normalisation, duplicate 409);
real parking in Bookings (visitor-bay availability, reservations) replacing the preview rows;
invite with `vehicle_id` + `parking_required` (atomic rollback with `NO_PARKING_AVAILABLE`);
guard parking check-in/out sessions; pass + public pass show bay/zone only (no plate/PII).

**What owners will notice** — residents register plates and reserve visitor bays; the guard
session flow handles parking; “Facilities/My Bookings” show real data.

**Deploy:** required migration 7 (fresh install 6 migrations / 34 tables).

### Increment 2 — Visitor access (PIN/QR) + resident PWA layout refresh
*Contract:* [`visitor-access-module-contract.md`](visitor-access-module-contract.md) ·
[`resident-pwa-layout-addendum.md`](resident-pwa-layout-addendum.md) · *Migration:* **6**
(visitor invitations, credentials, logs).

**What's new** — resident invitations (PIN shown once, rotating/extending/revoking, QR image +
public pass page), guard verification/check-in/check-out/walk-in approvals with rate limits and
single-use credentials; PWA layout refresh (dark-navy premium shell, hero estate card, 3×2 quick
tiles, bottom nav with centre QR, Visitors/Bookings screens); one-time dev SMS log adapter.

### Increment 1 — Foundation (Task 001)
*Contract:* [`foundation-contract.md`](foundation-contract.md) · *Migrations:* **1–4**
(SaaS/estates, identity + RBAC, estate structure, security/audit).

**What's new** — plain-PHP MVC core, MySQL/MariaDB schema, phone+OTP authentication (dev SMS
adapter), multi-estate tenancy + RBAC + permission middleware, CSRF + security headers, audit
log, PWA shell/manifest/service-worker, health endpoint, installer/migrator/seeder CLIs, test
harness + README. Baseline version was `0.1.0-foundation`.

---

## Behaviour & breaking changes to be aware of

| Change | Impact |
|---|---|
| **Real notices replace the Home announcement demo** | Home shows published estate notices (≤3, priority order) with “View All”; demo announcements only appear when no real notices exist (local preview mode). |
| **Home “Notices & Updates” tile is now a real link** | It navigates to `/app/notices` (previously a “Soon” toast). |
| **Status-bar theming** | Installed PWAs tint the Android/Chrome status bar from `theme-color` metas and use `black-translucent` on iOS so the app surface shows behind it; the theme toggle updates it live. |
| **Seeded demo notices are no longer prefixed** | Demo notice bodies no longer include a “Demo notice -” prefix (they are still demo data — purge before a real pilot). |
| **Staff editing has no route** | Staff can only be created, deactivated or reactivated (`/app/staff`); correct a record by deactivating and re-creating it. |
| **Guard incident detail is admin-only by design** | Guards list/report incidents (`/gate/incidents`); the detail/triage screen exists only in the admin portal. |
| **Overstay is a server-side job** | No web trigger: run `php scripts/parking-overstay.php` (dry-run first) from the cPanel Terminal or a cron entry. |
| **Bookings shows real parking** | “Facilities” lists real visitor-bay availability and “My Bookings” real reservations; non-parking facilities and preview rows are gone (preview content is disabled in production via `UI_PREVIEW_CONTENT=false`). |
| **`.env` is server-only** | No credentials in the repo/bundle; `APP_KEY` must stay stable (rotation invalidates sessions/QR/pass tokens). |

## Deploy notes per increment

| Increment | Migration | DB sync (owner) | Routes | Fresh-install state | Expected `/health` |
|---|---|---|---|---|---|
| 1 Foundation | 1–4 | first install | 25 | 4 migrations | applied=4 (historical) |
| 2 Visitor access | 6 (no 000005 file) | yes | 35 new / 61 | 6 migrations | applied=6 (historical) |
| 3 Vehicles & parking | 7 | yes | 73 | 6 migrations / 34 tables | applied=6 (historical) |
| 4 Estate/admin portal | **none** | no | 99 | 6 / 34 | applied=6 (historical) |
| 5 Mail/PWA/greeting | **none** | no | 99 | 6 / 34 | applied=6 (historical) |
| 6 Staff + incidents | 8 | **yes** (`install`) | 121 | 7 / 36 / 204 links | applied=7 |
| 7 Notices | 9 | **yes** (`install`) | 131 | 8 / 37 / 217 links | applied=8 |
| 8 Parking admin + overstay | **none** | optional (`install` applies the grace-30 seed) | 147 | 8 / 37 / 217 | applied=8 |
| 9 Platform portal *(delivered live 2026-11-11)* | none | optional (`install` no-op) | 158 | 8 / 37 / 217 | applied=8 |
| 10 Billing *(DELIVERED — bundle pin `f11367c`)* | 10 | **yes** (`install`) | 165 | 9 / 39 / 217 (+2 invoices / 1 payment) | applied=9 |

*Cumulative:* `deploy-sync.php?action=install` applies **all pending migrations** idempotently, so
one deploy can jump a server from any earlier state to the current one (e.g. live pre-Inc-4 → the
Inc-9 build). Use `action=status` (read-only) to inspect, and
[`deployment-rollback.md`](deployment-rollback.md) §2 before any rollback. Migrations are
forward-only; a DB rollback is a backup restore.

**Current live build:** pin gated commit **`be9b410`** (Inc-9), deployed from the
`storage/temporary/mtaapass-deploy.zip` bundle (392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`); the packet
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) documents the procedure (pull →
`deploy-sync install` → `/health` applied=8 → cache purge → smoke matrix). Live was verified on
2026-11-11 (`/health` version `0.2.1-dashpass`, production, applied=8/pending=0; Inc-6/7/8/9 routes
live; SW bypass verified). The next release is **Inc-10 billing** — **DB + route gates passed**
(9 migrations / 39 tables / 165 routes / 2 invoices + 1 payment on the gate DB) but **gated pending
the final suite, billing-test/UI/E2E reports and the T113 bundle**; it is **not deployed** yet
(live stays applied=8 until the owner deploys).

## Known issues / deferred

| Item | Status / workaround |
|---|---|
| Live site is behind the repo | One deployment follows `deploy-now-inc6-inc7.md`; the document-root finding must be fixed first ([`deployment-cpanel.md`](deployment-cpanel.md) §12). |
| Cloudflare / service-worker staleness | Purge Everything after each deploy; hard-refresh once (current **SW v7** activates and clears old caches). |
| No push notifications / notice fan-out | Notices are in-app only (no email/SMS fan-out, no attachments, no scheduling, no read receipts). |
| Billing | Not installed — Increment 10 (migration 10); the future contract is [`billing-invoices-contract.md`](billing-invoices-contract.md). |
| Firebase phone-auth OTP | Deferred behind a feature flag (`FIREBASE_SMS_ENABLED=false`); server/dev OTP remains the login path. |
| No staff-edit route | Create/deactivate/reactivate only (see behaviour changes). |
| Guard incident detail | Admin-only by design (guards list/report only). |
| Parking | No barriers/ANPR/fines/payments; overstay requires the CLI/cron job; bay archive uses `maintenance` state. |
| Manual install test | Browser install prompts cannot be automated; verify on a real device (manifest/SW/headers are covered by tests). |
| Demo data | Greenview/Riverside demo accounts, notices, staff, incidents and parking fixtures must be purged/replaced before a real resident pilot (`UI_PREVIEW_CONTENT=false`). |
| `APP_KEY` stability | Never rotate casually — sessions and QR/pass tokens are derived from it. |

## QA evidence summary

- **Automated suite (plain PHP):** Increment 3 → **400/400**; Increment 6 → **575/575** (70
  files, 1157s, exit 0); Inc-9 close → **676/676** (172 unit / 438 integration / 66 security, 80
  files; `tests/_final-full.log`); **current baseline → 710/710 PASS, 0 fail**
  (Firebase + Inc-9; `tests/_resume-full.log`, 1769.53s) — the Lead runs the exclusive full suite
  per gate.
- **Independent HTTP E2E (qa-e2e):** Inc-5 **71/71**, Inc-6 **95/96 (0 FAIL)**, Inc-7
  **78/80 (0 FAIL, 2 SKIP)** — real sessions/CSRF/dev-OTP, tenant isolation, 422/409 codes.
- **Per-increment proofs:** Inc-3 114/114 in-process + 46/46 live; Inc-4 150/150 in-process +
  145/145 render + 57/57 HTTP; Inc-6 57/57 backend + 66/66 UI live; Inc-7 51/51 backend +
  81/81 UI live (a real priority-ordering bug was found and fixed by the gate).
- **Database gates:** Inc-6 7 migrations / 36 tables / 204 links; Inc-7 8 / 37 / 52 / 217;
  install twice idempotent on dev + clean-room test DBs; cross-tenant composite-FK rejection
  probed.
- **Release/security:** auto-commit/push watcher healthy; bundle secret screens **CLEAN**
  (only empty `.env.example` placeholders and approved fake test sentinels; no APP_KEY /
  DEPLOY_SYNC_TOKEN / RESEND_API_KEY / MAIL_SMTP_PASSWORD values in tree, index or history);
  `.env*` gitignored; no force-push. See [`git-release-process.md`](git-release-process.md).

## Documentation map

| Increment | Contract | Acceptance record |
|---|---|---|
| Foundation | [`foundation-contract.md`](foundation-contract.md) | task board / README |
| Visitor access | [`visitor-access-module-contract.md`](visitor-access-module-contract.md) | task board / README |
| PWA layout refresh | [`resident-pwa-layout-addendum.md`](resident-pwa-layout-addendum.md) | task board / README |
| 3 Vehicles & parking | [`vehicles-parking-contract.md`](vehicles-parking-contract.md) | [`increment-3-acceptance.md`](increment-3-acceptance.md) |
| 4 Estate/admin portal | [`estate-admin-management-contract.md`](estate-admin-management-contract.md) | [`increment-4-acceptance.md`](increment-4-acceptance.md) |
| 5 Mail / PWA / greeting | [`email-pwa-greeting-contract.md`](email-pwa-greeting-contract.md) | [`increment-5-acceptance.md`](increment-5-acceptance.md) |
| 6 Staff + incidents | [`staff-incidents-contract.md`](staff-incidents-contract.md) | [`increment-6-acceptance.md`](increment-6-acceptance.md) |
| 7 Notices | [`estate-notices-contract.md`](estate-notices-contract.md) | [`increment-7-acceptance.md`](increment-7-acceptance.md) |
| 8 Parking admin + overstay | [`parking-administration-contract.md`](parking-administration-contract.md) | TBD (gate record in progress) |
| 9 Platform portal *(delivered live 2026-11-11)* | [`platform-admin-contract.md`](platform-admin-contract.md) | TBD (gate record) |
| 10 Billing *(future)* | [`billing-invoices-contract.md`](billing-invoices-contract.md) | — |

See also: [`deployment-cpanel.md`](deployment-cpanel.md) (deploy runbook),
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) (owner go-live packet),
[`deployment-rollback.md`](deployment-rollback.md) (rollback + verification),
[`git-release-process.md`](git-release-process.md) (publishing pipeline),
[`database-schema.md`](database-schema.md) (schema reference), and [`../README.md`](../README.md)
(developer overview, demo accounts, routes).

---

*Prepared by the release agent for the product owner/pilot operators. Update this file at each
increment gate (next: **Inc-10 billing — migration 10**; Inc-9 platform portal is delivered live).*
