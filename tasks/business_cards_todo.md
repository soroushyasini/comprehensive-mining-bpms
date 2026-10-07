# Business cards implementation

- [x] Migration and country seed; immutable source model and one-current-image constraint.
- [x] Offline manifest extraction and idempotent import of all 443 entries / 304 images.
- [x] Secured CRUD, filtered archive, duplicate warnings and private image lifecycle.
- [x] Corporate RTL panel, single-image editor and accessible image popup.
- [x] Disposable integration/browser verification and deployment documentation.

Verified 2026-10-06: fresh PHP 8.2/MySQL 8.4 fixture, 443/304/139 seed counts,
295 geolocated cards and 297 points, zero-new-record replay, complete source/image
fidelity, changed-source reporting without writes, 54 API checks and 23 browser
checks. Native ProcessMaker deployment and Dynaform acceptance remain environment
acceptance steps described in docs/BUSINESS_CARDS_DEPLOYMENT.md.

Existing plans and unrelated working-tree changes are preserved. Production deployment requires the actual ProcessMaker session and private-storage configuration; local verification uses synthetic identities only.

Prepared import delivered 2026-10-07: direct transactional SQL, 443-row review CSV,
one flat storage folder with 304 originals, manifest/checksums,
Persian instructions and ZIP. A dedicated fixture verified shifted destination IDs,
rollback, replay, preserved user edits, changed-source refusal and every original
through the authenticated API (942 checks after removing thumbnails). The table
loads no image until its preview popup is opened. Compatibility migration 015
preserves existing data while making the old thumbnail columns optional.
