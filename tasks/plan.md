# Plan: Procurement analytics replacement

## Build order

1. Freeze the legacy metric map and the new API contract in `docs/PROCUREMENT_ANALYTICS.md`.
2. Add a read-only analytics API using the existing procurement permission and normalized tables.
3. Add the responsive RTL panel with shared filters, honest states, self-contained SVG charts, accessible data tables, and hierarchy drill-down.
4. Add static release checks and run the existing procurement regression check.
5. Review correctness, security, performance, documentation, deployment steps, and repository diff; then commit and push only this feature.

## Risks and mitigations

- Legacy null/unknown values could disappear from charts: normalize them to explicit `unknown`/`نامشخص` groups and disclose quality counts.
- Different charts could represent different populations: build one parameterized `WHERE` scope reused by every dashboard query.
- High-cardinality authorities/products could produce unusable or oversized output: validate `top_n`, compact display series, and cap hierarchy combinations at 1000 with truncation metadata.
- DynaForm may load remote chart libraries inconsistently: use same-origin API calls and dependency-free SVG rendering.
- Existing uncommitted drilling work could be overwritten: do not edit, stage, or commit the three dirty drilling files or `output/`.

## Verification checkpoints

- Contract: release test fails before API/panel exist.
- API: action allow-list, read permission, date validation, parameterized filters, deletion scope, and metric semantics pass static checks.
- UI: script parses; unique IDs; no inline handlers, `.html()` generation, remote scripts, or fallback data.
- Regression: existing procurement-notices release check remains green.
- Delivery: diff contains only procurement analytics, documentation, plan, and panel registry changes.
