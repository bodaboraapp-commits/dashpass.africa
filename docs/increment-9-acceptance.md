# Increment 9 — Acceptance record (platform super-admin portal)

**Date:** 2026-11-11 · **Status: ACCEPTED (gate evidence complete; final full-suite confirmation applied at close-out)**
**Contract:** `docs/platform-admin-contract.md` (binding, dashboard-only audit) · **Design:** SaaS operator portal over existing tables — **no schema changes**.

## Delivered scope
- **Routes:** 147 → **158** (web 114 + API 44); 11 platform web routes, all `['auth']` + controller platform-area/permission gates (no estate middleware, no platform APIs).
- **Platform shell** (`layouts/platform.php`, `platform.css`): separate dark-navy portal, product name + “Platform” label, operator identity (masked phone), sign-out, permission-filtered nav Overview/Estates/Plans/Subscriptions, back-to-app only when estate access.
- **Dashboard**: real stats (accounts, estates active/suspended/archived, active subscriptions, plans), permission-filtered quick links, **dashboard-only platform audit viewer** (`recent_audit`, `platform.audit.view`) — no `/platform/audit` route.
- **Estates**: list/search-status filter, detail (account/admin/subscription/status form), **provisioning transaction** — optional new/existing account, estate + unique slug, 3 default settings (visitor validity 240, visitor parking grace 30, parking overstay grace 30), admin user create-or-link by phone + `estate_admin` membership, optional plan subscription (trialing), all-or-nothing with a rollback seam; status lifecycle active/suspended/archived (no hard deletes).
- **Plans**: create (code 3–40 `^[A-Z0-9_]{3,40}$`, numeric price/limits via `between:`), duplicate 422 `PLAN_EXISTS`, retire idempotent + audit, retired not selectable.
- **Subscriptions**: list, plan change (active plans), status transitions with terminal **409 `SUBSCRIPTION_CLOSED`**, one-current-per-estate, no deletes.
- **Account integration**: “Platform console” link driven by `platform_access`/`platform_permissions` resolved from active platform membership only — visible for the platform operator, hidden for residents/estate admins; platform slugs never leak into estate permissions.
- **Security hardening (found during review)**: platform namespace filtered to `platform.*` only (the super-admin wildcard had exposed 47 estate slugs to the platform payload); race-safe uniqueness 422s (estate slug/plan code/user phone); guarded conditional subscription update; plan re-checked inside the provisioning transaction.

## Gate evidence
| Check | Result |
|---|---|
| DB | **8 migrations / 37 tables / 52 permissions / 13 roles / 217 links** — unchanged (no schema change); `/health` applied=8 |
| Backend proof (`t64_verify.php`) | **48/48 PASS** — 34 service checks + 158-route/11-route assertions + HTTP pages/403/302/keys/dashboard-only audit |
| Platform E2E (qa-e2e) | **67 pass / 0 fail / 1 skip (68)** — area gate, dashboard, provisioning transaction, plans, subscriptions + terminal 409, CSRF/XSS/PII/no-hard-delete; no product gaps |
| Test fold (qa-mail) | **29/29 PASS** — platform access/provisioning/management incl. true mid-transaction rollback |
| Regressions (clean DB) | Inc-5 71/71 · Inc-6 95/96 (0 FAIL) · Inc-7 78/80 (0 FAIL) · Inc-8 101/102 (0 FAIL) |
| Lint | 221 files / 0 failures |
| Bug log | namespace leak (fixed), 3 race-safety fixes, Billing nav permanently-hidden gating fix, 9 frozen-key view mismatches fixed |

## Known limitations / deferred
- Seeded platform roles still hold estate-permission links via the wildcard (by design for `platform_super_admin`; a later RBAC tightening could scope platform roles to `platform.*` only).
- Estate status update is last-write-wins on concurrent flips (low; audit pairs consistent).
- No platform impersonation, billing/invoices (Inc-10), usage analytics or support ticketing.
- Platform account is separate from estate membership — platform operators see estate data only through platform screens.

## Deploy requirement
No migration: pin **`be9b410`** → pull → `POST /deploy-sync.php?action=install` with `X-Deploy-Token` (idempotent no-op; `/health` stays **applied=8 / 37 tables / 217 links**) → smoke `/platform`, `/platform/estates` (+new/detail/status), `/platform/plans`, `/platform/subscriptions` as `+254700000020`; Grace/Jane/guard 403; unauth 302. Bundle: `storage/temporary/mtaapass-deploy.zip` (sha256 `8F8C511A…`). Packet: `docs/deploy-now-inc6-inc7.md` §4g, `docs/deployment-cpanel.md` §3d.

## Next
Increment 10 — billing/invoices: migration 10 → **9 migrations / 39 tables / 165 routes**, platform billing (invoices, payments, past-due CLI).
