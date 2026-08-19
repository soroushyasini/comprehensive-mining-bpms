# Drilling module deployment runbook

This runbook deploys the EMCORE daily drilling module without losing or deduplicating legacy reports.

## 1. Release contents

Deploy these files from the same Git revision:

- `database/migrations/003_emcore_drilling_operations.sql`
- `database/migrations/004_emcore_drilling_legacy_staging.sql`
- `database/migrations/005_emcore_mine_relationships_and_legacy_mapping.sql`
- `emcore_api/emcore_drilling_reports.php`
- `emcore_api/emcore_mines.php`
- `panels/emcore_drilling_reports_panel.html`
- `panels/emcore_mines_panel.html`
- `tools/import_legacy_drilling_masters.php`
- `tools/import_legacy_drilling.php`

Keep the existing shared API files (`_bootstrap.php`, `_audit.php`, and `_module_permissions.php`) at the revision documented by the repository release.

## 2. Prerequisites

- A tested database backup and a recoverable copy of the current ProcessMaker web files.
- PHP CLI using the same PHP major/minor version as the ProcessMaker server.
- PHP extensions: PDO MySQL, JSON, mbstring, and session support.
- Existing EMCORE migrations `001` and `002` already applied.
- Reviewed mine IDs `3`, `8`, `9`, and `10`, company `معدن کاران مس میامی`, and person `سید محمدداود فیض‌آبادی` must match the preflight documented in migration `005`. The migration creates the remaining historical mine mappings.
- The database function `shamsi_slash_to_gregorian_date` installed and tested.
- A ProcessMaker user with active `authorization` administration access. After migration `003`, that user receives full initial access to `drilling_daily_reports`.
- Clean exports of:
  - `emidco_db_projects.sql`
  - `emidco_db_gamaneh.sql`
  - `prc_db_gozaresh_ruzane_copy2.csv`

Do not use a CSV that has been opened and re-saved with a different delimiter or character encoding. The importer requires a UTF-8, comma-delimited CSV with the original 45 columns.
The reader supports the supplied outer-quoted ProcessMaker/Navicat export and preserves multiline text fields while reconstructing the original 2,035 logical records.

## Windows CMD deployment for the current installation

This is the authoritative command profile for the current Windows server. Run it
from Command Prompt (`cmd.exe`), not PowerShell. The later Unix examples remain
only as equivalents for other installations.

```bat
cd /d C:\pmlearning\comprehensive-mining-bpms
set "EMCORE_RELEASE=C:\pmlearning\comprehensive-mining-bpms"
set "MYSQL_BIN=C:\pmlearning\mysql\bin"
set "PM_EMCORE_API=C:\pmlearning\bpms\workflow\public_html\emcore_api"
set "LEGACY_EXPORTS=C:\pmlearning\drilling-import"
set "BACKUP_ROOT=C:\pmlearning\backups\emcore-drilling-before-005"
set "PM_DATABASE=wf_pishro"
```

Confirm `PM_DATABASE` against the `dbname` value in
`%PM_EMCORE_API%\emcore_config.php`; change it if necessary. Put clean copies of
`emidco_db_projects.sql`, `emidco_db_gamaneh.sql`, and
`prc_db_gozaresh_ruzane_copy2.csv` in `%LEGACY_EXPORTS%`.

Verify the release and live helpers:

```bat
if not exist "%EMCORE_RELEASE%\emcore_api\emcore_drilling_reports.php" echo ERROR: release API missing
if not exist "%PM_EMCORE_API%\_bootstrap.php" echo ERROR: live bootstrap missing
if not exist "%PM_EMCORE_API%\_audit.php" echo ERROR: live audit helper missing
if not exist "%PM_EMCORE_API%\_module_permissions.php" echo ERROR: live permission helper missing
if not exist "%PM_EMCORE_API%\emcore_config.php" echo ERROR: live configuration missing
if not exist "%LEGACY_EXPORTS%\prc_db_gozaresh_ruzane_copy2.csv" echo ERROR: report CSV missing
```

Back up the live API and database before changing either. Use an approved backup
account; `-p` prompts for its password.

```bat
mkdir "%BACKUP_ROOT%"
robocopy "%PM_EMCORE_API%" "%BACKUP_ROOT%\emcore_api" /E /COPY:DAT /R:1 /W:1
"%MYSQL_BIN%\mysqldump.exe" -u YOUR_BACKUP_USER -p --single-transaction --routines --triggers "%PM_DATABASE%" > "%BACKUP_ROOT%\emcore-before-drilling.sql"
```

Run all four syntax checks on the exact release being deployed. They can be
repeated without loading the unrelated GD and OCI8 configuration:

```bat
php -n -l "%EMCORE_RELEASE%\emcore_api\emcore_drilling_reports.php"
php -n -l "%EMCORE_RELEASE%\emcore_api\emcore_mines.php"
php -n -l "%EMCORE_RELEASE%\tools\import_legacy_drilling_masters.php"
php -n -l "%EMCORE_RELEASE%\tools\import_legacy_drilling.php"
```

Apply the migrations in order to the ProcessMaker database:

```bat
"%MYSQL_BIN%\mysql.exe" -u YOUR_DEPLOY_USER -p "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\003_emcore_drilling_operations.sql"
"%MYSQL_BIN%\mysql.exe" -u YOUR_DEPLOY_USER -p "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\004_emcore_drilling_legacy_staging.sql"
"%MYSQL_BIN%\mysql.exe" -u YOUR_DEPLOY_USER -p "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\005_emcore_mine_relationships_and_legacy_mapping.sql"
```

Deploy both updated endpoints, preserving the existing live helpers and
`emcore_config.php`, then compare the copied files:

```bat
copy /Y "%EMCORE_RELEASE%\emcore_api\emcore_drilling_reports.php" "%PM_EMCORE_API%\emcore_drilling_reports.php"
copy /Y "%EMCORE_RELEASE%\emcore_api\emcore_mines.php" "%PM_EMCORE_API%\emcore_mines.php"
fc /B "%EMCORE_RELEASE%\emcore_api\emcore_drilling_reports.php" "%PM_EMCORE_API%\emcore_drilling_reports.php"
fc /B "%EMCORE_RELEASE%\emcore_api\emcore_mines.php" "%PM_EMCORE_API%\emcore_mines.php"
```

The CLI importers read `emcore_api\emcore_config.php` from the release checkout.
Copy the existing live configuration there only when the ignored release copy is
missing. It contains a secret and must remain restricted to administrators.

```bat
if not exist "%EMCORE_RELEASE%\emcore_api\emcore_config.php" copy "%PM_EMCORE_API%\emcore_config.php" "%EMCORE_RELEASE%\emcore_api\emcore_config.php"
```

Run both dry runs and review their totals before allowing writes:

```bat
php "%EMCORE_RELEASE%\tools\import_legacy_drilling_masters.php" "%LEGACY_EXPORTS%\emidco_db_projects.sql" "%LEGACY_EXPORTS%\emidco_db_gamaneh.sql"
php "%EMCORE_RELEASE%\tools\import_legacy_drilling.php" "%LEGACY_EXPORTS%\prc_db_gozaresh_ruzane_copy2.csv" --create-boreholes
```

After the dry-run output matches the acceptance targets in sections 8 and 9,
commit the imports with an active ProcessMaker user that has drilling create
permission:

```bat
php "%EMCORE_RELEASE%\tools\import_legacy_drilling_masters.php" "%LEGACY_EXPORTS%\emidco_db_projects.sql" "%LEGACY_EXPORTS%\emidco_db_gamaneh.sql" --commit --actor-usr-uid=YOUR_32_CHARACTER_USR_UID
php "%EMCORE_RELEASE%\tools\import_legacy_drilling.php" "%LEGACY_EXPORTS%\prc_db_gozaresh_ruzane_copy2.csv" --create-boreholes --commit --actor-usr-uid=YOUR_32_CHARACTER_USR_UID
```

Panel HTML is not copied into `%PM_EMCORE_API%`. Paste the complete contents of
`panels\emcore_mines_panel.html` into the mines Panel WebControl and
`panels\emcore_drilling_reports_panel.html` into the drilling Panel WebControl
as described in section 12.

## 3. Unix/Linux reference paths

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
php -l "$EMCORE_RELEASE/emcore_api/emcore_mines.php"
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

mysql --defaults-extra-file=/secure/mysql-deploy.cnf "$PM_DATABASE" \
  < "$EMCORE_RELEASE/database/migrations/005_emcore_mine_relationships_and_legacy_mapping.sql"
```

All three migrations are rerunnable. Verify the foundation:

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

Expected: one active module, rigs `1030`, `1031`, and `1036`, and 14 checklist items. Also verify migration `005`: mine ID `8` is named `تپه سیاه شمالی` with alias `تپه سیاه` and both ore subtypes; ID `9` is soft-retired into ID `8`; `تنگل نورا` has alias `تنگل`; and active records exist for `راه چمن`, `میامی`, and `کلاته برق`.

## 7. Deploy the API without replacing local secrets

Copy the two versioned endpoints. Preserve the existing ignored `emcore_config.php`:

```bash
install -m 0640 \
  "$EMCORE_RELEASE/emcore_api/emcore_drilling_reports.php" \
  "$PM_PUBLIC/emcore_api/emcore_drilling_reports.php"

install -m 0640 \
  "$EMCORE_RELEASE/emcore_api/emcore_mines.php" \
  "$PM_PUBLIC/emcore_api/emcore_mines.php"
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
- legacy project values `1`–`6` resolved through the reviewed mine-name/alias mapping
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
3. Replace the drilling panel content with `panels/emcore_drilling_reports_panel.html`.
4. Replace the existing mines panel content with `panels/emcore_mines_panel.html` so relationship fields and filtering match migration `005`.
5. Ensure the panel and `/emcore_api/emcore_drilling_reports.php` are served from the same origin so the ProcessMaker session cookie is sent.
6. Save and force-refresh the browser to bypass cached Dynaform content.
7. Keep the classic form available read-only during the acceptance period; do not remove it on first deployment.

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
13. The mines relationship filter separates owned, contractor, and personnel-related records.
14. Only one active `تپه سیاه شمالی` row is visible, with alias `تپه سیاه` and ore subtype `سولفیدی، اکسیدی`.
15. `راه چمن`, `میامی`, and `کلاته برق` show the reviewed company/person relationship.

## 14. Rollback

For an application-only rollback:

1. Restore the previous Dynaform/Panel WebControl.
2. Restore the previous API file or remove routing to the new endpoint.
3. Revoke or deactivate `drilling_daily_reports` permissions/module access.
4. Leave imported tables intact for investigation; do not drop them during an incident.

For a full data rollback, stop writes and restore the pre-deployment database backup according to the approved recovery procedure. Do not attempt to reverse a committed import with ad-hoc `DELETE` statements because audit, crew, checklist, staging, and report records are relationally linked.
