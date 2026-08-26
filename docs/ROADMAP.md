# EMCORE security and feature roadmap

Last updated: 2026-08-26

## Objective

Move EMCORE from a working ProcessMaker CRUD prototype to a secure, maintainable internal application, then build the remaining business modules on that foundation.

Authorization is assigned per ProcessMaker user and EMCORE module, with independent `create`, `read`, `update` (edit), and `delete` capabilities. ProcessMaker remains the identity provider. EMCORE stores only `USR_UID` and permission assignments; it never copies passwords or user profile data.

## Phase 0 — immediate credential response

Status: **operator action required**

- Rotate the MySQL root password committed to Git.
- Create a dedicated least-privilege MySQL account for EMCORE.
- Configure the API with environment variables or an untracked local config.
- If the repository left the trusted network, coordinate removal of the old credential from Git history.
- Review access to ignored `dataset/` credential exports.

Done when no production credential exists in tracked files/history and EMCORE uses a restricted account.

## Phase 1 — authentication and module authorization

Status: **deployed and successfully tested on 2026-08-09**

- Shared bootstrap: DB configuration, safe JSON errors, ProcessMaker-session authentication, CSRF, permissions.
- Add `emcore_modules` and `emcore_user_permissions`.
- Seed the built-in administrator to prevent initial lockout.
- Add the user-by-module CRUD matrix panel and API.
- Enforce every capability server-side and reject inactive users.

Done when anonymous requests return 401, unauthorized requests return 403, writes require CSRF, and permission changes require `authorization:update`.

## Phase 2 — API correctness and validation

Status: **implementation started**

- Validate IDs, required strings, enums, lengths, identifiers, booleans, and Jalali dates server-side.
- Derive Gregorian expiry dates from their Jalali source server-side.
- Use consistent HTTP status codes and return 404 for missing update/delete targets.
- Define soft-delete dependencies and transaction boundaries.

## Phase 3 — browser and deployment hardening

Status: **partially started**

- Escape values before HTML/JavaScript insertion.
- Use same-origin configurable API URLs.
- Add a ProcessMaker-compatible Content Security Policy.
- Rate-limit sensitive operations where hosting permits it.

## Phase 4 — engineering foundation

Status: **implementation started — append-only audit logging added**

- Versioned migrations and deployment/rollback checklists.
- Tests for authentication, permissions, validation, and soft deletes.
- Disposable test DB, CI, and PHP linting.
- Complete README and operations documentation.
- Append-only audit log with actor `USR_UID`, action, entity, ID, timestamp, and before/after snapshots.

## Phase 5 — domain modules

Status: **partially implemented**

Current module note: secured company, person, mine, mine-technical-manager, daily-drilling, and visitor-log modules are implemented. Mines support owned, contractor, and personnel-related scopes plus merge lineage. The drilling production migration and API deployment are verified; panel acceptance remains. The arrival-only visitor log has migration, API, panel, authorization, audit, and deployment documentation ready for rollout.

Suggested order: companies, persons, company-person relations, mines, mine technical managers, memberships, internet services, attachments, tokens/email accounts after secrets-policy approval, then unified expiry alerts.

A module is complete only with a migration, authorization registration, validated API, safe panel, tests, audit logging, and operator documentation.

Drilling deployment is governed by `docs/DRILLING_DEPLOYMENT.md`. The source contains 2,035 authoritative report rows; imports preserve every legacy ID and never deduplicate same-context reports.

Visitor-log deployment is governed by `docs/VISITOR_LOG_DEPLOYMENT.md`; its domain and API contract are documented in `docs/VISITOR_LOG_MODULE.md`.

## Authorization design

### Identity

- Canonical identity: ProcessMaker `USERS.USR_UID` (`CHAR(32)`).
- User display data is queried live from `USERS`; password columns are never selected.
- Only `USR_STATUS = 'ACTIVE'` users are eligible.

### Request resolution

For `companies/update`, restore the ProcessMaker PHP session, read `$_SESSION['USER_LOGGED']`, confirm the user is active, look up `(usr_uid, module_key)`, require `can_update = 1`, and validate CSRF. Never trust a browser-supplied user ID, username, or role.

### Matrix behavior

The panel shows active users as rows and enabled modules as columns. Each cell has C/R/U/D checkboxes. Saving replaces that user's four capabilities for the module atomically. The API prevents removal of the final active authorization administrator.

## Phase 1 deployment

1. Rotate the exposed password and create the restricted DB user.
2. Set `EMCORE_DB_DSN`, `EMCORE_DB_USER`, and `EMCORE_DB_PASSWORD`, or create ignored `emcore_api/emcore_config.php`.
3. Verify the administrator `USR_UID`, then apply `database/migrations/001_emcore_authorization.sql`.
4. Deploy under the same origin/session configuration as ProcessMaker.
5. Configure permissions as the seeded administrator.
6. Deploy protected business APIs.
7. Test anonymous, read-only, editor, CRUD, inactive-user, and CSRF-failure cases.

## Deployment checks

- Confirm ProcessMaker 3.8's PHP session cookie/save path. This implementation uses `$_SESSION['USER_LOGGED']`; a bootstrap adapter is needed if standalone WebContent has separate PHP session configuration.
- Confirm `USERS` and EMCORE tables share `wf_pishro`; otherwise add a separate read-only identity connection.
- Direct user grants are initially authoritative; role/group grants can be designed later.
