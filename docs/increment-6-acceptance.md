# Increment 6 — Acceptance record (staff directory + incidents)

**Date:** 2026-11-11 · **Status: ACCEPTED**
**Contract:** `docs/staff-incidents-contract.md` (Option B permissions) · **Blueprint:** §7 staff, §8 incidents/security

## Delivered scope
- Staff directory: resident household staff (create/list/deactivate/reactivate), admin estate-wide directory, masked phones, duplicate/archived-household guards, tenant isolation, audit (PII-free).
- Incidents: resident report + own-only visibility; guard report at session gate + own-only list; admin queue/filters/detail + status workflow (`open → investigating → resolved/dismissed`, resolution notes required, terminal 409), web + API, David cross-tenant 404.
- Email alerts on high/critical incidents (masked `mail.log` via log/SMTP drivers, `email.alert_sent`/`email.alert_failed` audits, non-blocking failure).
- UI phase 2: resident Staff/Incidents pages + account “Household services”; guard Incidents nav + dashboard action; admin Staff/Incidents nav (permission-filtered) with active states; live smoke as Jane/John/Grace/David (+ auditor 403 correct).
- Route gate: **121 routes** (web 77 + API 44); 22 new routes with frozen middleware.

## Gate evidence
| Check | Result |
|---|---|
| Migrations/tables/links | **7 / 36 / 204** (resident 16, tenant 15, household_member 10, estate_admin 31); install twice idempotent |
| Demo fixtures | 3 staff (`3a…0001-3`), 3 incidents (`3b…0001-3`) seeded, Riverside none |
| Backend in-process proof (T38) | **57/57 PASS** — routes, staff rules, incident workflow/terminal, guard gate, email alert + audit, no hard deletes, PII-free, CSRF, David scoping |
| UI live smoke (T39 phase 2) | **66/66 PASS** + 11/11 permission/nav checks |
| Independent HTTP E2E (qa-e2e T57B) | **95/96 PASS, 0 FAIL** (1 SKIP = Inc-7), Inc-5 regression 71/71 — masked alerts, 422/409 codes, tenant isolation |
| Full test suite (Lead exclusive) | **575/575 PASS**, 70 files, 1157s, exit 0 (152 unit / 357 integration / 66 security) |
| Health | `/health` 200, migrations applied 7, pending 0 |
| Bug found & fixed | phone normalization accepted <7-digit bare fragments → now `INVALID` (regression test added) |

## Deploy requirement
Owner deploy: pull + `POST /deploy-sync.php?action=install` with `X-Deploy-Token` (migration 8) → `/health` applied=7 / 36 tables / 204 links, then smoke `/app/staff`, `/app/incidents`, `/gate/incidents`, `/admin/staff`, `/admin/incidents` (Grace; David cross-tenant; John gate). Bundle: `storage/temporary/mtaapass-deploy.zip`.

## Deferred (documented)
Staff edit route (service exists, no route), guard incident detail (own-only list by design), owner alert inbox config (`MAIL_ALERT_TO`), live Cloudflare purge + server `.env` production settings.

## Next
Increment 7 — estate notices (migration 9, permissions 52, role links **217**, routes **131**, 4 demo notices).
