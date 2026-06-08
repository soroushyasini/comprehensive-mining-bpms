
# EMCORE — Developer Reference
**comprehensive-mining-bpms**  
Stack: ProcessMaker 3.8 · MySQL · PHP/JS (Panel WebControl)

---

## Table of Contents
1. [Project Overview](#1-project-overview)
2. [Architecture](#2-architecture)
3. [Database Schema Quick Reference](#3-database-schema-quick-reference)
4. [Module Specs](#4-module-specs)
   - 4.1 [Companies](#41-companies)
   - 4.2 [Persons](#42-persons)
   - 4.3 [Company–Person Relations](#43-companyperson-relations)
   - 4.4 [Memberships & Certifications](#44-memberships--certifications)
   - 4.5 [Tokens](#45-tokens)
   - 4.6 [Email Accounts](#46-email-accounts)
   - 4.7 [Internet Services](#47-internet-services)
   - 4.8 [Attachments](#48-attachments)
5. [Cross-Cutting Features](#5-cross-cutting-features)
6. [Alert & Expiry System](#6-alert--expiry-system)
7. [ProcessMaker Integration Notes](#7-processmaker-integration-notes)
8. [Planned Future Tables](#8-planned-future-tables)
9. [Naming Conventions](#9-naming-conventions)

---

## 1. Project Overview

EMCORE is the central operational database for a group of 6 mining companies
under the Emidco umbrella. It manages:

- Corporate identity and structure
- Personnel records and company roles
- Memberships, certifications, and licenses
- Digital signature tokens
- Email and system credentials
- Hosting and domain services
- Document attachments per entity

**Primary companies managed:**

| ID | Short Name | Legal Name |
|----|-----------|------------|
| 1  | امیدکو (Emidco) | شرکت سرمایه‌گذاری و توسعه معادن شرق |
| 2  | کاوش | کاوش گستر امین |
| 3  | تپه سیاه | معدن مس تپه سیاه سبزوار |
| 4  | مس میامی | معدن کاران مس میامی |
| 5  | تهاتر | تهاتر کالای خراسان |
| 6  | شتابدهنده | شتابدهنده |

---

## 2. Architecture

```
ProcessMaker 3.8
│
├── Dynaforms         → data entry forms per module
├── Panel WebControl  → dashboards, lists, expiry alerts
├── Triggers (PHP)    → DB reads/writes via MySQL PDO
└── REST API          → optional external integrations
        │
        ▼
    MySQL Database
        │
        └── emcore_* tables  (this schema)
```

**Auth:** Fully delegated to ProcessMaker engine.  
`emcore_persons.pm_user_id` → maps to ProcessMaker user when needed.

---

## 3. Database Schema Quick Reference

```
emcore_companies
emcore_persons
emcore_company_persons      → pivot: persons ↔ companies (roles + shares)
emcore_memberships          → certs, licenses, associations per company
emcore_tokens               → e-signature tokens per company/person
emcore_email_accounts       → email + system credentials per company/person
emcore_internet_services    → domains, hosting, internet per company
emcore_attachments          → polymorphic file store for all entities
```

**Soft delete pattern:** every table has `deleted_at DATETIME DEFAULT NULL`.  
Never hard-delete. Filter with `WHERE deleted_at IS NULL`.

**Date convention:**  
- `*_date_fa` → Jalali string `VARCHAR(10)` format `YYYY/MM/DD`  
- `*_date_en` → Gregorian `DATE` — used for all expiry calculations and alerts

---

## 4. Module Specs

---

### 4.1 Companies

**Table:** `emcore_companies`

#### List View
- Columns: `name_fa`, `legal_type`, `registration_number`, `national_id`, `phone`, `is_active`
- Filter by: `is_active`, `legal_type`
- Search: `name_fa`, `national_id`, `registration_number`
- Actions: View · Edit · Deactivate · Upload Attachment

#### Detail View
Tabs:
1. **Info** — all company fields
2. **Board & Shareholders** — pulled from `emcore_company_persons`
3. **Memberships** — pulled from `emcore_memberships` (with expiry badges)
4. **Tokens** — pulled from `emcore_tokens`
5. **Emails & Credentials** — pulled from `emcore_email_accounts`
6. **Internet Services** — pulled from `emcore_internet_services`
7. **Documents** — pulled from `emcore_attachments WHERE entity_type='company'`

#### Key Query — Company Card
```sql
SELECT
    c.*,
    GROUP_CONCAT(
        DISTINCT CONCAT(p.first_name,' ',p.last_name,'(',cp.role_type,')')
        ORDER BY cp.role_type SEPARATOR ' | '
    ) AS board_summary
FROM emcore_companies c
LEFT JOIN emcore_company_persons cp ON cp.company_id = c.id
    AND cp.deleted_at IS NULL
LEFT JOIN emcore_persons p ON p.id = cp.person_id
WHERE c.deleted_at IS NULL
GROUP BY c.id;
```

#### Attachment Categories
`gazette` · `registration_cert` · `articles_of_association` · `logo` · `tax_cert` · `other`

---

### 4.2 Persons

**Table:** `emcore_persons`

#### List View
- Columns: `first_name`, `last_name`, `national_id`, `phone_mobile`, `is_active`
- Search: `last_name`, `national_id`
- Actions: View · Edit · Deactivate · Upload Attachment

#### Detail View
Tabs:
1. **Info** — personal fields + education
2. **Roles** — all companies this person is linked to via `emcore_company_persons`
3. **Tokens** — their tokens from `emcore_tokens`
4. **Emails** — personal email accounts
5. **Documents** — `emcore_attachments WHERE entity_type='person'`

#### Key Query — Person's Company Roles
```sql
SELECT
    c.name_fa   AS company,
    cp.role_title,
    cp.role_type,
    cp.end_date_fa,
    cp.is_current
FROM emcore_company_persons cp
JOIN emcore_companies c ON c.id = cp.company_id
WHERE cp.person_id = :person_id
  AND cp.deleted_at IS NULL
ORDER BY cp.role_type;
```

#### Attachment Categories
`national_id` · `birth_cert` · `contract` · `insurance_booklet` · `degree` · `photo` · `other`

#### ⚠️ Incomplete Data Note
Personnel records rows 4–28 were reconstructed from secondary sources.
`national_id`, `birth_date_fa`, and education fields need to be completed
from the full `مشخصات_پرسنل` spreadsheet.

---

### 4.3 Company–Person Relations

**Table:** `emcore_company_persons`

#### Use Cases
- Board of directors management
- Shareholder registry
- Mandate expiry tracking

#### List View (per company)
- Group by `role_type`
- Show `share_amount` for shareholders
- Highlight rows where `end_date_fa` is within 60 days

#### Role Type Display Map

| `role_type` | نمایش فارسی |
|---|---|
| `board_chair` | رئیس هیئت مدیره |
| `board_vice_chair` | نائب رئیس هیئت مدیره |
| `board_member` | عضو هیئت مدیره |
| `ceo` | مدیر عامل |
| `auditor_primary` | بازرس اصلی |
| `auditor_alternate` | بازرس علی‌البدل |
| `shareholder` | سهامدار |
| `staff` | پرسنل |

#### ⚠️ Mandate Expiry Alerts
Company 5 (تهاتر) board mandate is critical — expires **1405/03/20**.  
See [Alert System](#6-alert--expiry-system).

---

### 4.4 Memberships & Certifications

**Table:** `emcore_memberships`

#### List View
- Columns: `company (name_fa)`, `title`, `membership_type`, `expiry_date_en`, `is_active`
- Filter by: `company_id`, `membership_type`, `is_active`
- Default sort: `expiry_date_en ASC` (soonest expiry first)
- Expiry badge:
  - 🔴 Expired
  - 🟡 Expiring within 60 days
  - 🟢 Valid

#### Key Query — Expiry Dashboard
```sql
SELECT
    c.name_fa       AS company,
    m.title,
    m.membership_type,
    m.expiry_date_fa,
    m.expiry_date_en,
    DATEDIFF(m.expiry_date_en, CURDATE()) AS days_remaining,
    CASE
        WHEN m.expiry_date_en < CURDATE()          THEN 'expired'
        WHEN m.expiry_date_en < DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'expiring_soon'
        ELSE 'valid'
    END AS status
FROM emcore_memberships m
JOIN emcore_companies c ON c.id = m.company_id
WHERE m.deleted_at IS NULL
ORDER BY m.expiry_date_en ASC;
```

#### ⚠️ Currently Expired (as of 2026-06-02)
- All ISO / HSE certs (expired 2026-01-28) — need urgent renewal
- ISO 9001 initial cert (expired 2024-11-08)
- Iran–UK Chamber (expired 2026-05-16)
- انجمن مس, خانه معدن, اتحادیه حفاران, IROPEX, ایران–چین

#### Attachment Categories
`certificate_scan` · `card_scan` · `renewal_invoice` · `other`

---

### 4.5 Tokens

**Table:** `emcore_tokens`

#### List View
- Columns: `company`, `person`, `token_label`, `token_type`, `expiry_date_en`, `is_active`
- Filter by: `company_id`, `token_type`
- Expiry badge same as memberships

#### ⚠️ Security Note
`pin` field is stored plaintext per current policy (ProcessMaker handles auth).
Do not expose `pin` in any public-facing or role-unrestricted view.
Gate all token views behind a dedicated ProcessMaker role.

#### Key Query — Token Summary per Company
```sql
SELECT
    c.name_fa,
    t.token_label,
    t.token_type,
    CONCAT(p.first_name,' ',p.last_name) AS holder,
    t.expiry_date_fa,
    t.expiry_date_en
FROM emcore_tokens t
JOIN emcore_companies c ON c.id = t.company_id
LEFT JOIN emcore_persons p ON p.id = t.person_id
WHERE t.deleted_at IS NULL
ORDER BY c.id, t.token_type;
```

---

### 4.6 Email Accounts

**Table:** `emcore_email_accounts`

#### List View
- Columns: `owner (company/person)`, `email_address`, `email_type`, `purpose`, `is_active`
- Filter by: `email_type`, `company_id`

#### ⚠️ Security Note
`password_hint` is stored plaintext per current policy.  
Same gating rule as tokens — restrict via ProcessMaker role.  
Future: migrate to a secrets vault and store only a reference key here.

#### Email Type Display Map

| `email_type` | Usage |
|---|---|
| `corporate` | Official company / staff email |
| `personal` | Personal email of a person |
| `system` | Login for an external system/portal |
| `tender` | Tender/procurement portal |

---

### 4.7 Internet Services

**Table:** `emcore_internet_services`

#### List View
- Columns: `service_name`, `service_type`, `provider`, `expiry_date_en`, `is_active`
- Expiry badge same pattern as memberships

#### ⚠️ Currently Expiring
- Static IP (داده پردازی پارس) expires **2026-07-03** — within 30 days

---

### 4.8 Attachments

**Table:** `emcore_attachments`

Polymorphic — one table serves all entities.

#### Supported entity_type values
`company` · `person` · `membership` · `token` · `email_account` · `internet_service`

#### Upload Flow
1. User uploads file via Dynaform file input
2. ProcessMaker trigger saves file to server path
3. Insert row into `emcore_attachments` with:
   - `entity_type` = target entity
   - `entity_id`   = target row ID
   - `category`    = document type
   - `file_path`   = relative path under ProcessMaker files directory
   - `uploaded_by` = `emcore_persons.id` of current PM user

#### Key Query — Fetch All Docs for a Company
```sql
SELECT * FROM emcore_attachments
WHERE entity_type = 'company'
  AND entity_id   = :company_id
  AND deleted_at  IS NULL
ORDER BY uploaded_at DESC;
```

---

## 5. Cross-Cutting Features

### Search / Global Lookup
A unified search across persons + companies is useful:

```sql
SELECT 'company' AS type, id, name_fa AS label, national_id AS identifier
FROM emcore_companies WHERE name_fa LIKE :q AND deleted_at IS NULL
UNION ALL
SELECT 'person', id,
    CONCAT(first_name,' ',last_name), national_id
FROM emcore_persons WHERE last_name LIKE :q AND deleted_at IS NULL;
```

### Audit Trail
All tables have `created_at` / `updated_at` / `deleted_at`.  
For full change history, a future `emcore_audit_log` table can capture
before/after snapshots triggered from ProcessMaker PHP triggers.

### Soft Delete Convention
```sql
-- Delete
UPDATE emcore_[table] SET deleted_at = NOW() WHERE id = :id;

-- Restore
UPDATE emcore_[table] SET deleted_at = NULL WHERE id = :id;

-- All active queries must include:
WHERE deleted_at IS NULL
```

---

## 6. Alert & Expiry System

Central feature — many records in this DB have deadlines.

### Priority Alerts (as of seeding date 2026-06-02)

| 🔴 | Entity | Detail | Deadline |
|---|---|---|---|
| CRITICAL | تهاتر board mandate | عضویت هیئت مدیره | 1405/03/20 |
| URGENT | Static IP | داده پردازی پارس | 2026-07-03 |
| EXPIRED | ISO/HSE certs (6 items) | All company 1 | 2026-01-28 |
| EXPIRED | Iran–UK Chamber | امیدکو + کاوش | 2026-05-16 |

### Unified Expiry Query (for dashboard widget)
```sql
SELECT
    'membership'        AS module,
    m.id                AS record_id,
    c.name_fa           AS company,
    m.title             AS label,
    m.expiry_date_en    AS expires_on,
    DATEDIFF(m.expiry_date_en, CURDATE()) AS days_left
FROM emcore_memberships m
JOIN emcore_companies c ON c.id = m.company_id
WHERE m.deleted_at IS NULL AND m.expiry_date_en IS NOT NULL

UNION ALL

SELECT
    'token',
    t.id,
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
    c.name_fa,
    CONCAT('هیئت مدیره - ', c.name_fa),
    STR_TO_DATE(REPLACE(cp.end_date_fa,'/','-'), '%Y-%m-%d'), -- requires Jalali conversion
    NULL
FROM emcore_company_persons cp
JOIN emcore_companies c ON c.id = cp.company_id
WHERE cp.deleted_at IS NULL AND cp.end_date_fa IS NOT NULL AND cp.is_current = 1

ORDER BY days_left ASC;
```

### Threshold Definitions

| `days_left` | Status | Badge Color |
|---|---|---|
| < 0 | `expired` | 🔴 Red |
| 0 – 30 | `critical` | 🟠 Orange |
| 31 – 60 | `warning` | 🟡 Yellow |
| > 60 | `valid` | 🟢 Green |

---

## 7. ProcessMaker Integration Notes

### Connecting to EMCORE from a Trigger
```php
// In a ProcessMaker PHP trigger
$db = new PDO(
    'mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4',
    'YOUR_USER',
    'YOUR_PASS'
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->prepare("
    SELECT * FROM emcore_companies
    WHERE deleted_at IS NULL
    ORDER BY name_fa
");
$stmt->execute();
@@companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Linking PM Users to Persons
When a ProcessMaker user is created for a person:
```sql
UPDATE emcore_persons
SET pm_user_id = :pm_user_guid
WHERE id = :person_id;
```

Then in any trigger you can resolve the current user's person record:
```php
$pmUserId = @@USER_LOGGED;
$stmt = $db->prepare("
    SELECT * FROM emcore_persons
    WHERE pm_user_id = ? AND deleted_at IS NULL
");
$stmt->execute([$pmUserId]);
@@current_person = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Recommended ProcessMaker Processes to Build

| Process | Trigger Tables |
|---|---|
| Company onboarding | `emcore_companies`, `emcore_company_persons` |
| Personnel onboarding | `emcore_persons`, `emcore_company_persons` |
| Certification renewal | `emcore_memberships`, `emcore_attachments` |
| Token renewal | `emcore_tokens` |
| Domain / hosting renewal | `emcore_internet_services` |
| Expiry alert digest (scheduled) | All `*_date_en` columns |

---

## 8. Planned Future Tables

These are identified from the remaining CSVs — not yet implemented:

| Table (proposed) | Source CSV | Description |
|---|---|---|
| `emcore_correspondence_out` | `ارسال_با_پست` | Outgoing postal correspondence |
| `emcore_correspondence_in` | `دریافت_از_پست` | Incoming postal correspondence |
| `emcore_referrals` | `ارجاعات` | Internal task referrals |
| `emcore_price_requests` | `درخواست_قیمت` | Inbound price inquiry leads |
| `emcore_company_changes` | `تغییرات_شرکتها` | Board meeting & charter change log |
| `emcore_credentials` | `pass.csv` | System passwords (beyond email) |
| `emcore_external_parties` | scattered across CSVs | Vendors, clients, gov organizations |
| `emcore_audit_log` | — | Full change history for all tables |

---

## 9. Naming Conventions

| Item | Convention | Example |
|---|---|---|
| Tables | `emcore_` prefix + snake_case | `emcore_company_persons` |
| Columns | snake_case, English | `expiry_date_en` |
| Jalali date columns | suffix `_fa` | `birth_date_fa` |
| Gregorian date columns | suffix `_en` | `expiry_date_en` |
| Boolean flags | `is_` prefix | `is_active`, `is_current` |
| Soft delete | `deleted_at` | always `DATETIME DEFAULT NULL` |
| FK columns | `{table_singular}_id` | `company_id`, `person_id` |
| ProcessMaker variables | `@@camelCase` | `@@currentPerson` |

---

*Last updated: 2026-06-02*  
*Schema version: 1.0*  
*Author: EMCORE project*

---

