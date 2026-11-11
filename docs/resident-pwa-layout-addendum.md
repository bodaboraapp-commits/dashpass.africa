# MtaaPass — Resident PWA Layout Refresh (Task 001.1) — binding addendum

**Status:** binding for this increment. Read fully before writing code.
**Base contract:** [`docs/foundation-contract.md`](foundation-contract.md) still applies (security rules, write scopes, conventions, test harness). This addendum refines the resident PWA look and adds two read-only screens.
**Product-owner instruction:** the resident PWA layout must follow the attached reference mockup: three phone screens — **Home**, **Visitors**, **Bookings** — with a dark navy / royal-blue premium treatment, hero estate card, 3×2 quick-action grid, announcements with pagination dots, a bottom navigation bar with a raised centre QR button, and list screens with segmented tabs and a sticky bottom call-to-action.

## 1. Approved deviation from Blueprint §17

Blueprint §17 specifies forest/emerald/sunshine/ivory. The product owner has directed the resident PWA to follow the attached mockup's visual language instead. Therefore, **for the resident PWA**, dark navy surfaces with a royal-blue accent are approved. The blueprint palette remains the reference for the guard, estate-admin and platform portals (later increments). This deviation is recorded here and in the README; it is not a silent change.

The dark theme becomes the **default** for the resident PWA (stored user preference wins; theme toggle stays). Light theme remains implemented and accessible.

## 2. Screens

### A. Home — `GET /app` (auth + estate) — existing `app/Views/app/home.php`, rebuilt

1. **Greeting block** (top): small muted line `Hello,` above a bold `Welcome Home` (`h1`); notifications bell button on the right (Soon toast: notifications module later).
2. **Hero estate card**: full-width rounded banner using a locally generated image `/assets/img/estate-hero.png` with a dark bottom gradient overlay; estate name in large white bold over the image, split across at most two lines (e.g. `Greenview` / `Residences`); circular chevron button bottom-right → `/app/estates`.
3. **Quick actions**: 2 rows × 3 columns of dark rounded tiles. Each tile = icon (24px, tinted square background) + primary label + small muted secondary label. Exactly:
   - `Access` / `My QR` → opens the QR sheet (button, not a link)
   - `Visitors` / `Pre-Register` → `/app/visitors`
   - `Vehicles` / `My Vehicles` → Soon toast
   - `Bookings` / `Facilities` → `/app/bookings`
   - `Payments` / `& Bills` → Soon toast
   - `Notices` / `& Updates` → Soon toast
4. **Announcements**: section header row with `Announcements` (`h2`) on the left and `View All` on the right (Soon toast/disabled); one announcement card (title + body) using preview content; pagination dots (one per announcement, first active) shown only when there is more than one announcement. Pagination is presentational (no fake interactivity beyond what works).
5. **Household / estate details**: keep the foundation information that exists today in a compact secondary card below announcements — at minimum the signed-in resident's household unit, building and estate role, plus the estate gates. Do not fabricate visitor counts, parking occupancy or payments.
6. **Bottom navigation**: `Home` (active), `Activity` (Soon), **centre raised QR FAB**, `Inbox` (Soon), `Account` → `/app/account`. The QR FAB is a circular accent button, ~56px, visually raised above the bar, `aria-label="Show my access QR"`.

### B. Visitors — `GET /app/visitors` (auth + estate) — new view `app/Views/app/visitors.php`

- Top bar: back chevron → `/app`; centred title `Visitors`.
- Segmented tabs: `Upcoming` and `History`. Active tab = filled accent pill, inactive = muted text. **Tabs must work without JavaScript** as links: `Upcoming` → `/app/visitors`, `History` → `/app/visitors?tab=history`. The controller validates the tab parameter against an allow-list and passes `visitors_tab` = `upcoming|history`.
- Visitor cards in a vertical list: circular initials avatar, bold name, purpose line, and date/time line. Render `visitors_upcoming` or `visitors_history` depending on the active tab.
- Empty state card when the selected tab's list is empty (visitor module not installed in production / preview off).
- Sticky bottom CTA: full-width accent button `+ Invite Visitor` (Soon toast — visitor invitations are a later increment).
- When `preview` is true, show a clearly visible `Layout preview` chip plus one short muted note: `Visitor passes ship in the Visitors increment.` Preview rows are demo content and must never be presented as real visit data.

### C. Bookings — `GET /app/bookings` (auth + estate) — new view `app/Views/app/bookings.php`

- Top bar: back chevron → `/app`; centred title `Bookings`; top-right arrow icon button → `/app`.
- Segmented tabs: `My Bookings` | `Facilities`. **Facilities is the default active tab** (as in the mockup); `My Bookings` is reached via `?tab=bookings`. Links, not JS-only. Controller validates `bookings_tab` = `facilities|bookings`.
- Facilities tab: facility cards with a thumbnail block on the left (local CSS/generated art — no external images), bold facility name, and a status line with colour **and** text: `Available` (success) or `Few Slots Left` (warning). Preview facilities: Function Room (Available), BBQ Area (Available), Gym Room (Few Slots Left), Badminton Court (Available).
- My Bookings tab: empty state (no bookings yet) — honest, no fabricated bookings.
- Sticky bottom CTA: full-width accent button `+ New Booking` (Soon toast).
- Same `Layout preview` chip + note when `preview` is true.

### D. QR sheet (shared component)

Opened by the Home `Access / My QR` tile and the bottom-nav QR FAB. Accessible modal dialog: title `My access pass`, a generated QR-placeholder visual (decorative, no token/credential encoded), muted note `Resident gate QR arrives with the Access module.`, close button. Must support Escape, backdrop click, focus move + return, `role="dialog"`, `aria-modal="true"`. No real credential or personal data rendered. Works when JS is unavailable (sheet hidden by default; the FAB degrades to a Soon toast link).

## 3. Routes (added)

| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/app/visitors` | `ResidentController::webVisitors` | `auth`, `estate` |
| GET | `/app/bookings` | `ResidentController::webBookings` | `auth`, `estate` |

No new API endpoints. No database changes. No new tables.

## 4. View data (modules → views)

Controllers pass `BuildsShell` shell plus:

**`webHome`** — `dashboard` (existing) plus:
```php
'preview'       => bool,                                   // config('ui.resident_pwa.preview_content')
'announcements' => [['title' => string, 'body' => string], ...], // 2 preview items or []
```

**`webVisitors`**:
```php
'preview'            => bool,
'visitors_tab'       => 'upcoming'|'history',
'visitors_upcoming'  => [['name'=>string,'purpose'=>string,'when'=>string], ...],
'visitors_history'   => [['name'=>string,'purpose'=>string,'when'=>string], ...],
```
Preview upcoming (3): `James Mwangi` / `Family visit` / `25 May 2026, 10:00 AM`; `Robert Brown` / `Service provider` / `25 May 2026, 2:30 PM`; `Amanda Lee` / `Personal visit` / `26 May 2026, 11:00 AM`. Preview history (2): `Grace Njeri` / `Delivery` / `18 May 2026, 09:15 AM`; `Samuel Otieno` / `Family visit` / `11 May 2026, 4:00 PM`.

**`webBookings`**:
```php
'preview'      => bool,
'bookings_tab' => 'facilities'|'bookings',
'facilities'   => [['name'=>string,'status'=>string,'tone'=>'ok'|'warn','icon'=>'room'|'bbq'|'gym'|'court'], ...],
'bookings'     => [],   // honest empty list this increment
```
Preview facilities: Function Room/Available/ok/room; BBQ Area/Available/ok/bbq; Gym Room/Few Slots Left/warn/gym; Badminton Court/Available/ok/court.

Preview content lives in one place: `App\Modules\Residents\PreviewContent` with static arrays. Controllers read `config('ui.resident_pwa.preview_content')`; when false they pass `preview => false` and empty arrays so production shows empty states. `.env` exposes `UI_PREVIEW_CONTENT` (default `true` in local/tests, documented in README).

## 5. Visual tokens

Implement in `public/assets/css/app.css` as CSS custom properties; dark is default:

| Token | Dark (default) | Light |
|---|---|---|
| `--bg` | `#070B16` | `#F4F6FB` |
| `--bg-elevated` | `#0B1222` | `#FFFFFF` |
| `--surface` | `#0E1526` | `#FFFFFF` |
| `--card` | `#141D33` | `#FFFFFF` |
| `--card-2` | `#1A2440` | `#EEF1F8` |
| `--border` | `rgba(244,246,255,.09)` | `rgba(11,18,32,.10)` |
| `--text` | `#F4F6FF` | `#0B1220` |
| `--muted` | `#98A4C2` | `#5B6684` |
| `--accent` | `#2E4BFF` | `#2E4BFF` |
| `--accent-strong` | `#4360FF` | `#1F3AE0` |
| `--accent-soft` | `rgba(46,75,255,.16)` | `rgba(46,75,255,.10)` |
| `--success` | `#35C77A` | `#128A53` |
| `--warning` | `#F4B942` | `#B07800` |
| `--danger` | `#FF6B6B` | `#C62828` |
| `--radius-lg` | `22px` | same |
| `--radius-md` | `16px` | same |

Bottom nav: translucent dark bar with blur (graceful fallback), active item accent, centre FAB raised. Hero overlay: vertical gradient from transparent to `rgba(5,9,20,.92)` for legibility (WCAG AA for the estate name over the image). Maintain ≥44px targets, `:focus-visible` rings, `prefers-reduced-motion`, `forced-colors`, and dark/light parity.

Assets (all local, generated, no network): `public/assets/img/estate-hero.png` (generated with GD — dusk sky, building silhouettes, courtyard lights; 1600×900, usable behind text), plus the existing icon set regenerated in navy/blue brand colours. A `scripts/generate-estate-hero.php` (idempotent) is required alongside the icon generator. Facility thumbnails may be CSS gradients + inline SVG icons rather than photographs.

## 6. Constraints

- Keep every Task 001 guarantee: server-side authority, escaping with `e()`, CSP unchanged (no inline `<script>`/`style=`/`onclick`), no external CDNs/fonts/images, root-relative asset URLs, all 210 existing tests green.
- Tabs are real links with query parameters and work without JavaScript.
- Preview content is clearly labelled and controlled by `UI_PREVIEW_CONTENT`; never present preview rows as live operational data.
- Do not fabricate module data (visitor counts, parking occupancy, payments). Keep the existing honest module status where relevant.
- Accessibility: single `h1` per page, labelled icon-only buttons, `aria-current` on the active nav item, visible focus, dialog semantics for the QR sheet.
- Responsive: primary target 360–430px; must remain usable on tablet/desktop (≥1024px keeps the existing sidebar treatment or a centred column).

## 7. Acceptance

1. `GET /app`, `/app/visitors`, `/app/visitors?tab=history`, `/app/bookings`, `/app/bookings?tab=bookings` all render 200 for an authenticated resident with an estate; unauthenticated requests redirect to `/login`; invalid `tab` values fall back to the default tab.
2. Home contains: greeting `Welcome Home`, hero estate name, 6 quick tiles with the exact labels above, announcements section with dots, bottom nav with 5 slots and centre QR button.
3. Visitors contains: tabs, CTA `+ Invite Visitor`, preview chip (when preview on), 3 upcoming / 2 history rows; history tab shows the history rows.
4. Bookings contains: tabs with Facilities active by default, CTA `+ New Booking`, 4 facility cards with status text; My Bookings tab shows an empty state.
5. QR sheet: dialog markup present, opened by FAB/Access tile, Escape/backdrop close, focus management, no credentials in markup.
6. No inline scripts/styles; escaping test for preview strings; a11y checks (h1 count, labelled buttons, aria-current).
7. `php tests/run.php` still passes (existing 210 + new tests), `php -l` clean, `node --check` clean.
8. Live smoke on `127.0.0.1:8010` (Lead's dev server) after integration; screenshots not required (sandbox), but rendered HTML must be reviewed for structure and the live pages must load with CSS/hero/JS 200.

## 8. Coordination / write scopes

| Agent | Task | Owns |
|---|---|---|
| Lead | T9 | `docs/**`, `config/**`, `.env*`, `app/Core/**`, `app/Middleware/**`, integration + final verification |
| pwa-shell | T6 | `app/Views/**`, `public/**`, `scripts/generate-*.php` |
| modules | T7 | `app/Modules/**`, `routes/**` |
| tests | T8 | `tests/**`, `README.md` |

Everything else (database, security kernel) is unchanged for this increment. Follow the base contract's rules (strict types, prepared statements, escaping, lint, no scope crossing, honest reports).
