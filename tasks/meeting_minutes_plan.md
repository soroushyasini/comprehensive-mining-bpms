# Meeting minutes implementation

Implement the approved 2026-10-04 Persian plan: company/year transactional numbering,
legacy archival numbers, USERS/external participants, independent metadata/scan states,
private versioned files, module-wide permissions, and reusable UI components only in
the new panel. Existing panels and unrelated dirty files must remain unchanged.

Delivery order: database/domain → secured API/storage → components/panel → tests/docs.
See docs/MEETING_MINUTES_MODULE.md for the final executable contract and
docs/MEETING_MINUTES_DEPLOYMENT.md for installation and rollback.
