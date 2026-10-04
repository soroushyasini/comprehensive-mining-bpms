# Meeting minutes verification — 2026-10-04

## Executed

- PHP 8.2 lint: `_minutes_domain.php`, `_minutes_storage.php`, `emcore_meeting_minutes.php`.
- MySQL 8.4 / PHP 8.2 isolated integration: **136 checks passed**.
- Chrome/Chromium with jQuery 1.11.3, desktop 1440×1000 and mobile 390×844:
  **31 browser checks passed**.
- Standalone PHP calendar: **19 checks passed without any SQL connection**.
- `check_meeting_minutes_release.js`: passed JS parsing, panel selector wiring,
  unique IDs, safe text rendering, same-origin assets and Jalali/leap boundaries.
- Read-only deployment preflight: all checks passed in the disposable fixture.
- A mixed endpoint/helper deployment was rejected with HTTP 503 before database
  access. The sync tool passed a Windows filesystem test for dry-run, destination
  hashes, old-file backups, unchanged configuration and temporary-file cleanup.
- Existing procurement notices, procurement analytics and procurement workflow
  release checks: all passed.
- Existing panel SHA-256 values match their pre-task values, including the user's
  pre-existing drilling panel changes. No prior panel was rewritten.
- `git diff --check`: passed. Temporary minutes test API/database containers and
  their dedicated network were removed by the runner.

## Behavioral coverage

Company/year isolation; concurrent allocation and concurrent duplicate request;
legacy original numbering and unknown fields; Persian/Arabic digit normalization;
legacy/new-number overlap; numbering beyond 9999; immutable issued number after
date corrections; Jalali leap/day validation; midnight ending; officer presence;
external participants; retained inactive-user/company snapshots; permission and
CSRF rejection; optimistic edit conflict; scan/attachment distinction; replacement
history and download logs; empty/oversized/forged uploads; SHA-256 integrity check;
audit failure rolling back counters/records and cleaning failed upload bytes;
server filtering/sorting/pagination; migration reruns; escaped XSS; keyboard
calendar/picker/modal behavior; multi-file queue failure and remaining-file retry;
read-only controls and responsive layout.

## Remaining environment acceptance

### Company selection preview hotfix

Removed the redundant SQL date-conversion call from the validated PHP calendar.
The integration suite now drops the fixture SQL calendar routine before preview,
creation, editing and filtering to guard against missing workspace routines.
The exact original error was reproduced with the original converter and an SQL
routine double returning a conflicting date. The fixed converter returned the
correct date independently of that SQL result. The integration suite completed
with the SQL routine removed, including preview, create, edit and date filters.
Both empty times, either time alone, clearing times during edits, bad entered
times, conditional overnight validation and metadata completeness are covered.
API responses identify the loaded endpoint/domain revision `2026-10-04.2`.

Native ProcessMaker 3.8 Dynaform installation, actual PHP version/extensions,
session cookie/save-path compatibility, workspace migration, private filesystem
permissions and production backup/restore must
be accepted on the destination host. Nothing in this change claims production
deployment or ISO certification. The fixture uses synthetic data and must never
be deployed as a production router.

Reproduce with `tools/run_meeting_minutes_fixture.ps1` and follow
`MEETING_MINUTES_DEPLOYMENT.md` for the native acceptance sequence.
