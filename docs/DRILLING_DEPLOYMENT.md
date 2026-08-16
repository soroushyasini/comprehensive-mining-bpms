# Drilling module deployment runbook

This runbook deploys the EMCORE daily drilling module without losing or deduplicating legacy reports.

## 1. Release contents

Deploy these files from the same Git revision:

- `database/migrations/003_emcore_drilling_operations.sql`
- `database/migrations/004_emcore_drilling_legacy_staging.sql`
- `emcore_api/emcore_drilling_reports.php`
- `panels/emcore_drilling_reports_panel.html`
- `tools/import_legacy_drilling_masters.php`
- `tools/import_legacy_drilling.php`

Keep the existing shared API files (`_bootstrap.php`, `_audit.php`, and `_module_permissions.php`) at the revision documented by the repository release.

## 2. Prerequisites

- A tested database backup and a recoverable copy of the current ProcessMaker web files.
- PHP CLI using the same PHP major/minor version as the ProcessMaker server.
- PHP extensions: PDO MySQL, JSON, mbstring, and session support.
- Existing EMCORE migrations `001` and `002` already applied.
- Existing `emcore_mines` records for the six legacy project/mine names.
- The database function `shamsi_slash_to_gregorian_date` installed and tested.
- A ProcessMaker user with active `authorization` administration access. After migration `003`, that user receives full initial access to `drilling_daily_reports`.
- Clean exports of:
  - `emidco_db_projects.sql`
  - `emidco_db_gamaneh.sql`
  - `prc_db_gozaresh_ruzane_copy2.csv`

Do not use a CSV that has been opened and re-saved with a different delimiter or character encoding. The importer requires a UTF-8, comma-delimited CSV with the original 45 columns.

## 3. Set deployment paths

The examples use shell variables only to keep commands readable. Replace the values with verified, explicit server paths:

```bash
EMCORE_RELEASE=/srv/releases/comprehensive-mining-bpms
PM_PUBLIC=/opt/processmaker/workflow/public_html
LEGACY_EXPORTS=/srv/import/emcore-drilling
PM_DATABASE=workflow
```

Confirm them before copying or executing anything:

```bash
test -f "$EMCORE_RELEASE/emcore_api/emcore_drilling_reports.php"
test -d "$PM_PUBLIC/emcore_api"
test -f "$LEGACY_EXPORTS/prc_db_gozaresh_ruzane_copy2.csv"
```

## 4. Back up before mutation

Use the server's approved backup mechanism. At minimum, capture the ProcessMaker/EMCORE database and the deployed EMCORE API directory. Do not place database dumps inside the web root or Git repository.

Example using a protected MySQL option file:

```bash
mysqldump --defaults-extra-file=/secure/mysql-backup.cnf \
  --single-transaction --routines --triggers "$PM_DATABASE" \
  > /secure/backups/emcore-before-drilling.sql
```

## 5. Server-side syntax gate

This must pass using the production-compatible PHP CLI before files become reachable:

```bash
php -l "$EMCORE_RELEASE/emcore_api/emcore_drilling_reports.php"
php -l "$EMCORE_RELEASE/tools/import_legacy_drilling_masters.php"
php -l "$EMCORE_RELEASE/tools/import_legacy_drilling.php"
```

Stop deployment if any lint command fails.

## 6. Apply database migrations

Apply migrations in order to the same database that contains `USERS` and the existing `emcore_*` tables:

```bash
mysql --defaults-extra-file=/secure/mysql-deploy.cnf "$PM_DATABASE" \
  < "$EMCORE_RELEASE/database/migrations/003_emcore_drilling_operations.sql"

mysql --defaults-extra-file=/secure/mysql-deploy.cnf "$PM_DATABASE" \
  < "$EMCORE_RELEASE/database/migrations/004_emcore_drilling_legacy_staging.sql"
```

Both migrations are rerunnable. Verify the foundation:

```sql
SELECT module_key, is_active
FROM emcore_modules
WHERE module_key = 'drilling_daily_reports';

SELECT serial_number, status
FROM emcore_drilling_rigs
ORDER BY serial_number;

SELECT COUNT(*) AS checklist_items
FROM emcore_drilling_checklist_items
WHERE is_active = 1;
```

Expected: one active module, rigs `1030`, `1031`, and `1036`, and 14 checklist items.

## 7. Deploy the API without replacing local secrets

Copy only the versioned endpoint. Preserve the existing ignored `emcore_config.php`:

```bash
install -m 0640 \
  "$EMCORE_RELEASE/emcore_api/emcore_drilling_reports.php" \
  "$PM_PUBLIC/emcore_api/emcore_drilling_reports.php"
```

If shared helpers in production are older than the release, deploy the reviewed helper files from the same revision before the endpoint. Never overwrite `emcore_config.php` with the example configuration.

## 8. Import all 170 borehole masters

First run a dry run:

```bash
php "$EMCORE_RELEASE/tools/import_legacy_drilling_masters.php" \
  "$LEGACY_EXPORTS/emidco_db_projects.sql" \
  "$LEGACY_EXPORTS/emidco_db_gamaneh.sql"
```

Expected against the supplied source: six legacy projects, six mapped mines, 170 parsed boreholes, and zero unmapped projects. If a mine is unmapped, correct the reviewed mine-name mapping before continuing.

Commit using an active ProcessMaker user that has drilling create permission:

```bash
php "$EMCORE_RELEASE/tools/import_legacy_drilling_masters.php" \
  "$LEGACY_EXPORTS/emidco_db_projects.sql" \
  "$LEGACY_EXPORTS/emidco_db_gamaneh.sql" \
  --commit \
  --actor-usr-uid=YOUR_32_CHARACTER_USR_UID
```

Rerunning is safe: existing mine/borehole pairs are reported as existing rather than duplicated.

## 9. Dry-run the 2,035 daily reports

Use `--create-boreholes` because the full report source contains two borehole codes not present in the 170-row legacy master. The dry run simulates their creation but writes nothing:

```bash
php "$EMCORE_RELEASE/tools/import_legacy_drilling.php" \
  "$LEGACY_EXPORTS/prc_db_gozaresh_ruzane_copy2.csv" \
  --create-boreholes
```

For the reviewed source, the acceptance targets are:

- `source_rows = 2035`
- `parse_errors = 0`
- no deduplication count or winner selection
- project value `1` normalized to `راه چمن`
- `stop_time = 99` mapped to `no_drilling`
- incomplete-key rows reported under `needs_review`

The exact ready/review counts can change if missing mine, borehole, rig, shift, or date values are corrected before deployment. Investigate every unexpected parser or mine-mapping error.

## 10. Commit the report import

Only after the dry-run output is reviewed:

```bash
php "$EMCORE_RELEASE/tools/import_legacy_drilling.php" \
  "$LEGACY_EXPORTS/prc_db_gozaresh_ruzane_copy2.csv" \
  --create-boreholes \
  --commit \
  --actor-usr-uid=YOUR_32_CHARACTER_USR_UID
```

The importer is idempotent by `legacy_id`. It never deletes or merges reports. Every source row is stored in `emcore_drilling_legacy_import_rows`; fully mapped rows also receive a canonical report and rows that cannot yet be mapped remain `needs_review`.

Verify reconciliation:

```sql
SELECT import_status, COUNT(*)
FROM emcore_drilling_legacy_import_rows
GROUP BY import_status;

SELECT COUNT(*) AS canonical_legacy_reports
FROM emcore_drilling_reports
WHERE legacy_id IS NOT NULL;

SELECT COUNT(*) AS duplicate_legacy_ids
FROM (
    SELECT legacy_id
    FROM emcore_drilling_reports
    WHERE legacy_id IS NOT NULL
    GROUP BY legacy_id
    HAVING COUNT(*) > 1
) d;
```

The last query must return zero.

## 11. Grant user permissions

Open the EMCORE authorization panel and grant `گزارش روزانه حفاری` capabilities deliberately:

- Data-entry operators: create + read, and update only if correction is part of policy.
- Supervisors: read + update.
- Administrators: full CRUD.
- Reporting-only users: read only.

Test one user from each applicable permission profile. Hidden panel buttons are not the security boundary; direct API calls must also return `403` when unauthorized.

## 12. Install the ProcessMaker Panel WebControl

In ProcessMaker Designer:

1. Back up/export the existing daily drilling Dynaform.
2. Create a new Dynaform or a dedicated Panel WebControl for the EMCORE drilling module.
3. Replace the panel content with the complete contents of `panels/emcore_drilling_reports_panel.html`.
4. Ensure the panel and `/emcore_api/emcore_drilling_reports.php` are served from the same origin so the ProcessMaker session cookie is sent.
5. Save and force-refresh the browser to bypass cached Dynaform content.
6. Keep the classic form available read-only during the acceptance period; do not remove it on first deployment.

## 13. Acceptance tests

Perform these tests in the deployed browser session:

1. Read-only user can list/filter/get reports but sees no mutation controls.
2. Create-only operator can add both a registered and temporary crew member.
3. Mine selection filters boreholes correctly.
4. Rigs `1030`, `1031`, and `1036` are selectable independently of mine.
5. Day/night selection applies the expected default times.
6. `no_drilling` requires a cause, forces equal start/end depth, and stores no stop duration.
7. Partial stop rejects durations outside `0–12` hours.
8. A second report for the same borehole/date/shift is accepted.
9. Persian text renders safely and an HTML/script payload is displayed as text.
10. Missing/invalid CSRF returns `403`.
11. Create/update/delete operations produce `emcore_audit_log` entries.
12. Soft-deleted reports disappear from normal lists but retain audit history.

## 14. Rollback

For an application-only rollback:

1. Restore the previous Dynaform/Panel WebControl.
2. Restore the previous API file or remove routing to the new endpoint.
3. Revoke or deactivate `drilling_daily_reports` permissions/module access.
4. Leave imported tables intact for investigation; do not drop them during an incident.

For a full data rollback, stop writes and restore the pre-deployment database backup according to the approved recovery procedure. Do not attempt to reverse a committed import with ad-hoc `DELETE` statements because audit, crew, checklist, staging, and report records are relationally linked.
