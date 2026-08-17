# EMCORE — Developer Reference Addendum
**Mines & Technical Managers + Unified Expiry Dashboard View**
*Appended: 2026-06-11 · Schema version: 1.1*

---

## A. New Tables

### A.1 Mines

**Table:** `emcore_mines`

```sql
CREATE TABLE emcore_mines (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED DEFAULT NULL,
    relationship_type VARCHAR(32) NOT NULL DEFAULT 'owned',
    related_person_id INT UNSIGNED DEFAULT NULL,
    merged_into_id INT UNSIGNED DEFAULT NULL,
    mine_name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    mineral_type VARCHAR(100) COLLATE utf8mb4_unicode_ci,
    status VARCHAR(100) COLLATE utf8mb4_unicode_ci,
    license_number VARCHAR(50) COLLATE utf8mb4_unicode_ci,
    license_date_fa VARCHAR(10) COLLATE utf8mb4_unicode_ci,
    license_validity_fa VARCHAR(10) COLLATE utf8mb4_unicode_ci,
    license_validity_en DATE,
    proven_reserve_tons DECIMAL(15,2),
    probable_reserve_tons DECIMAL(15,2),
    annual_extraction_tons DECIMAL(15,2),
    cutoff_grade VARCHAR(20) COLLATE utf8mb4_unicode_ci,
    average_grade VARCHAR(20) COLLATE utf8mb4_unicode_ci,
    cadastre_code VARCHAR(50) COLLATE utf8mb4_unicode_ci,
    guarantee_letter_date_fa VARCHAR(10) COLLATE utf8mb4_unicode_ci,
    ore_subtype VARCHAR(100) COLLATE utf8mb4_unicode_ci,
    alias_name VARCHAR(255) COLLATE utf8mb4_unicode_ci,
    notes TEXT COLLATE utf8mb4_unicode_ci,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    KEY idx_company_id (company_id),
    FOREIGN KEY (company_id) REFERENCES emcore_companies(id),
    FOREIGN KEY (related_person_id) REFERENCES emcore_persons(id),
    FOREIGN KEY (merged_into_id) REFERENCES emcore_mines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Notes
- `relationship_type` distinguishes `owned`, `contractor`, and `personnel_related` records. Company is required for owned/contractor mines; person is required for personnel-related mines.
- `merged_into_id` records soft-merge lineage; foreign keys move to the canonical mine before the duplicate is soft-retired.
- `license_validity_fa` is the source field; `license_validity_en` is derived (see §C) and used for all expiry calculations.
- `ore_subtype` stores multiple ore types on one mine record when the license and cadastre are shared (e.g. merged `تپه سیاه شمالی` stores `سولفیدی، اکسیدی`).
- `alias_name` stores an informal/alternate name for the mine (e.g. "توسعه" aliased as "چاه یابو").
- `_` / `؟` / blank source values → stored as `NULL`.

#### Attachment Categories (for `emcore_attachments`)
`license_doc` · `guarantee_letter` · `other`
(extend `entity_type` enum with `mine`)

---

### A.2 Mine Technical Managers

**Table:** `emcore_mine_technical_managers`

```sql
CREATE TABLE emcore_mine_technical_managers (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    mine_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED DEFAULT NULL,
    full_name VARCHAR(255) COLLATE utf8mb4_unicode_ci,
    phone VARCHAR(20) COLLATE utf8mb4_unicode_ci,
    contact_method VARCHAR(50) COLLATE utf8mb4_unicode_ci,
    contract_date_fa VARCHAR(10) COLLATE utf8mb4_unicode_ci,
    contract_validity_fa VARCHAR(10) COLLATE utf8mb4_unicode_ci,
    contract_validity_en DATE,
    contract_amount VARCHAR(255) COLLATE utf8mb4_unicode_ci,
    payment_schedule VARCHAR(100) COLLATE utf8mb4_unicode_ci,
    is_current TINYINT(1) DEFAULT 1,
    notes TEXT COLLATE utf8mb4_unicode_ci,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    KEY idx_mine_id (mine_id),
    KEY idx_person_id (person_id),
    FOREIGN KEY (mine_id) REFERENCES emcore_mines(id),
    FOREIGN KEY (person_id) REFERENCES emcore_persons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Notes
- `contract_validity_fa` is the source field; `contract_validity_en` is derived (see §C) and used for expiry alerts.
- `person_id` links to `emcore_persons` if the technical manager is also tracked there; `full_name`/`phone` act as a standalone snapshot otherwise (most TMs are external contractors, not formal employees).
- `is_current = 0` for superseded/ended contracts — keeps history without deleting.
- `contract_date_fa` is the contract start date; no `_en` counterpart needed (not used for alerts).

#### Attachment Categories (for `emcore_attachments`)
`contract_doc` · `other`
(extend `entity_type` enum with `mine_technical_manager`)

---

## B. Schema Change to Existing Table

`emcore_company_persons` was missing a Gregorian counterpart for board mandate expiry, which section 6's original alert query referenced via an incorrect string-based conversion.

```sql
ALTER TABLE emcore_company_persons
  ADD COLUMN end_date_en DATE DEFAULT NULL AFTER end_date_fa;

UPDATE emcore_company_persons
SET end_date_en = shamsi_slash_to_gregorian_date(end_date_fa)
WHERE end_date_fa IS NOT NULL;
```

---

## C. Jalali → Gregorian Conversion

A pre-existing function `shamsi_to_gregorian(VARCHAR)` (dash-separated input, used elsewhere in the system) is **not modified**, since other processes depend on it.

Instead, a dedicated wrapper handles the project's `YYYY/MM/DD` (slash) format and returns a proper `DATE`:

```sql
CREATE DEFINER=`root`@`localhost` FUNCTION `shamsi_slash_to_gregorian_date`(shamsi_date VARCHAR(10))
RETURNS DATE
DETERMINISTIC
BEGIN
    IF shamsi_date IS NULL OR shamsi_date = '' THEN
        RETURN NULL;
    END IF;
    RETURN CAST(shamsi_to_gregorian(REPLACE(shamsi_date, '/', '-')) AS DATE);
END
```

### Usage Convention
- All `*_date_fa` / `*_validity_fa` fields remain `VARCHAR(10)` — used for **display only**.
- Any `_fa` field that represents an **expiry/deadline** must have a corresponding `_en DATE` column, populated via `shamsi_slash_to_gregorian_date()`.
- All alerting, `DATEDIFF`, and sorting logic operates **only** on `_en` columns.
- `_fa` fields that represent **start/issue dates** (e.g. `license_date_fa`, `contract_date_fa`, `guarantee_letter_date_fa`, `registration_date_fa`, `birth_date_fa`) do **not** need `_en` counterparts — they're not used for expiry calculations.

### Sync on Write
Whenever a `_fa` expiry field is inserted or edited (via ProcessMaker Dynaform/trigger), populate the matching `_en` field in the same operation:

```sql
UPDATE emcore_mines
SET license_validity_fa = :new_value,
    license_validity_en = shamsi_slash_to_gregorian_date(:new_value)
WHERE id = :id;
```

---

## D. Unified Expiry Dashboard (replaces §6 query)

The original placeholder conversion in section 6 (`STR_TO_DATE(REPLACE(cp.end_date_fa,'/','-'), '%Y-%m-%d')`) was incorrect — Jalali and Gregorian dates are not numerically interchangeable. It is replaced by the view below, which also adds `mine_license` and `tm_contract` modules and a computed `status` field.

```sql
CREATE OR REPLACE VIEW emcore_expiry_dashboard AS
SELECT *,
    CASE
        WHEN days_left < 0 THEN 'expired'
        WHEN days_left <= 30 THEN 'critical'
        WHEN days_left <= 60 THEN 'warning'
        ELSE 'valid'
    END AS status
FROM (

SELECT
    'membership' AS module,
    m.id AS record_id,
    c.id AS company_id,
    c.name_fa AS company,
    m.title AS label,
    m.expiry_date_en AS expires_on,
    DATEDIFF(m.expiry_date_en, CURDATE()) AS days_left
FROM emcore_memberships m
JOIN emcore_companies c ON c.id = m.company_id
WHERE m.deleted_at IS NULL AND m.expiry_date_en IS NOT NULL

UNION ALL

SELECT
    'token',
    t.id,
    c.id,
    c.name_fa,
    t.token_label,
    t.expiry_date_en,
    DATEDIFF(t.expiry_date_en, CURDATE())
FROM emcore_tokens t
LEFT JOIN emcore_companies c ON c.id = t.company_id
WHERE t.deleted_at IS NULL AND t.expiry_date_en IS NOT NULL

UNION ALL

SELECT
    'internet_service',
    s.id,
    c.id,
    c.name_fa,
    s.service_name,
    s.expiry_date_en,
    DATEDIFF(s.expiry_date_en, CURDATE())
FROM emcore_internet_services s
LEFT JOIN emcore_companies c ON c.id = s.company_id
WHERE s.deleted_at IS NULL AND s.expiry_date_en IS NOT NULL

UNION ALL

SELECT
    'board_mandate',
    cp.id,
    c.id,
    c.name_fa,
    CONCAT('هیئت مدیره - ', c.name_fa),
    cp.end_date_en,
    DATEDIFF(cp.end_date_en, CURDATE())
FROM emcore_company_persons cp
JOIN emcore_companies c ON c.id = cp.company_id
WHERE cp.deleted_at IS NULL AND cp.end_date_en IS NOT NULL AND cp.is_current = 1

UNION ALL

SELECT
    'mine_license',
    mn.id,
    c.id,
    c.name_fa,
    CONCAT(mn.mine_name, IFNULL(CONCAT(' (', mn.alias_name, ')'), '')),
    mn.license_validity_en,
    DATEDIFF(mn.license_validity_en, CURDATE())
FROM emcore_mines mn
JOIN emcore_companies c ON c.id = mn.company_id
WHERE mn.deleted_at IS NULL AND mn.license_validity_en IS NOT NULL

UNION ALL

SELECT
    'tm_contract',
    t.id,
    c.id,
    c.name_fa,
    CONCAT('قرارداد مسئول فنی - ', mn.mine_name, IFNULL(CONCAT(' (', t.full_name, ')'), '')),
    t.contract_validity_en,
    DATEDIFF(t.contract_validity_en, CURDATE())
FROM emcore_mine_technical_managers t
JOIN emcore_mines mn ON mn.id = t.mine_id
JOIN emcore_companies c ON c.id = mn.company_id
WHERE t.deleted_at IS NULL AND t.contract_validity_en IS NOT NULL AND t.is_current = 1

) AS unified_expiry;
```

### Usage

```sql
-- Full dashboard, soonest-expiring first
SELECT * FROM emcore_expiry_dashboard ORDER BY days_left;

-- Only items needing attention
SELECT * FROM emcore_expiry_dashboard WHERE status IN ('expired','critical','warning') ORDER BY days_left;

-- Per-company
SELECT * FROM emcore_expiry_dashboard WHERE company_id = :company_id ORDER BY days_left;
```

### Status Thresholds (unchanged from original §6)

| `days_left` | Status | Badge Color |
|---|---|---|
| < 0 | `expired` | 🔴 Red |
| 0 – 30 | `critical` | 🟠 Orange |
| 31 – 60 | `warning` | 🟡 Yellow |
| > 60 | `valid` | 🟢 Green |

### Frontend Notes
- `company_id` may be `NULL` for personal tokens (`person_id` set, no `company_id`) — the dashboard UI should fall back to a "شخصی" (personal) label in that case.
- This view is a live computation (no stored/cached status) — `days_left` and `status` are always current as of query time. No sync job needed; updating a `_en` source column is immediately reflected.
- A large backlog of long-expired memberships/contracts exists in current data (some since 2019/2021) — these may represent stale records needing an `is_active`/`is_current` review rather than active alerts. Consider a separate "active alerts" view filtering e.g. `days_left > -365` if the raw `expired` list is too noisy for a dashboard.

---

## E. Updated Schema Quick Reference (§3 addendum)

```
emcore_mines                    → mining licenses, reserves, grades per company
emcore_mine_technical_managers  → contracted technical managers per mine
emcore_expiry_dashboard (VIEW)  → unified expiry/alert feed across all modules
```

---

*Addendum author: EMCORE project*
*Schema version: 1.1*
