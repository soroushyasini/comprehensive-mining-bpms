The user wants a summary of what we're building and where we are. Let me give a clear, concise overview.

We're building EMCORE - a CRUD system for 8 modules using ProcessMaker 3.8 as the UI layer, with a Panel WebControl + Bootstrap modals + AJAX API approach.

Current status:
- ✅ Companies module: Panel WebControl working (table renders), API file accessible
- 🔄 In progress: Connecting panel to API (just fixed the API URL)
- ⏳ Remaining: Test the full CRUD flow for Companies, then build remaining 7 modules
</current_rewritten_thinking>
</thinking>

## What We're Building — EMCORE CRUD

**Goal:** Manage 8 modules of company/personnel data for 6 Emidco mining companies, using ProcessMaker 3.8 as the UI — no external app, everything inside PM.

---

## Architecture (settled)

```
PM Dynaform
└── Panel WebControl (HTML + JS)
      └── Bootstrap modals for Create / Edit / Delete
            └── AJAX → emcore_*.php API files
                  └── MySQL (wf_pishro database)
```

No PM process routing. No page navigation. Everything happens in modals on one page.

---

## 8 Modules to Build

| # | Module | Table | Status |
|---|---|---|---|
| 1 | **Companies** | `emcore_companies` | 🔄 90% — panel renders, API works, need final test |
| 2 | Persons | `emcore_persons` | ⏳ |
| 3 | Company–Person Relations | `emcore_company_persons` | ⏳ |
| 4 | Memberships & Certifications | `emcore_memberships` | ⏳ |
| 5 | Tokens | `emcore_tokens` | ⏳ |
| 6 | Email Accounts | `emcore_email_accounts` | ⏳ |
| 7 | Internet Services | `emcore_internet_services` | ⏳ |
| 8 | Attachments | `emcore_attachments` | ⏳ |

---

## What's Done ✅

- DB connection confirmed (`wf_pishro`, root)
- `.htaccess` updated — `emcore_*.php` files are accessible
- PM Panel WebControl pattern locked in:
  - `$(document).ready` + `setTimeout(500)` for PM field init
  - `$("#field").getValue()` for reading PM fields
  - AJAX + Bootstrap modals (no PM routing needed)
- `emcore_companies_api.php` — deployed and responding
- Companies panel — renders table with all 6 companies

---

## Right Now 🔄

Finish Companies module:
1. Update `var API` in panel to point to `emcore_companies_api.php`
2. Test Create modal → saves new company
3. Test Edit modal → pre-fills and updates
4. Test Delete → soft deletes

Once Companies is fully working end-to-end → copy the pattern for Persons → repeat for all 8 modules.

---