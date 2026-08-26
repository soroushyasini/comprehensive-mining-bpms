# EMCORE developer and module authoring guide

**Project:** Comprehensive Mining BPMS
**Runtime:** ProcessMaker 3.8, PHP, MySQL, HTML/CSS, jQuery Panel WebControls
**Audience:** EMCORE developers, ProcessMaker administrators, reviewers, and coding agents/LLMs
**Document role:** Canonical implementation and integration guide
**Last reviewed:** 2026-08-26

## 1. Purpose

EMCORE is an operational information system for a group of mining companies. ProcessMaker provides the authenticated user environment and Dynaform host. EMCORE adds purpose-built modules for structured company, personnel, mine, contract, credential, document, and expiry data.

The system is intentionally incremental. A module normally consists of:

1. one or more MySQL tables prefixed with `emcore_`;
2. a PHP JSON endpoint under `emcore_api/`;
3. a Persian, right-to-left Panel WebControl embedded in a ProcessMaker Dynaform;
4. per-user CRUD authorization;
5. CSRF protection for writes;
6. transactional audit logging; and
7. migration, deployment, and verification documentation.

This guide explains the contract a new module must follow. A developer or LLM should be able to implement a module without inventing a parallel architecture.

## 2. Source-of-truth order

When documents disagree, use this order:

1. Executable code and versioned files under `database/migrations/`.
2. This guide.
3. `docs/ROADMAP.md` for status and future work.
4. `db_schema.md` and `EMCORE_dev_reference_addendum.md` for domain background and older schema detail.
5. `Panel WebControl Standard Template.md`, `fa_en_date_explainer.md`, and historical notes for supplementary context.

Never infer production credentials, deployed paths, or completed features from historical notes.

## 3. System context

### 3.1 ProcessMaker responsibilities

ProcessMaker 3.8 supplies:

- login and session management;
- the canonical `USERS` identity table;
- `USR_UID`, exposed in the PHP session as `$_SESSION['USER_LOGGED']`;
- Dynaforms and Panel WebControls;
- process tasks, routing, and triggers where workflow is actually required; and
- the browser origin/session in which EMCORE panels run.

EMCORE does **not** copy or validate ProcessMaker passwords. Password hashes must never be selected, returned, logged, or stored in EMCORE tables.

### 3.2 The ad-hoc EMCORE layer

Several operational modules do not need a routed ProcessMaker case for every CRUD action. They use an ad-hoc layer:

```text
Authenticated ProcessMaker user
        │
        ▼
Dynaform Panel WebControl (RTL HTML + jQuery)
        │  same-origin POST, ProcessMaker session cookie
        ▼
/emcore_api/emcore_<module>.php
        │  authentication, permission, CSRF, validation, transaction, audit
        ▼
MySQL: USERS + emcore_* tables
```

This does not bypass ProcessMaker identity. It reuses the authenticated ProcessMaker PHP session and adds EMCORE-specific authorization.

Use a normal ProcessMaker process/trigger instead when an operation requires approvals, assignments, SLAs, escalation, or routed human work. Use the ad-hoc API pattern for immediate module administration and lookup screens.

### 3.3 Deployment assumption

Panels and APIs must be served from the same origin and must share compatible PHP session configuration with ProcessMaker. Otherwise the browser may send a cookie that the API cannot restore, and `$_SESSION['USER_LOGGED']` will be absent.

The currently expected production-style paths are:

```text
/emcore_api/_bootstrap.php
/emcore_api/_audit.php
/emcore_api/_module_permissions.php
/emcore_api/emcore_<module>.php
```

Panel URLs should be root-relative, for example:

```javascript
var API_URL = '/emcore_api/emcore_mines.php';
```

Do not embed a hostname, HTTP scheme, fixed CSRF token, user UID, or secret in a panel.

## 4. Repository map

```text
database/migrations/        Versioned, ordered MySQL changes
docs/DEVELOPER_GUIDE.md     This canonical guide
docs/ROADMAP.md             Delivery status and future phases
emcore_api/_bootstrap.php   Config, PDO, session identity, JSON/errors, CSRF, validation
emcore_api/_audit.php       Append-only transactional audit writer
emcore_api/_module_permissions.php
                            Current user's CRUD capability map
emcore_api/emcore_*.php     Module and administration endpoints
panels/*.html               Deployable ProcessMaker Panel WebControls
panels/README.md            Panel-to-endpoint mapping and deployment notes
db_schema.md                Domain/schema reference
EMCORE_dev_reference_addendum.md
                            Mines, technical managers, and expiry additions
```

`dataset/` is ignored and may contain sensitive source material. It is not application configuration and must not be committed or exposed to panels/APIs.

## 5. Implemented platform capabilities

### 5.1 Shared API bootstrap

Every protected endpoint loads the shared stack, normally through:

```php
require_once __DIR__ . '/_module_permissions.php';
```

That file loads audit and bootstrap dependencies. The shared stack provides:

- environment/local configuration resolution;
- PDO with exceptions, associative fetches, and native prepared statements;
- JSON response headers and `no-store` caching;
- safe exception handling;
- active ProcessMaker user resolution;
- per-module capability enforcement;
- session-bound CSRF tokens;
- POST/action validation;
- common ID, string, and boolean validation;
- audit logging; and
- effective capability maps for panels.

Do not duplicate database credentials, session logic, or JSON error handling in a module endpoint.

### 5.2 Current modules

| Module key | Canonical table/API | Panel | Status |
|---|---|---|---|
| `authorization` | `emcore_user_permissions`; `emcore_authorization_api.php` | `emcore_authorization_panel.html` | Implemented and tested |
| `audit_log` | `emcore_audit_log`; `emcore_audit_log_api.php` | Read API; dedicated panel pending | Implemented and tested |
| `companies` | `emcore_companies`; `emcore_companies_api.php` | `panels/emcore_companies_panel.html` | Implemented |
| `persons` | `emcore_persons`; `emcore_persons_api.php` | `panels/emcore_persons_panel.html` | Implemented |
| `mines` | `emcore_mines`; `emcore_mines.php` | `panels/emcore_mines_panel.html` | Implemented; supports owned, contractor, and personnel-related mines plus soft merge lineage |
| `mine_technical_managers` | `emcore_mine_technical_managers`; `emcore_mine_technical_managers.php` | `panels/emcore_mine_technical_managers_panel.html` | Implemented |
| `drilling_daily_reports` | `emcore_drilling_reports` plus borehole/rig/crew/checklist tables; `emcore_drilling_reports.php` | `panels/emcore_drilling_reports_panel.html` | Deployed; production import verified, panel acceptance pending |
| `visitor_log` | `emcore_visits`; `emcore_visitor_log.php` | `panels/emcore_visitor_log_panel.html` | Implemented; deployment pending |
| `company_persons` | `emcore_company_persons` | Pending | Domain/schema defined |
| `memberships` | `emcore_memberships` | Pending | Domain/schema defined |
| `tokens` | `emcore_tokens` | Pending | Security-sensitive; panel/API pending |
| `email_accounts` | `emcore_email_accounts` | Pending | Security-sensitive; panel/API pending |
| `internet_services` | `emcore_internet_services` | Pending | Domain/schema defined |
| `attachments` | `emcore_attachments` | Pending | File security design required |

The database and migration files are authoritative if status changes after this document's review date.

## 6. Identity and authorization

### 6.1 Identity

The canonical identity is `USERS.USR_UID`, a 32-character ProcessMaker UID. The request path is:

1. Start or restore the PHP session.
2. Read `$_SESSION['USER_LOGGED']`.
3. Validate the UID shape.
4. Select only non-sensitive display columns from `USERS`.
5. Require `USR_STATUS = 'ACTIVE'`.

Never accept identity from POST data, a query string, a custom header, a hidden form field, or a role name supplied by the browser.

### 6.2 Permission model

`emcore_user_permissions` stores one row per `(usr_uid, module_key)` with:

- `can_create`
- `can_read`
- `can_update`
- `can_delete`
- `granted_by`
- timestamps

The API is the enforcement boundary. UI visibility is not authorization.

Map actions explicitly:

```php
$action = emcore_action(['list', 'get', 'create', 'update', 'delete']);
$capability = [
    'list' => 'read',
    'get' => 'read',
    'create' => 'create',
    'update' => 'update',
    'delete' => 'delete',
][$action];
emcore_require_permission('module_key', $capability);
```

Lookup actions such as `companies` or `mines` normally require the module's `read` capability.

### 6.3 Permission administration invariants

- Only `authorization:update` may modify grants.
- The target must be an active ProcessMaker user.
- The module must be active in `emcore_modules`.
- Removing all four capabilities removes the permission row.
- The final active authorization administrator cannot be removed.
- Permission changes are audited with before/after data and the granting actor.

## 7. CSRF contract

Read actions return a session-bound token:

```json
{
  "success": true,
  "data": [],
  "csrf_token": "session-specific-token",
  "permissions": {
    "can_create": 1,
    "can_read": 1,
    "can_update": 0,
    "can_delete": 0
  }
}
```

Every create, update, delete, and permission write must call:

```php
emcore_require_csrf();
```

Panels capture the token from a successful API response and send it on later requests:

```javascript
var EMCORE_CSRF_TOKEN = '';

jQuery(document).ajaxSend(function (event, xhr) {
  if (EMCORE_CSRF_TOKEN) {
    xhr.setRequestHeader('X-CSRF-Token', EMCORE_CSRF_TOKEN);
  }
});

jQuery(document).ajaxSuccess(function (event, xhr, settings, response) {
  if (response && response.csrf_token) {
    EMCORE_CSRF_TOKEN = response.csrf_token;
  }
});
```

The API also accepts a POST `csrf_token` for clients that cannot set headers. Never hardcode or reuse a token from another session.

## 8. API protocol

### 8.1 Transport

The current endpoints use POST-based RPC actions rather than a REST resource router. Keep this convention unless the entire API is deliberately versioned and migrated.

```text
POST /emcore_api/emcore_companies_api.php
Content-Type: application/x-www-form-urlencoded

action=list
```

### 8.2 Standard actions

| Action | Capability | CSRF | Expected result |
|---|---|---|---|
| `list` | read | No | Collection, token, permissions |
| `get` | read | No | One active record |
| `create` | create | Yes | New integer ID, HTTP 201 |
| `update` | update | Yes | Success or 404 |
| `delete` | delete | Yes | Soft-delete success or 404/409 |

Additional lookup actions must be documented beside the module and should return the smallest necessary dataset.

### 8.3 JSON envelope and HTTP status codes

Success:

```json
{"success": true, "data": {}}
```

Failure:

```json
{
  "success": false,
  "error": "Safe Persian message",
  "details": {"field_name": "machine_readable_reason"}
}
```

Use:

| Status | Meaning |
|---|---|
| 200 | Successful read/update/delete |
| 201 | Successful create |
| 400 | Unknown action or malformed request |
| 401 | No valid active ProcessMaker session |
| 403 | Missing capability or invalid CSRF token |
| 404 | Active target record does not exist |
| 405 | Non-POST method |
| 409 | Business-state conflict, such as a protected dependency |
| 422 | Field validation failure |
| 500 | Unexpected internal error; details logged server-side only |

Never return PDO exception text, SQL, credentials, stack traces, or filesystem paths to the browser.

## 9. Database conventions

### 9.1 Naming and storage

- Tables: `emcore_` prefix and plural snake_case.
- Columns: English snake_case.
- Primary keys: unsigned integer `id` unless a platform identity requires otherwise.
- Foreign keys: singular entity plus `_id`.
- Booleans: `is_` or `can_` prefix using `TINYINT(1)`.
- Text: `utf8mb4` and `utf8mb4_unicode_ci` for Persian/English data.
- Lifecycle: `created_at`, `updated_at`, and `deleted_at` where soft deletion applies.
- Money/quantity: `DECIMAL`, not floating point, when arithmetic precision matters.

### 9.2 Soft deletion

Business records are not hard-deleted:

```sql
UPDATE emcore_example
SET deleted_at = NOW(), updated_at = NOW()
WHERE id = :id AND deleted_at IS NULL;
```

Every active list/get/join must intentionally apply `deleted_at IS NULL` to the relevant tables.

Before implementing delete, choose and document a dependency policy:

- block deletion while active/history children exist;
- deactivate children in the same transaction;
- retain children but hide the parent; or
- explicitly cascade soft deletion.

Do not silently orphan records. The mines API blocks deletion when technical-manager, borehole, or merge-lineage dependencies exist. Duplicate mines are merged by reassigning foreign keys, setting `merged_into_id`, and soft-retiring the duplicate; never hard-delete a referenced mine.

### 9.3 Dates

Persian operational input uses Jalali strings:

```text
YYYY/MM/DD
```

Rules:

- `*_fa` is the source/display value (`VARCHAR(10)`).
- Expiry/deadline fields also have `*_en DATE`.
- Gregorian expiry values are derived on the server using `shamsi_slash_to_gregorian_date()`.
- Panels may display derived Gregorian values but must not edit or submit them as authoritative input.
- Sorting, `DATEDIFF`, alerts, and expiry status use only Gregorian `DATE` columns.
- Start/issue dates need no Gregorian partner unless calculations require one.

Example:

```sql
license_validity_fa = :license_validity_fa,
license_validity_en = shamsi_slash_to_gregorian_date(:license_validity_fa_derived)
```

Use distinct PDO placeholders when native prepares are enabled.

### 9.4 Migrations

- Add ordered SQL files under `database/migrations/`.
- Make reruns safe where practical with `IF NOT EXISTS`, `INSERT IGNORE`, or idempotent upserts.
- Never edit a migration already deployed to production to change history. Add the next migration.
- State prerequisites and irreversible operations in comments.
- Review bootstrap grants carefully so a new authorization system does not lock out all administrators.

## 10. Audit logging and transactions

All writes to implemented business modules and permissions must create an `emcore_audit_log` record containing:

- request ID;
- actor `USR_UID`;
- module key and action;
- entity type and ID;
- before and after JSON snapshots;
- optional metadata;
- IP address and user agent; and
- timestamp.

The business write and audit insert must share the same transaction:

```php
$db->beginTransaction();
try {
    // SELECT current row FOR UPDATE
    // perform INSERT/UPDATE/soft delete
    // fetch the resulting row
    emcore_audit('module_key', 'update', 'entity_type', $id, $before, $after);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
```

Do not audit plaintext secrets. For a security-sensitive module, redact or omit protected values from snapshots and record only safe metadata.

## 11. Validation requirements

Validate on the server even if the panel validates first.

At minimum:

- require positive integer IDs;
- distinguish required values from optional `NULL`;
- enforce schema-aware maximum lengths;
- allowlist enum/status values;
- parse booleans strictly;
- validate Jalali shape and derive Gregorian dates server-side;
- validate decimal precision without casting arbitrary text to float;
- confirm referenced parents exist, are active, and are not soft-deleted;
- check affected/target rows and return 404 rather than false success; and
- use prepared statements for all values.

Avoid broad `SELECT *` in list responses. A protected `get` or audit snapshot may use it when the complete record is intentional.

## 12. Panel WebControl contract

### 12.1 Required behavior

A deployable panel must:

- use `<html dir="rtl" lang="fa">` and UTF-8;
- use a root-relative, same-origin API URL;
- wait for ProcessMaker/jQuery initialization when required;
- load its initial collection through `list`;
- capture and send the EMCORE CSRF token;
- read the returned capability map;
- hide unauthorized controls before the user can interact;
- handle non-2xx responses and display safe Persian errors;
- escape all database-backed text before generated HTML insertion;
- build `<option>` elements using `.text()` rather than string concatenation;
- keep derived fields read-only;
- prevent duplicate submissions while a write is pending; and
- reload the collection after a successful write.

### 12.2 Capability-aware controls

Panels should default write controls to hidden/disabled until permissions arrive:

```javascript
var EMCORE_PERMISSIONS = {
  can_create: 0,
  can_read: 0,
  can_update: 0,
  can_delete: 0
};

function applyEmcorePermissions() {
  jQuery('.action-create').toggle(Number(EMCORE_PERMISSIONS.can_create) === 1);
  jQuery('.action-update').toggle(Number(EMCORE_PERMISSIONS.can_update) === 1);
  jQuery('.action-delete').toggle(Number(EMCORE_PERMISSIONS.can_delete) === 1);
}
```

The API must still reject unauthorized requests even when controls are hidden.

### 12.3 Output safety

Prefer DOM creation and `.text()`:

```javascript
var option = jQuery('<option>').val(record.id).text(record.name_fa);
select.append(option);
```

If an existing panel builds HTML strings, escape every untrusted value for its output context. Inline JavaScript handlers are legacy-compatible but fragile; new panels should prefer event listeners with record IDs stored in `data-*` attributes.

## 13. Creating a module: required sequence

### Step 1 — define the module contract

Before coding, document:

- module key and Persian/English display names;
- table(s), ownership, and relationships;
- list/detail fields;
- searchable/filterable fields;
- required/optional values and enums;
- date and expiry behavior;
- dependency behavior on soft delete;
- sensitive fields and audit-redaction rules;
- attachment categories, if any; and
- whether the operation is ad-hoc CRUD or needs routed ProcessMaker workflow.

### Step 2 — add a migration

Create the next ordered migration. It should create/alter tables and register the module:

```sql
INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('example_records', 'نمونه‌ها', 'Example records', 120)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;
```

Do not automatically grant broad access to every ProcessMaker user. Use an explicit, reviewed bootstrap grant if initial administration requires it.

### Step 3 — implement the endpoint

Create `emcore_api/emcore_example_records.php` using this shape:

```php
<?php

require_once __DIR__ . '/_module_permissions.php';

$action = emcore_action(['list', 'get', 'create', 'update', 'delete']);
$capability = [
    'list' => 'read',
    'get' => 'read',
    'create' => 'create',
    'update' => 'update',
    'delete' => 'delete',
][$action];
emcore_require_permission('example_records', $capability);
$db = emcore_db();

if ($action === 'list') {
    $rows = $db->query(
        'SELECT id, label, is_active
         FROM emcore_example_records
         WHERE deleted_at IS NULL
         ORDER BY label'
    )->fetchAll();

    emcore_json([
        'success' => true,
        'data' => $rows,
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions('example_records'),
    ]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    // Fetch active row or throw EmcoreHttpException(404, ...).
}

emcore_require_csrf();

// Validate all write fields before opening the transaction.
// Lock the target for update/delete, write, audit, then commit.
```

Keep the action branches easy to audit. Extract shared helpers only when they preserve module-specific validation and lifecycle rules.

### Step 4 — implement the panel

Copy the closest panel under `panels/`, then change:

- endpoint URL;
- form fields and labels;
- table columns and filters;
- module-specific client validation;
- selectors used for capability visibility; and
- safe rendering functions.

Do not copy old fixed-hostname or non-CSRF examples from historical notes.

### Step 5 — grant and verify permissions

Using the authorization panel, test at least:

| Test user | Expected behavior |
|---|---|
| No module row | API returns 403 |
| Read only | List/get work; write controls hidden; direct writes return 403 |
| Create + read | Create works; edit/delete remain unavailable |
| Read + update | Existing records can be edited; create/delete unavailable |
| Full CRUD | All supported actions work |
| Inactive ProcessMaker user | API returns 401 even if permission rows remain |

### Step 6 — test failure behavior

Test:

- missing/invalid CSRF;
- missing required fields;
- invalid ID and nonexistent record;
- invalid enum/date/decimal;
- soft-delete dependency conflict;
- duplicate/unique constraint errors;
- expired browser session;
- Persian text and Unicode JSON;
- stored-XSS payloads displayed as text;
- audit before/after snapshots; and
- rollback when audit insertion fails.

### Step 7 — document and deploy

Update:

- the current-modules table in this guide;
- `docs/ROADMAP.md` status;
- `panels/README.md` mapping; and
- deployment notes for new migrations/configuration.

Deploy migration first, shared helpers second, endpoint third, and panel last. Force-refresh the Dynaform/WebControl after replacement.

## 14. Configuration and secrets

Supported configuration:

- `EMCORE_DB_DSN`
- `EMCORE_DB_USER`
- `EMCORE_DB_PASSWORD`
- optional `EMCORE_SESSION_NAME`
- ignored local `emcore_api/emcore_config.php`

Use `emcore_api/emcore_config.example.php` only as a template. Production should use a least-privilege database user, not MySQL `root`.

Never commit:

- database passwords;
- ProcessMaker password hashes;
- fixed CSRF tokens or session IDs;
- API keys, token PINs, or email passwords;
- production exports containing personal data; or
- local `dataset/` source files.

If a credential was ever committed, removing it from the current file is not sufficient. Rotate it and coordinate Git-history cleanup if the repository left the trusted environment.

## 15. Deployment and rollback checklist

### Before deployment

- Back up affected tables.
- Confirm the target ProcessMaker workspace/database.
- Confirm the active administrator `USR_UID` used by any bootstrap grant.
- Review migration SQL and dependencies.
- Confirm environment variables and restricted DB privileges.
- Run PHP lint/tests in an environment with the target PHP version.
- Verify the panel endpoint path and same-origin behavior.

### Deployment order

1. Database migration.
2. Shared helper changes.
3. API endpoint.
4. Panel WebContent/Dynaform.
5. Permission grants.
6. Smoke tests and audit verification.

### Rollback

- Restore the prior panel first if users are blocked.
- Restore the prior compatible API version.
- Prefer forward-fix migrations; do not drop newly collected data casually.
- If a schema rollback is unavoidable, use a reviewed script and backup.
- Preserve audit records unless a documented legal/retention requirement says otherwise.

## 16. Troubleshooting

### 401 — login required or active user not found

Check:

- the request includes the ProcessMaker session cookie;
- API and ProcessMaker share session name/save path;
- `$_SESSION['USER_LOGGED']` is present;
- the UID exists in `USERS`; and
- `USR_STATUS = 'ACTIVE'`.

### 403 — insufficient access

If the error is `دسترسی کافی ندارید`, check `(usr_uid, module_key)` and the mapped `can_*` column.

If the error is `توکن امنیتی نامعتبر است`, confirm:

- `list` completed before a write;
- the panel captured `csrf_token`;
- the write sent `X-CSRF-Token` or POST `csrf_token`;
- the PHP session did not change between requests; and
- the browser is not running a stale cached panel.

Do not disable CSRF to fix a panel.

### 404 on update/delete

Confirm the ID is positive and the record is not already soft-deleted.

### 409 on delete

The module's dependency rule blocked deletion. Inspect the safe error message and related active/history records.

### 500 internal error

Inspect server/PHP logs. Common causes include missing migrations, absent date-conversion function, DB privilege errors, incompatible session configuration, or schema drift. Do not expose raw exceptions to the browser.

### Jalali/Gregorian mismatch

Ensure the panel sends only the `_fa` source, and the API writes the `_en` value through `shamsi_slash_to_gregorian_date()` in the same statement/transaction.

## 17. Security-sensitive future modules

### Tokens and email/system credentials

The historical schema contains plaintext-policy notes. New implementation must not casually reproduce that design. Before building these modules:

- define whether the system stores secrets, hints, or vault references;
- prefer a secrets vault and reference IDs;
- restrict list/detail fields;
- redact audit snapshots;
- prevent secrets from browser tables, logs, exports, and search; and
- obtain an explicit security decision for any plaintext storage.

### Attachments

Before implementing uploads, define:

- allowed MIME types and extensions;
- maximum size;
- server-generated filenames;
- storage outside executable web paths;
- authorization on upload/download/delete;
- malware scanning where available;
- entity ownership checks;
- audit metadata without embedding file content; and
- retention/soft-delete behavior.

## 18. Guidance for coding agents and LLMs

An agent working on this repository must:

1. Read this guide, relevant migrations, the closest implemented API, and its panel before editing.
2. Inspect the working tree and preserve unrelated/user changes.
3. Treat executable code/migrations as newer than historical prose.
4. Reuse `_bootstrap.php`, `_audit.php`, and `_module_permissions.php`.
5. Never weaken authentication, permissions, CSRF, validation, audit, or output escaping to make a test pass.
6. Never invent production credentials, user IDs, schema columns, enum values, or ProcessMaker paths.
7. Ask for deployment-specific facts only when they cannot be derived safely.
8. Add migrations rather than silently assuming tables/columns exist.
9. Keep the panel and endpoint contract synchronized in the same change.
10. State tests that were run and clearly identify tests blocked by missing PHP/MySQL/ProcessMaker runtime.
11. Avoid modifying ignored datasets unless explicitly requested.
12. Update this guide when introducing a new architectural convention.

If a request conflicts with a security invariant, stop and explain the conflict rather than implementing an insecure shortcut.

## 19. Definition of done for a module

A module is not complete merely because its table or list screen works. It is complete when:

- schema changes are versioned;
- the module is registered for authorization;
- active identity and each CRUD capability are enforced server-side;
- writes require CSRF;
- validation matches the schema/domain;
- date derivation and dependency behavior are correct;
- writes and audits are transactional;
- list responses return token and capabilities;
- the RTL panel hides unauthorized actions and safely renders values;
- sensitive values are excluded/redacted;
- happy paths and failure paths are tested;
- deployment/rollback steps are documented; and
- roadmap/module documentation is updated.

## 20. Related references

- `docs/ROADMAP.md` — priorities and current delivery status.
- `database/migrations/001_emcore_authorization.sql` — module/user CRUD authorization.
- `database/migrations/002_emcore_audit_log.sql` — audit schema and initial access.
- `docs/DRILLING_MODULE.md` — drilling domain, API, checklist, crew, and legacy-mapping contract.
- `docs/DRILLING_DEPLOYMENT.md` — drilling migration, import, acceptance, and rollback runbook.
- `db_schema.md` — core business module reference.
- `EMCORE_dev_reference_addendum.md` — mines, technical managers, and unified expiry design.
- `fa_en_date_explainer.md` — date-conversion rationale.
- `panels/README.md` — deployed panel mapping and security integration.
