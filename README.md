# EMCORE — Comprehensive Mining BPMS

EMCORE is a ProcessMaker 3.8–integrated operational system for managing mining-company, personnel, mine, contract, document, credential, and expiry data.

ProcessMaker provides authentication, sessions, Dynaforms, and routed workflows. EMCORE adds secured ad-hoc PHP/MySQL modules and Persian RTL Panel WebControls for operational CRUD screens.

## Start here

- [Developer and module authoring guide](docs/DEVELOPER_GUIDE.md) — canonical architecture, security, API/panel contracts, and end-to-end module instructions.
- [Roadmap](docs/ROADMAP.md) — current implementation status and planned phases.
- [Drilling deployment runbook](docs/DRILLING_DEPLOYMENT.md) — migrations, legacy import, ProcessMaker panel installation, acceptance, and rollback.
- [Daily drilling module reference](docs/DRILLING_MODULE.md) — domain model, invariants, API contract, legacy mapping, and import behavior.
- [Database/domain reference](db_schema.md) — core business tables and module specifications.
- [Mines and expiry addendum](EMCORE_dev_reference_addendum.md) — mine-specific tables and expiry behavior.
- [Panel deployment guide](panels/README.md) — ProcessMaker WebControl files and endpoint mapping.

## Implemented foundation

- ProcessMaker session authentication using `USR_UID`
- Per-user, per-module CRUD authorization
- CSRF protection
- Shared PDO/configuration/error handling
- Transactional before/after audit logging
- Soft-delete and Jalali/Gregorian date conventions
- Secured company, person, mine, and mine-technical-manager modules
- Mine relationship scopes (owned, contractor, personnel-related) and auditable soft-merge lineage
- Daily drilling operations with boreholes, movable rigs, crew, checklist, lossless legacy staging, and secured reports

For setup, module development, security invariants, deployment order, troubleshooting, and the definition of done, read `docs/DEVELOPER_GUIDE.md` before making changes.
