# MtaaPass/DashPASS — UI & Device Acceptance Checklist (Handover)

**Status:** handover checklist for the current build.
**Route count at time of writing:** **195** (150 web + 44 API + dynamic manifest; Inc-11/13 schedules, billing and the landing About redirect included).
**Scope covered:** resident PWA (Home/estates/notices/staff/incidents/visitors/vehicles/parking/bookings/account), guard PWA, estate admin console, platform portal phase-1, billing phase-1 (pending route gates), auth/guest/public pages, error pages, PWA install/status-bar/service-worker.
**Out of scope here:** module/business-logic acceptance (covered by the increment acceptance docs and full test suite), load/performance testing, and security penetration testing.

> **No-screenshot policy in this checklist.** Automated screenshots/device farms are not available in this build environment. Each check must be confirmed manually (device or browser DevTools) and evidenced by the tester's page URL, viewport, browser build, and defect link. "Looks fine" is not evidence; record what was checked.

---

## 1. Environment, demo accounts, and entry points

| Item | Value |
|---|---|
| Local dev URL | `http://127.0.0.1:8010` |
| Production URL | `https://<production-domain>` (must be HTTPS for installability) |
| Dev server / tunnel | Lead-owned XAMPP Apache on 8010; **do not restart** it during acceptance |
| Product name | config-driven (dev **MtaaPass**, prod **DashPASS**) |
| Login | passwordless: phone → one-time code. In dev the code is shown on the OTP screen only. |
| Routes expected | 195 (150 web + 44 API + dynamic manifest) |
| Migrations expected | 8 now / 9 tables 39 after Inc-10, role links 217 |
| Test database | `mtaapass_test` is shared with the automated suite — do not run acceptance scripts against it |

**Demo accounts** (seeded demo data only — never use real people's phone numbers or secrets in this document):

| Role | Demo phone | Home estate / notes |
|---|---|---|
| Resident (Jane Smith) | `+254700000010` | Greenview Residences, household **A-001 · Block A** (owner, primary) |
| Tenant | `+254700000011` | Greenview, A-002 |
| Household member | `+254700000012` | Greenview, A-002 |
| Guard (John Mutiso) | `+254700000004` | Greenview gates (pick assigned gate after login) |
| Security supervisor | `+254700000003` | Greenview (gate/incident oversight) |
| Parking manager | `+254700000008` | Greenview (parking admin screens) |
| Auditor | `+254700000009` | Greenview (read-only; no admin/user manage) |
| Estate admin (Grace Njoroge) | `+254700000001` | Greenview **and** Riverside admin |
| Estate manager | `+254700000002` | Greenview |
| Riverside admin (David Kimani) | `+254700000014` | Riverside Court only — scoping checks |
| Platform super admin (Daniel) | `+254700000020` | Platform portal; no estate context (goes to `/app/no-estate`) |

**Seeded demo data used by checks:** notices (3 published + 1 draft on Greenview), staff (Demo Housekeeper A-001, Demo Gardener A-002, Demo Driver B-001), incidents (1 open, 1 investigating, 1 resolved), parking zones/bays/allocations/violations, plans/subscriptions (once Inc-9/10 gates land).

---

## 2. Page coverage — every shell and role

**Legend:** `200` = loads; `empty` = honest empty state (no fake rows); `403` = permission/area gate; `404` = wrong estate/malformed/unpublished; `302` = redirect to `/login` when signed out.

### 2.1 Resident PWA — Jane (`+254700000010`), app shell

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/app` | Home: greeting, vibrant hero card with single-line estate name + **Details** chevron (`/app/estates`), six 3×2 quick tiles (Access QR, Visitors, Vehicles, Nav, Bookings, Payments soon), announcements carousel with illustrated placeholders; **no Household services block** and **no "Your estate" details section** | `200` |
| `/app/estates` | **Current estate** card at top (Your role, Estate type · timezone, household A-001 · Block A · Owner + Primary chip, gate(s) with status chips, plan, preview note only in preview mode) + **Your estates** switcher with Current chip and switch forms | `200`, `empty` card if no active estate |
| `/app/notices` | Published notices list: priority/status chips, category chip, published date, excerpt, detail links, honest empty state | `200` |
| `/app/notices/{uuid}` | Notice detail: title h1, `nl2br` body, audience/priority/category chips, published/expiry meta, back link | `200`; draft/archived/foreign → **404** |
| `/app/staff` | Household staff directory: own-household rows, masked phone, type label, status chip, deactivate/reactivate, add form | `200`, `empty` if no staff |
| `/app/incidents` | Own incident reports only: severity/status chips, occurred/gate/household/reporter, empty state, report CTA | `200` |
| `/app/incidents/new` | Report form: type/severity/summary/description/occurred_at/optional gate/household/invitation, CSRF, errors/old | `200` |
| `/app/visitors` | Upcoming/History tabs, pending walk-ins, visitor cards, pass/revoke actions, empty states | `200` |
| `/app/visitors/invite` | Invite form incl. optional vehicle/parking, validation errors, no parking availability message | `200` |
| `/app/passes/{uuid}` | Pass screen: QR, PIN reveal/rotate, validity, status, revoke/extend, public pass URL | `200`; foreign pass → **404** |
| `/app/vehicles` | Vehicle list/add/deactivate, empty state | `200` |
| `/app/parking` | Allocations/reservations read-only, empty state | `200` |
| `/app/bookings` | Facilities availability + bookings tabs, empty states | `200` |
| `/app/account` | Profile, Household services (notices/staff/incidents), install banner/self-check, appearance, About, sign-out; **Admin console link hidden for Jane**, **Platform console hidden** | `200` |
| `/app/no-estate` | No-estate explanation and next steps (platform-only user after login or a user with no membership) | `200` |

### 2.2 Guard PWA — John (`+254700000004`), guard shell

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/gate` | Dashboard: gate selection when no gate chosen; after selection, stats, shortcuts (Verify, Walk-in, Report incident, Read notices, **Parking violations**) | `200` |
| `/gate/select-gate` | POST gate choice | `302` back to dashboard |
| `/gate/verify` | Visitor verification/check-in flow, QR/keypad, no-JS form works | `200` |
| `/gate/active` | Visitors currently inside with checkout, empty state | `200` |
| `/gate/walkin` | Walk-in request form (household select), pending list, gate-required state | `200` |
| `/gate/incidents` | Own incident reports, gate context, nav active | `200` |
| `/gate/incidents/new` | Guard incident report form; gate fixed from session; gate-required state | `200` |
| `/gate/notices` | Read-only published notices for audience all/staff; no resident-only notices; empty state | `200` |
| `/gate/parking/violations` | Report form (bay required, plate number, notes), own reports list, gate-required state | `200` |

### 2.3 Estate admin console — Grace (`+254700000001`), admin shell

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/admin` | Dashboard stats/recent activity, sidebar/tabs nav, single h1 | `200` |
| `/admin/buildings` | Buildings list, add form, validation errors/old | `200` |
| `/admin/buildings/{uuid}` | Building detail, household list/counts, edit | `200`; foreign uuid → **404** |
| `/admin/households` | Households list, status filter, add form | `200` |
| `/admin/households/{uuid}` | Household detail, members table, add member (new/existing), edit | `200`; foreign → **404** |
| `/admin/residents` | Residents list, add suspend/reactivate, validation | `200` |
| `/admin/guards` | Guards list/add, gate checkboxes assign/archive forms | `200` |
| `/admin/gates` | Gates list/add/edit/archive; edit card keeps **Save changes** (primary) and **Archive gate** (destructive) in one 44px action row that wraps on mobile | `200` |
| `/admin/staff` | Estate staff directory (masked phones, household labels, read-only audit view) | `200`, `empty` |
| `/admin/incidents` | Incident queue: status/severity filters, table, detail links | `200`, `empty` if filtered |
| `/admin/incidents/{uuid}` | Detail + status workflow; resolve requires notes; terminal closed note; draft-style actions | `200`; foreign uuid → **404** |
| `/admin/notices` | Notices queue: status/category/priority filters, counts, add CTA | `200` |
| `/admin/notices/new` | Create notice form (title/body/category/priority/audience/expires_at), errors/old, CSRF | `200` |
| `/admin/notices/{uuid}` | Detail + timeline + publish/archive; **Draft shows inline Edit form** posting to the same detail URL; published locked; archived terminal | `200`; foreign → **404** |
| `/admin/parking` | Overview: stats + links, sub-nav Overview active | `200` |
| `/admin/parking/zones` | Zones list/add/inline edit; duplicate name validation; no-archive note | `200` |
| `/admin/parking/bays` | Bays list/add/inline edit/filters/archive; in-use bays show guard copy and no archive; archived show Maintenance | `200` |
| `/admin/parking/allocations` | Allocations list/filter/add/end; ended rows read “Ended” | `200` |
| `/admin/parking/violations` | Violations queue/filters/manual create/resolve/dismiss (dismiss posts to `/resolve` with hidden `status=dismissed`), terminal rows show notes | `200` |
| `/admin/settings` | Allowlisted settings, **Appearance** (`default/light/dark`; device preference wins, estate default is fallback; reduced-motion note), and **Billing settings** (utility basis/rate, channel/method subset, schedule defaults) with validation + `billing_meta` inherited/override badges | `200` |
| `/admin/billing` | Read-only estate billing: balance summary, invoice/payment lists, reports CTA, CSV export, honest empty states, date filters (invalid filters surface backend 422 messaging) | `200` |
| `/admin/billing/invoices/{uuid}` | Read-only invoice detail + payment ledger | `200`; foreign → **404** |
| `/admin/billing/reports` | Summary/outstanding/payments/utility reports with date filters, tab links and CSV export | `200` |
| `/admin/billing/export.csv` | PII-free CSV export; filters persist into the export link | `200` text/csv |

### 2.4 Riverside-scoped admin — David (`+254700000014`)

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/admin` | Admin shell loads for Riverside only; no Greenview data anywhere | `200` |
| `/admin/households`, `/admin/parking`, `/admin/staff`, `/admin/incidents`, `/admin/notices` | Riverside-scoped lists; honest empty states; **no Greenview household/estate/staff/incident/notice/parking rows** | `200` |
| Greenview objects (paste a Greenview notice/incident/household uuid) | Tenant isolation | **404** |

### 2.5 Platform portal — Daniel (`+254700000020`) — *routes land with Inc-9 gate*

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/platform` | Platform dashboard: stats, links, **dashboard-only audit table** (no separate audit route) | `200` |
| `/platform/estates` | Estate table, status filters, provision CTA | `200`, `empty` |
| `/platform/estates/new` | Provisioning form: account existing/new, estate fields, first admin, plan, default settings | `200` |
| `/platform/estates/{uuid}` | Estate detail + status form (active/suspended/archived) + subscription summary + admins | `200`; foreign → **404** |
| `/platform/plans` | Plans list/create/retire | `200` |
| `/platform/subscriptions` | Subscriptions list + plan/status update; cancelled/expired terminal | `200` |
| `/platform/billing` (phase-1; Inc-10 gate) | Billing stats + invoice table + filters, New invoice CTA | `200` |
| `/platform/billing/schedules` | Schedules list: estate/plan/cadence/next run/last invoice/status; New schedule CTA | `200`, `empty` |
| `/platform/billing/schedules/new` | Schedule form: scope/estate, anchor day/month + month-end clamp, lead/due/reminder timing, amount source, amount fields | `200` |
| `/platform/billing/schedules/{uuid}` | Schedule detail: next-run preview, edit form, pause/resume toggle, **run-now dry-run** counts/invoice preview (zero-write, preview-only) | `200`; foreign → **404** |
| `/platform/billing/invoices/new` | Create invoice: estate + subscription prefill, amount/due/notes, save draft | `200` |
| `/platform/billing/invoices/{uuid}` | Invoice detail, balance, payment ledger, record payment, status actions | `200`; foreign → **404** |
| `/app/no-estate` | Platform-only operator lands here after login (no estate context) | `200` |
| `/app/account` | Discreet **Platform console** link visible only for platform permission holders | `200` |

### 2.6 Public / auth / error pages

| URL | Page / what to verify | Expected states |
|---|---|---|
| `/` | Reference-clone landing: brand + **About us**/**Need help?** links + **Login** pill; eyebrow/headline/2-line copy; **Check in** + **Scan QR** CTAs; rounded 4:3 estate image with diamond/dot accents; footer `Copyright © 2026 DashPASS · hello@dashpass.africa`; one theme toggle; no features/About sections | `200` |
| `/about` | Rendered About page: moved welcome content, what DashPASS does, sign-in steps, privacy notice + `hello@dashpass.africa`, back-to-home | `200` |
| `/login` | Phone entry then OTP step (dev shows code); no password fields; exactly one theme toggle; supporting copy uses `Estate Administrator` / `hello@dashpass.africa`; no Firebase/carrier helper copy | `200` |
| `/login/otp` | OTP submit, throttling message after repeated attempts | `302` / `429`-style message |
| `/login/verify` | Verify code or sign-in; invalid → error copy | `302` / error page |
| `/v/{token}` | Public visitor pass: estate/visitor/household/validity/status; no credentials/PII; invalid/expired/revoked neutral states; parking bay when present | `200`; invalid/expired → neutral **200** or **404** per module rules |
| `/health` | Health probe | `200` |
| `/manifest.json` | `application/manifest+json`, `theme_color`/`background_color` **#070B16**, start_url/scope/icons | `200` |
| `/service-worker.js` | JS, `VERSION` **v7**, `no-cache` + `Service-Worker-Allowed` headers on Apache production | `200` |
| `/offline.html` | Offline fallback page (precached) | `200` |
| Unknown path e.g. `/does-not-exist` | Styled error page, single h1, home/sign-in links | **404** |
| Signed-out protected URL e.g. `/app`, `/admin`, `/gate`, `/platform` | Redirect to login | **302** `/login` |

### 2.7 Role/status matrix — expected 403/404 behaviour

| Caller → target | `/app/*` | `/gate/*` | `/admin/*` | `/platform/*` | `/platform/billing/*` |
|---|---|---|---|---|---|
| Unauthenticated | 302 /login | 302 /login | 302 /login | 302 /login | 302 /login |
| Resident Jane (no admin/guard/ platform perms) | 200 | **403** | **403** | **403** | **403** |
| Guard John | **403** (estate app not his area; guard shell only) | 200 | **403** | **403** | **403** |
| Auditor (read-only) | 200 | **403** | **403** | **403** | **403** |
| Estate admin Grace | 200 (estate views) | **403** | 200 | **403** | **403** |
| Riverside admin David | 200 (Riverside only) | **403** | 200 (Riverside only) | **403** | **403** |
| Platform super admin Daniel | `/app/no-estate` 200 | **403** | **403** (no estate membership) | 200 | 200 (finance perms) |
| Foreign-estate UUID (same role) | **404** | **404** | **404** | **404** | **404** |

> Record the actual HTTP status for each row with the browser Network tab or `curl -o /dev/null -s -w "%{http_code}"`; do not assume the 403 page rendered instead of a redirect.

---

## 3. Responsive matrix (360 / 390 / 414 / 768 / 1024 / 1440)

**How to test:** DevTools device toolbar (or real device). At each width confirm **no horizontal page scroll** (`document.documentElement.scrollWidth <= window.innerWidth`), no clipped/overlapping text, tap targets usable, sticky bars not covering content, and the single h1 visible. Use a long value where possible (long estate name, long UUID, plate number, big money amount) to stress wrapping.

**Legend:** `✓` must pass; `—` not applicable at that width; *risk* marks the components to look at closely.

| Screen / component | 360 | 390 | 414 | 768 | 1024 | 1440 |
|---|---|---|---|---|---|---|
| Home: greeting + hero + Details link | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Home: six quick tiles 3×2 (T69 box: 108px min-height, 0.8/0.7/0.75 padding, 0.6/0.75 gap, ~2rem icon) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Guest reference landing + `/about` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Home: announcements carousel (illustrated 64:27 placeholders, right→left autoplay, pause on focus/touch only) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Estates: Current estate card (long household/gate names wrap) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Estates: membership switcher cards | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notices list/detail (chips, long titles/body wrap) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Staff directory + add form | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Incidents list + report form | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Account (install self-check, household services, long phone/UUID) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Guard dashboard + tabbar (6 tabs, scroll if needed) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Guard incident/notice/parking lists (card lists) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Guard forms (verify/walkin/report) full-width inputs | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin dashboard/quick links | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin sub-nav (Zones/Bays/Allocations/Violations) wraps/scrolls | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin tables → cards (≤720px) with `data-label` per cell | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin table action cells + inline edit forms (full-width at mobile) | *risk* | *risk* | *risk* | ✓ | ✓ | ✓ |
| Admin parking overview stats (free/occupied/maintenance/allocations/violations) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin sidebar vs tabs (sidebar ≥1024px, tabs below) | tabs | tabs | tabs | tabs | sidebar | sidebar |
| Platform topbar + nav (horizontal scroll below 1024) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Platform tables → cards (≤720px) + inline plan/status edit | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Platform money stats (big KES values wrap/clamp) | *risk* | *risk* | *risk* | ✓ | ✓ | ✓ |
| Billing invoice table → cards / balance & amount values | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Auth/guest (welcome/login/no-estate) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Error pages (404/403/500) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notch/status-bar safe area (standalone install) | ✓ | ✓ | ✓ | ✓ | — | — |
| Bottom tabbar/CTA dock safe area | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**Known-risky components to eyeball every run:** tables→cards transitions around 640–720px, admin inline-edit forms inside card-mode cells, long UUID/plate/reference strings, platform money stats at 360px, sub-nav/tabbar wrapping, sticky topbars under notches, and long notice/incident text.

---

## 4. Accessibility checklist

Run through the list below on every shell at 360px and 1440px (and once keyboard-only / screen-reader spot check). Tick each item in the evidence table of §7.

### 4.1 Colour contrast (WCAG AA)

| Element | Dark theme | Light theme |
|---|---|---|
| Body text on card | ≥ 4.5:1 (`--text` on `--card`) | ≥ 4.5:1 (`#0b1220` on white) |
| Muted/meta text | ≥ 4.5:1 (`#98a4c2` on `#141d33` ≈ 6.7:1) | ≥ 4.5:1 (`#5b6684` on white ≈ 5.7:1) |
| Status chip “Active/Confirmed” (success) | ≈ 6.8:1 | **5.66:1** after T93 fix (`#0a6b3f`) |
| Status chip “Pending/Open/Past due” (warning) | ≈ 7.1:1 | **6.04:1** after T93 fix (`#7a5200`) |
| Status chip “Danger/Critical/Overdue” | ≈ 5.0:1 | ≈ 4.8:1 |
| Neutral/inactive chip | ≥ 4.5:1 | ≥ 4.5:1 |
| Link/accent text | ≥ 4.5:1 | ≥ 4.5:1 |
| Non-text UI (borders, focus ring, icons) | ≥ 3:1 | ≥ 3:1 |

Check both themes by toggling Appearance (theme toggle in topbar/account) on each shell; chips must not rely on colour alone (they always include text).

### 4.2 Targets, focus, and keyboard

- [ ] All primary buttons, small buttons, icon buttons, nav items, sub-nav items, tabbar items and inline-edit `<summary>` toggles are **≥44×44px** as rendered.
- [ ] Visible `:focus-visible` ring on every interactive element (nav, sub-nav, topbar links, link cards, table links, inline-edit summaries, forms, dialogs). Tab through the whole page without losing focus.
- [ ] Skip link (“Skip to content”) is the first tab stop and moves focus to `#main`.
- [ ] Active nav/sub-nav/tabbar item has `aria-current="page"` **and** the active style; inactive items do not.
- [ ] Icon-only controls have accessible names (`aria-label`/visible text); decorative icons are `aria-hidden="true"`.
- [ ] QR sheet/modal: opens from Access tile, focus moves into dialog, Escape closes, focus returns to the trigger, backdrop click closes.
- [ ] No keyboard trap; native `<details>` inline edits open with Enter/Space and reach their inputs.
- [ ] Form fields have visible `<label for>`; errors are announced (inline error text/role=alert or summary) and old values are preserved after validation.
- [ ] Buttons that submit destructive/side-effect actions are distinguishable by text (not icon-only).
- [ ] Toast/status regions are `aria-live` polite and do not steal focus.

### 4.3 Structure, motion, and content

- [ ] Exactly **one h1 per page**; heading order is h1 → h2 ( → h3 cards), no skipped levels. (Home h1 “Welcome Home”; estates h1 “Your estates”; each admin/platform/guard screen one h1.)
- [ ] Landmarks present: `header`, `nav` (labelled), `main#main`, footer/bottom nav as appropriate; one `main` per page.
- [ ] `html lang="en"`; page `<title>` unique and descriptive (`<Page> · <Product>`).
- [ ] Tables have `<th scope="col">`; mobile card mode keeps labels visible (`data-label`) and does not drop header semantics for screen readers.
- [ ] Images: hero decorative `alt=""`; QR image has meaningful `alt`; no text baked into images.
- [ ] `prefers-reduced-motion: reduce` disables transitions/animations (test with DevTools emulation): tiles, cards, toasts, inline edits, nav — no motion required to operate.
- [ ] 200% browser zoom and 200% text-only resize: content reflows, no clipped controls or horizontal scroll.
- [ ] Colour is not the only status indicator (chips carry text labels + tones).
- [ ] No automatic time-based content that can’t be paused (none expected); no flashing content.

### 4.4 Keyboard-only pass list

1. Welcome → Login (phone → OTP) → dashboard using only Tab/Enter/Space.
2. Home: hero Details → estates; Access tile → QR sheet (Esc returns focus); Notices tile → list → detail → back.
3. Estates: read cards; switch estate form reachable (Jane has 1 estate in demo; use Grace for multi-estate switch).
4. Notice detail back link; staff add form; incident report form submit with validation error then correct.
5. Guard: select gate, dashboard shortcuts, incidents/notices/parking lists and report form.
6. Admin: sidebar/tabs nav between Overview → Staff → Incidents → Notices → Parking (all five pages) → Settings; open inline edit, save, table links.
7. Platform: nav Overview → Estates → Plans → Subscriptions (→ Billing after Inc-10); inline plan/subscription edit.
8. Sign out reachable from every shell; focus returns sensibly.

---

## 5. PWA install, status bar, and service worker checks

### 5.1 Android / Chrome

- [ ] Serve over **HTTPS** (production). Installability requires HTTPS + valid manifest + service worker.
- [ ] Head emits `<meta name="theme-color" content="#070B16">` (default/light) and a second `theme-color` **#070B16** with `media="(prefers-color-scheme: dark)"`; `color-scheme: light dark`; `mobile-web-app-capable="yes"`.
- [ ] `/manifest.json` returns `application/manifest+json`, `theme_color="#070B16"`, `background_color="#070B16"`, `display="standalone"`, start_url/scope/icons (192/512/maskable).
- [ ] Installed/standalone mode shows the **navy status bar** (theme-color applies only installed, not the browser tab).
- [ ] Install prompt/banner appears when Chrome offers it; Install button triggers the native prompt; `appinstalled` hides the banner and the account “App status” reads **Installed on this device**.
- [ ] In an **in-app browser** (WhatsApp/Instagram/Facebook/LinkedIn/X/TikTok), the app explains “open in Chrome/Safari to install” and never shows a dead Install button; the account Installation check lists the blocker.
- [ ] ngrok free interstitial/HTTP tunnel cannot install — use the HTTPS production domain; document if tested via ngrok.

### 5.2 iOS / Safari (Add to Home Screen)

- [ ] Head has `apple-mobile-web-app-capable="yes"`, `apple-mobile-web-app-title="<Product>"`, **`apple-mobile-web-app-status-bar-style="black-translucent"`**.
- [ ] Add to Home Screen via Share → Add to Home Screen; launched app shows navy background behind the status bar (iOS ignores theme-color; it cannot style status-bar text or the notification shade).
- [ ] Safe area: topbars/headers/content start below the notch/status bar (`env(safe-area-inset-top)`), no content hidden; bottom tabbar/CTA clears the home indicator.
- [ ] No native install prompt on iOS — banner/self-check explains the Share → Add to Home Screen path.

### 5.3 Install banner & account self-check

- [ ] Banner shows only when a prompt is available or iOS/in-app guidance applies; dismiss persists for the day; hidden when standalone.
- [ ] Account screen install card shows HTTPS ✓, manifest linked, service-worker status, install-prompt availability, display mode, and “Why can’t I install?” notes.
- [ ] Banner/self-check copy uses the configured product name, is readable at 360px, and respects reduced motion.

### 5.4 Service worker (v7) after deploy

- [ ] `/service-worker.js` 200, `VERSION` **v7**; caches `mtaapass-shell-v7` and `mtaapass-static-v7` are created; old caches deleted on activate.
- [ ] Page reload online → SW controls the page (`navigator.serviceWorker.controller`); no unhandled console errors.
- [ ] Offline: navigate to an uncached page → `/offline.html` fallback; cached shell assets still load.
- [ ] Update flow: new SW reaches waiting → update prompt → “Apply update” activates it (`SKIP_WAITING`) and reloads once; first install does not force an extra reload.
- [ ] Sign out posts `LOGOUT` and clears caches; no stale account data after re-login.
- [ ] Apache production headers for `/service-worker.js`: `Cache-Control: no-cache, max-age=0`, `Service-Worker-Allowed: /` (the local PHP dev server does not apply `.htaccess` — verify on production).
- [ ] After deploy, bump-test: hard refresh/close-reopen installed app so a stale shell doesn’t mask the new version.

### 5.4b Brand/UI polish (current tree, 2026-11-12)

- [ ] PWA manifest `name` ends with **“Skip the Queue”** (`<Product> Skip the Queue`), `short_name` unchanged (12-char base); offline page title **“You are offline · Skip the Queue”**.
- [ ] Guest tagline **“Skip the Queue”**; guest/app footer shows **`<Product> <version> · Secure, Fast & Accountable.`** + contact **hello@dashpass.africa** (no `support@…` addresses).
- [ ] Sign-in copy shows the developer helper and **“Not registered yet? Ask the Estate Administrator to add your phone number, then sign in here.”**; single theme toggle (no duplicate; exactly one).
- [ ] Service worker **v7** (`mtaapass-shell-v7` / `mtaapass-static-v7`); old caches deleted on activate.
- [ ] Directional chevrons use the bolder stroke/+2–4px sizing (facility cards, Details control).
- [ ] Admin gates/guards action rows render inline (`form-actions--row`: Save / Archive actions aligned).
- [ ] No stale support-email addresses (legacy `support@*` must be absent; only **hello@dashpass.africa**) or pre-**v7** SW copy anywhere in the UI or release docs.

### 5.5 Suggested physical-device set

| Device / OS | Browser | Focus |
|---|---|---|
| Android 12+ phone | Chrome (installed + browser) | theme bar, prompt, install banner, back behaviour |
| iPhone 13+/iOS 16+ | Safari (installed + browser) | black-translucent + safe area, Share flow, PWA cache |
| Android small phone ~360×640 | Chrome | no horizontal scroll, tile/table layout, 44px targets |
| iPhone SE 3rd gen | Safari | narrow layout, keyboard covering forms |
| Android tablet / iPad | Chrome / Safari | wide layout, sidebar (admin/platform ≥1024), tables desktop mode |
| Desktop Chrome/Firefox/Safari | latest | full-width layout, keyboard, theme toggle, service worker |

---

## 6. Cross-cutting checks (every page)

- [ ] **Single h1**, unique `<title>`, no page-level PHP notices/warnings in the HTML or console.
- [ ] **No horizontal scroll** at 360/390/414/768/1024/1440 (`scrollWidth <= innerWidth`).
- [ ] All dynamic content escaped (no broken markup when a name contains `<`, `&`, quotes); UUID/plate/reference strings wrap instead of overflowing.
- [ ] No absolute `http(s)://` asset URLs (root-relative only); no inline `<script>`/`<style>`/`onclick` (CSP-safe).
- [ ] Forms: CSRF token present, validation errors visible, old input preserved, unsaved/na filters clearly cleared.
- [ ] Empty states honest (no invented rows/counts); filtered-empty states explain how to clear filters.
- [ ] Flash success/error messages render once and are not lost after redirect (PRG).
- [ ] Product name config-driven in copy (MtaaPass dev / DashPASS prod), never hardcoded.
- [ ] No PII leakage: phones masked in lists; tenant scoping holds for foreign UUIDs (404), cross-estate data never rendered.
- [ ] No hard-delete affordances (archive/deactivate/void semantics only).
- [ ] Loading/disabled states on destructive actions (archive/deactivate/resolve) prevent double submits.

---

## 7. Evidence & sign-off template

### 7.1 Environment row (copy per run)

| Field | Value |
|---|---|
| Build / commit | `<git sha / bundle tag>` |
| Environment URL | `https://<domain>` or `http://127.0.0.1:8010` |
| App version | `<app version / release name>` |
| Routes / migrations | 195 (150 web + 44 API + dynamic manifest) / migrations unchanged by UI work |
| Tester | `<name>` |
| Date / time (EAT) | `<yyyy-mm-dd hh:mm>` |
| Device / OS | `<device, OS build>` |
| Browser / version | `<browser + version>` |
| Viewport(s) | `<e.g. 360×800, 390×844, 768×1024, 1440×900>` |
| Theme(s) tested | `dark ☐  light ☐  system ☐` |
| Result | `PASS ☐  CONDITIONAL ☐  FAIL ☐` |

### 7.2 Per-shell result rows

| Shell / role | Signed-in as | Pages covered | Result | Defects (links) |
|---|---|---|---|---|
| Guest / auth / public pass | — | `/`, `/login`, `/login/otp`, `/v/{token}`, 404/403 | ☐ | |
| Resident app | Jane | `/app`, `/app/estates`, notices list+detail, staff, incidents+new, account | ☐ | |
| Resident app (residency/empty) | new estate admin / no-estate user | `/app/no-estate`, empty lists | ☐ | |
| Guard app | John | `/gate`, verify, active, walkin, incidents, notices, parking violations | ☐ | |
| Estate admin | Grace | admin dashboard, buildings, households, residents, guards, gates, staff, incidents, notices, parking ×5, settings | ☐ | |
| Scoped admin | David | Riverside pages + foreign-uuid 404 checks | ☐ | |
| Platform portal | Daniel | dashboard, estates, plans, subscriptions (billing after Inc-10) | ☐ | |
| PWA install / status bar | Android + iOS devices | install, theme bar, safe area, SW v7 | ☐ | |
| Accessibility | — | contrast, 44px, keyboard, reduced motion, headings, forms | ☐ | |

### 7.3 Defect logging format

Each failure must be filed with enough detail for the owner to reproduce; do not batch vague items.

| Field | Example |
|---|---|
| ID / link | `UI-123 — <tracker link>` |
| Page URL + role | `/admin/parking/bays` as Grace |
| Viewport / device / browser / theme | 360×800, Pixel 6 Chrome 130, dark |
| Expected | “In-use bay shows no Archive action” |
| Actual | “Archive button visible on bay with active allocation” |
| Severity | blocker / major / minor / cosmetic |
| Evidence | screenshot or DOM snippet, console errors, network status codes |
| Environment | build commit, environment URL, SW version |

### 7.4 Final sign-off

| Role | Name | Decision | Date |
|---|---|---|---|
| QA / acceptance tester | | `PASS / CONDITIONAL / FAIL` | |
| Engineering owner (UI) | | | |
| Product owner | | | |

**Conditions for handover:** all blocker defects fixed and retested; every shell row signed; PWA install/status-bar checks signed on at least one Android and one iOS device; no page with horizontal scroll at 360px; accessibility checklist no open blocker/major items. Remaining minor defects must be listed here with owner + planned increment:

| Defect | Severity | Owner | Planned fix |
|---|---|---|---|
| | | | |

**Screenshot-free limitation:** this build environment has no browser automation or device farm, so this checklist is the evidence source. Where a visual judgement is required (status-bar colour, notch overlap, exact tile alignment), the tester must confirm on a physical device and attach a photo/screenshot to the defect or sign-off record.

---

## 8. Related documents

- [Deployment (cPanel)](deployment-cpanel.md) — production deploy, HTTPS, Apache headers, cache busting.
- [Rollback runbook](deployment-rollback.md) — rollback steps if a blocker is found post-deploy.
- [Git release process](git-release-process.md) — branch/tag/bundle and release checklist.
- [Foundation contract](foundation-contract.md) — design system, palette, tokens, shell data.
- [Resident PWA layout addendum](resident-pwa-layout-addendum.md) — Home/tiles/nav/QR sheet rules.
- [Email + PWA greeting contract](email-pwa-greeting-contract.md) — greeting windows and install UX contract.
- [Visitor access module contract](visitor-access-module-contract.md) — visitor/pass screens.
- [Vehicles & parking contract](vehicles-parking-contract.md) — resident vehicles, parking, bookings.
- [Estate admin management contract](estate-admin-management-contract.md) — admin console pages.
- [Staff & incidents contract](staff-incidents-contract.md) — staff directory and incident screens.
- [Estate notices contract](estate-notices-contract.md) — notices list/detail/composer.
- [Parking administration contract](parking-administration-contract.md) — parking admin/guard screens.
- [Platform admin contract](platform-admin-contract.md) — platform portal pages.
- [Billing/invoices contract](billing-invoices-contract.md) — billing phase-1 screens.
- [Team execution contract](team-execution-contract.md) — reporting/cadence rules during acceptance.

---

*Checklist version: T101 handover draft. Update the route/migration numbers in §1, the platform/billing status in §2.5, and re-run §5 PWA checks whenever a new increment deploys.*

## Icon system attribution

- Standard UI icons use **Material Icons by Google** (Apache License 2.0, https://fonts.google.com/icons), inlined as 24×24 `currentColor` SVG paths (no network fetch, no external icon font, no inline styles/scripts).
- Genuinely custom glyphs (for example estate gate, siren, QR/pass-specific marks) remain in the local stroke-based icon set; they are `aria-hidden` when decorative and labelled by surrounding text where needed.
- Icon names and aliases used by views remain stable; the icon registry lives in `app/Views/partials/icon.php`.
