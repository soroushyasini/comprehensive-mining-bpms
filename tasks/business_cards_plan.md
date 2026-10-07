# Business-card module delivery plan

Accepted scope: shared archive, 443 immutable source entries, 304 optional scans,
one current scan per card, multiple contact points/locations, separate business
and address countries, duplicate warnings, and an accessible in-page image popup.
No OCR, QR, geocoding, merges, legacy-table import, routed workflow or map in v1.

Build order and acceptance:

1. Add migration 014 and fixed countries; unique generated key enforces one current image.
2. Extract/review a private manifest; import all 443 entries and 304 scans in a guarded fixture; reimport creates zero and preserves edits.
3. Implement shared-auth CRUD/read API, optimistic locking, replay, audit and private originals only; fetch images on popup demand.
4. Implement scoped corporate RTL archive/editor and single-file upload, with Blob popup and cleanup.
5. Verify seed fidelity, permissions, concurrency, rollback, image integrity, popup focus, XSS, mobile and read-only behavior.
6. Document coordinated deployment and real ProcessMaker session acceptance.

Task status: `tasks/business_cards_todo.md`. Existing unrelated plans and changes
are retained. Production credentials/session/runtime are never inferred from fixture results.
