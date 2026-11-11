# Database integrity check — operations guide

`scripts/db-integrity-check.php` is a **read-only** post-deploy diagnostic for the
MtaaPass/DashPASS database. It verifies that migrations, schema, tenant foreign keys,
reference data and secrets/PII hygiene are all consistent after an install or upgrade.
Run it after **every** `deploy-sync.php?action=install` (dev, staging and production),
and whenever the app reports a database-related error.

- **Read-only:** the script only issues `SELECT`/`information_schema` queries. It never
  writes, migrates, seeds, drops or creates anything. It also never prints credentials
  (it displays the database *name* only).
- **Production-safe:** it can be run over SSH/cPanel Terminal on the production
  database. It does not modify data, sessions or audit history.
- **Non-zero on failure:** suitable for cron/CI/runbook gating.

## 1. Usage

```bash
# Human-readable report
php scripts/db-integrity-check.php

# Machine-readable JSON (for automation / deploy gates)
php scripts/db-integrity-check.php --json

# Help
php scripts/db-integrity-check.php --help
```

### XAMPP / local note

Local XAMPP PHP may print a startup warning
`Module "openssl" is already loaded in Unknown on line 0` **before** the JSON body.
That breaks naive JSON parsers (the checker itself is fine). Suppress the startup
warning for JSON piping:

```bash
php -d display_startup_errors=0 scripts/db-integrity-check.php --json
```

(On production PHP the warning normally does not appear; redirecting stderr and/or the
flag keeps CI output clean.)

### Exit codes

| Code | Meaning |
|---|---|
| `0` | All checks passed. |
| `1` | One or more integrity checks failed (or an unexpected error occurred — the script always fails loudly and never exits 0 on an exception). |
| `2` | Usage/refusal (e.g. not run from the CLI, bad invocation). |

The human report ends with `OK — database integrity checks passed (N checks)` on success
or `FAILED — X of N checks failed.` and lists each failing check with a short reason.

## 2. What it checks (26 checks)

### Migrations
1. `schema_migrations` table exists.
2. Migration file count equals applied-row count.
3. No unapplied migration files (files without a row).
4. No applied rows without a matching file.
5. **Checksums:** every applied row's recorded SHA-256 equals the current file hash —
   catches edited/rolled-back migration files.
6. Batch numbers and `applied_at` are sane (positive batch, non-null timestamp).

### Schema
7. Live table inventory equals the tables declared across the migration files
   (plus `schema_migrations`, which the migrator creates itself).
8. Every table uses `InnoDB`.
9. Every table uses `utf8mb4_unicode_ci`.
10. Database default charset/collation are `utf8mb4` / `utf8mb4_unicode_ci`.
11. Every table with a `uuid` column has a `UNIQUE(uuid)` index.
12. Every FK's child columns are covered by a left-prefix index.
13. Every **composite tenant FK** parent has the matching `UNIQUE(<cols>)` index
    (e.g. `subscriptions(id, estate_id)`, `billing_invoices(id, estate_id)`).
14. Foreign-key count is non-zero (sanity that FKs are present at all).

### Referential integrity
15. **Orphan-row scan for every foreign key** — child rows with all FK columns
    non-null but no matching parent. Any orphan fails the check (MATCH-SIMPLE
    nullable-composite semantics are respected).

### Data sanity
16. No undeclared `ENUM` values (catches blank `''` values written by non-strict
    MySQL when code writes an enum value the schema does not declare).
17. Permission total matches `PermissionsSeeder`.
18. Role total matches `RolesSeeder`.
19. Role-permission **link total** matches seeders (pre-Inc-10 / current: **217**).
20. **Per-role permission counts** match seeders (e.g. `estate_admin` 33,
    `resident` 17, `tenant` 16, `household_member` 11, `platform_super_admin` 52).
21. `estate_settings` values are valid JSON (one malformed setting fails).
22. Billing-state branch:
    - **Pre-Inc-10:** `billing_invoices`/`billing_payments` absent → PASS with an
      explicit “expected before the Inc-10 DB gate” note.
    - **Post-Inc-10:** tables present → requires ≥2 Greenview invoices and ≥1
      Greenview payment (the seeded demo invoices/payment).

### Secrets / PII
23. No plaintext PIN/OTP/credential columns (suspicious column-name scan; ENUM
    type discriminators such as `credential_type ENUM('pin','qr')` are labels, not
    secrets, and are excluded; known `*_hash`/`*_encrypted*` columns are expected).
24. Known hash/secret columns hold hashed values (minimum length sanity).
25. **Audit metadata PII sample** (last 500 non-null metadata rows):
    - **Fatal:** phone-number-like values and credential keys
      (`pin`/`otp`/`password`/`secret`/`token`).
    - **Allowed (noted):** `visitor.*` audit label fields such as `visitor_name` /
      `household_label` (the visitor-access contract permits ids/labels; it forbids
      PIN/QR token/phone). Name values in stricter domains (admin/staff/incident/
      notice/parking/platform/billing) are fatal.
26. (The core-tables presence check) `permissions`, `roles`, `role_permissions`,
    `estate_settings`, `audit_logs` must exist; on an empty/unmigrated DB the script
    reports readable failures and skips data-dependent checks instead of crashing.

> A freshly created (unmigrated) database therefore fails cleanly with exit 1 and
> explains exactly what is missing, rather than throwing a stack trace.

## 3. Expected counts (what “green” looks like)

| State | Migrations | Tables | Permissions | Roles | Role links | FK constraints | Settings | Billing |
|---|---|---|---|---|---|---|---|---|
| **Pre-Inc-10** (current) | **8** | **37** | **52** | **13** | **217** | **64** | **5** (Greenview) | absent |
| **Post-Inc-10** (after migration 10 gate) | **9** | **39** | **52** | **13** | **217** | **72** | **5** | 2 invoices / 1 payment (Greenview) |

The numbers are derived/compared against the repository migrations/seeders, not
hardcoded counters, so they stay correct as increments are added — but the table above
is the quick double-check for the current release.

The report also prints:
`Counts: <migrations> migrations, <tables> tables, <permissions> permissions,
<roles> roles, <role_links> role links, <foreign_keys> FK constraints, <settings>
settings rows, <audit_rows> audit rows`.

## 4. Post-deploy runbook step (after every install)

Run this immediately after `POST /deploy-sync.php?action=install` (or
`php scripts/install.php`) and after any rollback/restore:

```bash
# 1. Health endpoint (app-level)
curl -s https://dashpass.africa/health
# expect: "migrations":{"status":"ok","applied":8,"pending":0}   (9 after Inc-10)

# 2. Database integrity (read-only)
php -d display_startup_errors=0 scripts/db-integrity-check.php
# expect: OK — database integrity checks passed (26 checks)   [exit 0]
```

- In CI/automation: `php scripts/db-integrity-check.php --json` and assert
  `status == "ok"` and `failures == 0` (suppressing the XAMPP startup warning when
  present).
- Production via cPanel Terminal: run the same command from the app root; it is
  read-only and prints no credentials.
- Record the output (counts + `OK`) in the deploy/smoke notes for the release.

## 5. Interpreting FAILs and first-response actions

| Failing check | Likely cause | Action |
|---|---|---|
| `schema_migrations` / file-vs-row / checksum | deploy-sync `install` not run, migration file edited after being applied, wrong code version | Confirm `deploy-sync?action=status`; run `action=install` for the pinned release; restore the correct migration file (never edit an applied migration) |
| table inventory / engine / collation | partial migration, manual DB changes, wrong DB restored | Inspect `schema_migrations`; apply pending migrations; restore from backup if tables are missing |
| `UNIQUE(uuid)` / FK index / composite parent index | manual schema edits or non-standard restore | Do not patch production by hand; re-run the pinned migration or restore; escalate to the DB owner |
| orphan rows | data imported/restored while FK checks were off | Identify affected FK (check detail); repair or restore within a maintenance window; never delete audit history silently |
| undeclared ENUM values | non-strict MySQL write of an unsupported status (e.g. `estate_memberships.status = 'inactive'`) | Fix the calling code/status value; correct rows to declared values on a disposable/dev DB first; enforce strict SQL mode where possible |
| RBAC totals / per-role counts | permission/role seeder not run, partial seed, manual link edits | Re-run the installer (idempotent) for the pinned release; verify `permissions`, `roles`, `role_permissions` |
| settings JSON | malformed value written outside the validated settings API | Fix via the admin settings UI/API or seeded installer; never hand-edit JSON in production |
| billing-state branch | migration 10 missing/present unexpectedly | Pre-Inc-10: expected (PASS). Post-gate: run the Inc-10 installer/seed; confirm invoices 2 / payment 1 |
| plaintext credential column / weak hash | schema drift or manual table created | Restore correct schema; rotate any exposed credentials; treat as a security incident |
| audit metadata PII | product code writing PII into audit metadata | Fix the audit call to log uuids/keys only; purge only per retention policy with approval (audit history is append-only) |

## 6. Security & limitations

- Read-only; no credentials or PII values are printed (only the database name and
  aggregate counts; PII *hits* are reported by action/check, never by content).
- The audit PII scan is a **sample** (last 500 non-null metadata rows) for speed; the
  absence of failures does not prove every historical row is PII-free.
- Orphan scans are exact per FK but can be slower on very large tables; the script is
  intended for post-deploy checks, not continuous polling.
- The checker validates database *structure and reference data*; it complements (does
  not replace) `/health`, the test suite and the application smoke matrix.
