# EMCORE visitor log module

## Purpose

The visitor log is an arrival-only register for reception and security staff. One row represents one physical visit. It is not a customer master, appointment scheduler, access-control system, or ProcessMaker case.

The module records who arrived, why they came, who they met, when they entered, and when they left. Visitors still on site are always listed first.

## Components

| Component | File or identifier |
|---|---|
| Permission module | `visitor_log` |
| Canonical table | `emcore_visits` |
| Migration | `database/migrations/006_emcore_visitor_log.sql` |
| API | `emcore_api/emcore_visitor_log.php` |
| Panel | `panels/emcore_visitor_log_panel.html` |
| Audit entity | `visit` |

## Record lifecycle

```text
create/check in -> inside -> checkout -> completed
```

Status is derived from `exited_at`; it is never stored independently. A null exit means the visitor is inside. Checkout is an audited update operation and requires `visitor_log:update`.

There is no uniqueness constraint on visitor, host, date, or purpose. Repeated visits and people with the same name are valid.

## Data model

`emcore_visits` stores visitor and host name snapshots so historical rows remain understandable when names or ProcessMaker accounts change.

- `visitor_name` is required free text. A visit does not automatically create an `emcore_persons` record.
- `organization_name` is optional and intentionally remains a snapshot in version one.
- `host_usr_uid` optionally links the visit to an active ProcessMaker user.
- `host_name_snapshot` is always required. When `host_usr_uid` is present, the API derives the snapshot from `USERS`; it does not trust the browser-supplied name.
- `visit_date_fa` is the authoritative Jalali input shown to operators.
- `visit_date_en` is derived server-side with `shamsi_slash_to_gregorian_date()`.
- `entered_at` and `exited_at` are canonical datetimes used for ordering and duration calculations.
- `created_by_usr_uid` and `updated_by_usr_uid` come from the authenticated ProcessMaker session.
- `deleted_at` implements soft deletion.

The table deliberately has no foreign key to `USERS`. ProcessMaker owns that identity table, and historical visit rows must survive user deactivation or platform-side changes.

## API contract

All requests are same-origin `POST` requests and use the ProcessMaker session.

| Action | Capability | Behavior |
|---|---|---|
| `lookups` | read | Active ProcessMaker hosts, CSRF token, and permissions |
| `list` | read | Filtered/paginated visit ledger and global summary |
| `get` | read | One complete active visit |
| `create` | create | Register an arrival, optionally a corrected historical completed visit |
| `update` | update | Correct visitor, host, entry, exit, purpose, or notes |
| `checkout` | update | Set `exited_at` to database `NOW()` once |
| `delete` | delete | Soft-delete one visit |

Write actions require the session-bound `X-CSRF-Token`. The API validates field lengths, user UID shape, active linked hosts, Jalali date shape/conversion, 24-hour time values, and exit-after-entry ordering.

List filters are `search`, `status`, `host_usr_uid`, `date_from_fa`, `date_to_fa`, `page`, and `page_size`. Allowed status values are `inside` and `completed`.

## Panel behavior

The panel is a Persian RTL reception ledger. It:

- defaults new records to the browser's current Persian date and local time;
- keeps visitors without an exit at the top;
- shows total, today, currently-inside, and completed counts;
- filters by text, host, date range, and presence status;
- lists active ProcessMaker users as hosts and allows a manual-host fallback;
- provides one-click checkout only to users with update permission;
- renders database values with jQuery text nodes rather than HTML interpolation; and
- hides mutation controls that the effective CRUD map does not permit.

The API remains the security boundary. Hidden controls are not authorization.

## Audit behavior

Create, edit, checkout, and soft-delete run inside transactions with their audit writes. Checkout is recorded as audit action `update` with metadata `{"operation":"checkout"}` because the shared audit action allowlist intentionally remains small.

## Intentional exclusions

Version one does not include appointments, notifications, badges, identity-document numbers, vehicle plates, visitor photos, automatic person creation, host approval, or physical access decisions. These can be added later without changing the visit lifecycle.