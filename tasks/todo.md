# Procurement analytics tasks

- [x] Specify the legacy-to-new metric contract.
  - Acceptance: all five legacy chart meanings and the corrected common filter scope are documented.
  - Verify: inspect `docs/PROCUREMENT_ANALYTICS.md`.
  - Files: `docs/PROCUREMENT_ANALYTICS.md`, `tasks/plan.md`, `tasks/todo.md`.

- [x] Implement the read-only analytics API.
  - Acceptance: lookups and dashboard actions use normalized non-deleted data, module read permission, boundary validation, and parameterized filters.
  - Verify: static API checks and PHP lint on the deployment host.
  - Files: `emcore_api/emcore_procurement_analytics.php`, `tools/check_procurement_analytics_release.js`.

- [x] Implement the ProcessMaker analytics panel.
  - Acceptance: common filters, KPIs, all five legacy views, enhancements, quality disclosure, empty/error states, tables, drill-down, accessibility, and responsive layout are present.
  - Verify: JavaScript parse checks and manual browser acceptance at target widths.
  - Files: `panels/emcore_procurement_analytics_panel.html`, `panels/README.md`.

- [x] Verify, document deployment, commit, and push.
  - Acceptance: new and existing release checks pass; unrelated dirty files remain untouched; deployment file list and acceptance queries are documented.
  - Verify: `git diff`, `git status`, release commands, commit log, remote push.
  - Files: `docs/PROCUREMENT_ANALYTICS.md`, repository metadata only.
