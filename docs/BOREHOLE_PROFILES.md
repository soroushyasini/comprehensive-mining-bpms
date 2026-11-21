# Borehole profiles

The `boreholes` panel manages the canonical `emcore_boreholes` records. The mine and per-mine unique code are required. Area or zone, collar X/Y/Z, coordinate system, azimuth, dip and notes are optional. Angles are in degrees and the recorded depth is in meters. The panel transmits dates as `YYYY/MM/DD` in the Jalali calendar. The API normalizes Persian and Arabic digits in dates and decimal fields. It preserves the entered borehole-code spelling, apart from trimming surrounding whitespace.

`status` controls whether the daily report form offers the borehole. `profile_drilling_status` describes the borehole as a whole. Inactivating a borehole keeps its record and report history. The first report link permanently locks the mine, including when the report is later soft-deleted or reassigned. The database migration also locks mines on records that already have reports. Borehole removal is outside this version.

The profile's manual depth, start, confirmed completion date and status are stored in the `profile_*` columns. Supplying or changing a completion date requires `completion_confirmed=1`. `daily_report_summary` is returned by `get` only to users with daily report read permission. Its count, dates and ending depth come from current non-deleted reports for that borehole. The first and last report boundaries use the same date, time, shift and ID order as the analytics. The dates describe available coverage and do not automatically set the profile dates.

## Install and verify

1. Verify that migrations `001`, `002`, `003` and `009` are applied and that the database provides `shamsi_slash_to_gregorian_date`. Apply `026_emcore_borehole_profiles.sql` before publishing the PHP files. The migration account needs stored-procedure and trigger privileges. Schedule the migration while report writes are paused, since it recreates its two triggers on a rerun.
2. Publish `emcore_boreholes.php` and `_drilling_report_summary.php`, then the updated `emcore_drilling_reports.php` and `emcore_drilling_analytics.php`. Install `emcore_boreholes_panel.html` as a same-origin ProcessMaker WebControl and add its menu entry. Republish `emcore_drilling_reports_panel.html` to refresh the list when opening a new report.
3. Assign `boreholes` read to viewers and create/update to designated editors. The daily report read permission controls access to derived data. Existing report-users keep their lookup access through the report/analytics endpoints. The migration creates the new module entry without granting access automatically.
4. Verify that a newly created active borehole is offered when opening **New report**, and appears in analytics after its first report and a dashboard refresh. Check that an inactive borehole remains available in the analytics history. Run `node tools/check_drilling_analytics_release.js`.

To roll back the panel, remove its menu entry and restore the previous API versions together if needed. Retain the additive schema and saved profile data.

The integration test uses a disposable database named `emcore_boreholes_fixture`. Load `tools/fixtures/boreholes/schema.sql` and migrations `001`, `002`, `003`, `009` and `026`, in that order. In this database, grant fixture user `00000000000000000000000000000001` read, create and update on `boreholes`, and `22222222222222222222222222222222` read only. Set the normal `EMCORE_DB_*` connection variables to the fixture and `EMCORE_BOREHOLES_FIXTURE=1`. Then start `php -S 127.0.0.1:33426 tools/fixtures/boreholes/router.php` and run `node tools/test_boreholes_integration.js`.

## Read-only legacy comparison

```text
php tools/compare_legacy_boreholes.php path/to/projects.sql path/to/gamaneh.sql
```

Run from a trusted machine using the existing EMCORE database configuration, preferably with a read-only user. The tool parses the two exports locally (one `INSERT` per line) and starts a read-only database transaction. The JSON output lists exact matches, missing rows, ambiguous projects or spelling variants for review, and current records outside the snapshot. It never executes the supplied statements. A spacing, case or separator match is a suggestion for review. The smaller snapshot is not a removal list. Imports and history reconciliation are separate operations.
