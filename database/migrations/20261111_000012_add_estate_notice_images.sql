-- MtaaPass/DashPASS Increment 12 — announcement images (estate notices)
-- Staged migration: additive ALTER estate_notices (DESIGN FROZEN — NOT APPLIED)
--
-- Build-order freeze (Lead, 2026-11-11): automated billing ships FIRST as migration 000011;
-- this announcement-image migration becomes 000012 afterwards.
--
-- Design freeze: only two nullable payload columns — image_path VARCHAR(255) + image_alt
-- VARCHAR(160). No new table, no FK, no index (the serving queries already use
-- idx_estate_notices_estate_status_published; the image fields are display payload, never
-- filter/sort keys). No data statements, no backfill, no destructive changes.
--
-- Additive-only, MySQL 8 / MariaDB 10.4 compatible (ALTER ... ADD COLUMN ... AFTER with
-- nullable VARCHAR columns only; existing rows keep NULLs = "no image").

ALTER TABLE estate_notices
    ADD COLUMN image_path VARCHAR(255) NULL AFTER body,
    ADD COLUMN image_alt VARCHAR(160) NULL AFTER image_path;
