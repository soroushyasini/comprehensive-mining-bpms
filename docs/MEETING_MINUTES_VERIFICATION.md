# Meeting minutes verification — 2026-10-04

## Executed

- PHP 8.2 lint: `_minutes_domain.php`, `_minutes_storage.php`, `emcore_meeting_minutes.php`.
- MySQL 8.4 / PHP 8.2 isolated integration: **123 checks passed**.
- Chrome/Chromium with jQuery 1.11.3, desktop 1440×1000 and mobile 390×844:
  **29 browser checks passed**.
- `check_meeting_minutes_release.js`: passed JS parsing, panel selector wiring,
  unique IDs, safe text rendering, same-origin assets and Jalali/leap boundaries.
- Read-only deployment preflight: all checks passed in the disposable fixture.
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

Native ProcessMaker 3.8 Dynaform installation, actual PHP version/extensions,
session cookie/save-path compatibility, workspace migration, private filesystem
permissions, real SQL date-conversion function and production backup/restore must
be accepted on the destination host. Nothing in this change claims production
deployment or ISO certification. The fixture uses synthetic data and must never
be deployed as a production router.

Reproduce with `tools/run_meeting_minutes_fixture.ps1` and follow
`MEETING_MINUTES_DEPLOYMENT.md` for the native acceptance sequence.
