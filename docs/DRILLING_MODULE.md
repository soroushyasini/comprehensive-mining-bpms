# EMCORE daily drilling module

## Purpose

The daily drilling module replaces the classic ProcessMaker JSON/Dynaform CRUD screen with an EMCORE API and same-origin Panel WebControl. ProcessMaker remains the session identity provider; EMCORE owns the operational data, permissions, validation, and audit history.

The legacy source reviewed for the migration contains 2,035 authoritative rows. Records are never deduplicated merely because they share a mine, borehole, date, and shift.

## Domain hierarchy

```text
emcore_mines
└── emcore_boreholes
    └── emcore_drilling_reports
        ├── emcore_drilling_report_crew
        └── emcore_drilling_report_checklist

emcore_drilling_rigs ──────────┘
emcore_drilling_checklist_items ┘
```

- Each legacy “project” maps to an `emcore_mines` record. Historical contractor/personnel-related projects remain explicit records and carry `relationship_type` metadata.
- A borehole belongs to one mine.
- Rigs are independent assets and can move between mines.
- A report references one borehole and one rig.
- Crew entries can reference an EMCORE person or retain a temporary worker name only.

## Report identity

`emcore_drilling_reports.id` is the canonical identity. `legacy_id` is unique only for imported source traceability. `report_number` is the user-facing identifier.

The contextual combination below is indexed but intentionally not unique:

```text
borehole + report date + shift
```

The legacy data contains distinct records sharing that context. The panel may warn about another contextual report in a future enhancement, but the API must not reject it as a duplicate.

## Operation states

| State | Meaning | Validation for new reports |
|---|---|---|
| `drilling` | Drilling occurred without a reported stop | Stop cause/duration cleared |
| `partially_stopped` | Drilling occurred with interruption | Cause required; duration greater than 0 and at most 12 hours |
| `no_drilling` | No drilling occurred during the report | Cause required; start/end depth equal; duration stored as `NULL` |

Legacy `stop_time = 99` maps to `no_drilling`. The sentinel value `99` is never stored as a duration in the new model.

## Fixed checklist

The user interface retains the familiar 14 checkboxes. Labels are master data in `emcore_drilling_checklist_items`; each report stores stable item keys and boolean responses rather than comma-separated Persian labels.

The importer accepts both known legacy encodings:

- numeric IDs such as `1,2,3,4,5,6,7`; and
- older full Persian labels, including the historical duplicated number 13 typo.

## Crew behavior

Each selected or entered worker becomes one `emcore_drilling_report_crew` row:

- `person_id` is populated only for an explicitly selected active EMCORE person;
- `worker_name_snapshot` is always retained;
- `worker_type = registered` when a person is selected;
- `worker_type = temporary` for pay-day, contract, or unregistered workers; and
- `role_key` records the role for that report rather than treating roles as separate person identities.

The legacy importer does not automatically match names to `emcore_persons`, because Persian spelling variants make an automatic identity merge unsafe. It imports name snapshots as temporary and leaves later reviewed matching possible.

## API contract

Endpoint: `/emcore_api/emcore_drilling_reports.php`

Permission module: `drilling_daily_reports`

| Action | Capability | Purpose |
|---|---|---|
| `lookups` | read | Mines, boreholes, rigs, active persons, checklist items, CSRF, capabilities |
| `list` | read | Paginated and filtered report list |
| `get` | read | Report with crew and checklist children |
| `create` | create | Transactional report/crew/checklist creation and audit |
| `update` | update | Locked transactional replacement and before/after audit |
| `delete` | delete | Soft-delete report and retain children/history |

All actions use POST for compatibility with the existing EMCORE panels. Mutations require the session CSRF token in `X-CSRF-Token`.

List filters:

- `mine_id`
- `borehole_id`
- `rig_id`
- `shift`
- `date_from_fa`
- `date_to_fa`
- `search`
- `page`
- `page_size` (maximum 200)

## Legacy source mapping

| Legacy source | EMCORE destination |
|---|---|
| `emidco_db_projects` | Reviewed `emcore_mines` records/aliases created or normalized by migration `005` |
| `emidco_db_gamaneh` | `emcore_boreholes` |
| `dastgah_name` | `emcore_drilling_rigs.serial_number` |
| `prc_db_gozaresh_ruzane_copy2.id` | `legacy_id` and staging `source_legacy_id` |
| Project values `1`–`6` | Reviewed map: `راه چمن`, `تپه سیاه`, `میامی`, `زبرکوه`, `تنگل`, `کلاته برق` |
| Personnel text columns | Crew child rows with temporary name snapshots |
| Checklist strings | Stable checklist response rows |
| `stop_time = 99` | `operation_state = no_drilling`, duration `NULL` |
| Original row | JSON in staging and `legacy_source_data` |

The import is deliberately two-layered:

```text
source CSV
  └── emcore_drilling_legacy_import_rows (every parseable source ID)
       ├── imported → canonical report and child records
       └── needs_review → preserved until missing mapping/key is corrected
```

## Import tools

`tools/import_legacy_drilling_masters.php` imports the six project mappings and all 170 supplied borehole masters. It is dry-run by default and requires an active, permitted ProcessMaker actor for `--commit`.

`tools/import_legacy_drilling.php` imports the 2,035 report source. It is also dry-run by default, is idempotent by `legacy_id`, writes one audit entry per canonical report, and never selects a “winner” among same-context records.

See `DRILLING_DEPLOYMENT.md` for exact command order, acceptance targets, permission profiles, and rollback.

## Known migration exceptions

The reviewed source contains records with missing borehole, shift, rig, or report date. Those rows remain in staging with `needs_review` rather than receiving fabricated identities.

Two report borehole codes were not present in the original 170-row master:

- `تپه سیاه / HBH11B`
- `کلاته برق / KB-BHH07`

The report importer can create these reviewed codes with `--create-boreholes` while preserving their source spelling.

Historical depth/corebox/consumable values are preserved. The importer does not retroactively recalculate or “correct” them. New API-created reports calculate drilling amount from start/end depth and enforce the new state rules.

## Security and audit invariants

- Identity comes only from the active ProcessMaker session.
- The API is the permission boundary; hidden buttons are not authorization.
- Every mutation requires CSRF.
- Create/update/delete and their audit record share one database transaction.
- Imported reports retain their source row and import actor.
- No passwords, ProcessMaker hashes, or production exports belong in Git.
- Database values are rendered with jQuery DOM `.text()`/option construction rather than unsafe HTML concatenation.

## Definition of done for deployment

- Migrations `003`, `004`, and `005` apply successfully.
- PHP lint passes on the ProcessMaker-compatible server runtime.
- All six projects map and all 170 master boreholes are accounted for: `زبرکوه`; `تنگل` via alias of `تنگل نورا`; `تپه سیاه` via alias of merged `تپه سیاه شمالی`; and explicit historical records for `راه چمن`, `میامی`, and `کلاته برق`.
- Report dry run reads exactly 2,035 rows with zero CSV parse errors.
- Every parseable source ID exists in staging after commit.
- Canonical imports contain no repeated non-null `legacy_id`.
- Same-context reports remain separate records.
- Permission profiles and failure paths pass.
- `no_drilling`, partial stop, temporary crew, and second same-context report tests pass.
- Audit entries are present and the classic form remains available read-only through acceptance.
