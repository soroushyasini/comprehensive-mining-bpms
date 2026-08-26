# Visitor log deployment runbook

This runbook targets the current Windows ProcessMaker installation.

## Paths

```bat
set "EMCORE_RELEASE=C:\pmlearning\comprehensive-mining-bpms"
set "MYSQL_BIN=C:\pmlearning\mysql\bin"
set "PM_EMCORE_API=C:\pmlearning\bpms\workflow\public_html\emcore_api"
set "PM_DATABASE=wf_pishro"
```

Confirm `PM_DATABASE` against `dbname` in `%PM_EMCORE_API%\emcore_config.php` before applying the migration. Back up the database and live API using the approved operational process.

## Preflight

```bat
cd /D "%EMCORE_RELEASE%"
git pull --ff-only
git status --short
php -n -l "%EMCORE_RELEASE%\emcore_api\emcore_visitor_log.php"
```

Do not deploy with an unexpected modified worktree. Local ignored configuration and known operator-owned untracked files are not release changes.

## Apply migration

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 --show-warnings "%PM_DATABASE%" < "%EMCORE_RELEASE%\database\migrations\006_emcore_visitor_log.sql"
```

The migration is rerunnable. It creates `emcore_visits`, registers `visitor_log`, and gives existing active authorization administrators initial full CRUD access without overwriting later permission choices.

Verify:

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT module_key,name_fa,is_active FROM emcore_modules WHERE module_key='visitor_log'; SHOW CREATE TABLE emcore_visits; SELECT usr_uid,can_create,can_read,can_update,can_delete FROM emcore_user_permissions WHERE module_key='visitor_log';"
```

## Deploy API

```bat
copy /Y "%EMCORE_RELEASE%\emcore_api\emcore_visitor_log.php" "%PM_EMCORE_API%\emcore_visitor_log.php"
fc /B "%EMCORE_RELEASE%\emcore_api\emcore_visitor_log.php" "%PM_EMCORE_API%\emcore_visitor_log.php"
php -n -l "%PM_EMCORE_API%\emcore_visitor_log.php"
```

`fc` must report no differences and the live PHP file must pass syntax validation.

## Install panel

In ProcessMaker Designer:

1. Create or open the visitor-log Dynaform.
2. Add a Panel WebControl.
3. Paste the complete contents of `panels\emcore_visitor_log_panel.html`.
4. Save and force-refresh the browser.
5. Keep the panel and `/emcore_api/emcore_visitor_log.php` on the same origin.

## Assign permissions

Use the EMCORE authorization matrix to grant `ثبت ورود و خروج مراجعان` deliberately:

- reception/security operators normally need create, read, and update;
- reporting users need read only;
- delete should be limited to administrators.

## Acceptance checks

1. Anonymous access returns `401`.
2. A read-only user can filter and view but sees no mutation buttons.
3. An operator can register a visitor with an active ProcessMaker host.
4. Manual host entry works without creating a ProcessMaker user or person record.
5. A new arrival appears above completed rows with status `داخل مجموعه`.
6. Checkout records the current database time and cannot be repeated.
7. Manual correction rejects an exit earlier than entry.
8. Repeated visits for the same visitor/date/host remain separate.
9. Persian text and an HTML/script payload render as text.
10. Missing or invalid CSRF returns `403` for writes.
11. Create, edit, checkout, and delete produce `emcore_audit_log` entries.
12. A deleted visit disappears from the panel but remains in the database with `deleted_at` populated.

## Post-deployment verification

```bat
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4 "%PM_DATABASE%" -e "SELECT COUNT(*) AS active_visits,SUM(exited_at IS NULL) AS currently_inside FROM emcore_visits WHERE deleted_at IS NULL; SELECT action,COUNT(*) AS audit_rows FROM emcore_audit_log WHERE module_key='visitor_log' GROUP BY action;"
```

Do not drop the table as a rollback after operators have entered real visits. Disable the module and restore the previous panel/API while preserving data for a corrective release.