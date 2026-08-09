# Understanding `_fa` / `_en` Date Columns in EMCORE

## The Problem

Your data comes from Iranian sources, so dates are written in the **Jalali (Shamsi) calendar** —
e.g. `1404/08/12`. This is what your forms, datepickers, and humans use.

But MySQL's `DATE` type, and everything built on it (`DATEDIFF`, `CURDATE()`, sorting,
"days until expiry" calculations), **only understands the Gregorian calendar**.

If you store `1404/08/12` as a string and ask MySQL "how many days until this date?",
MySQL has no idea what that string even means — it's not a date to MySQL, just text.
You can't do math on it, can't compare it to today, can't sort it correctly across
years in all cases.

## The Solution: Store Both

For every date that's used for **expiry/deadline tracking**, we store it **twice**,
in two columns:

| Column | Type | Format | Purpose |
|---|---|---|---|
| `xxx_fa` | `VARCHAR(10)` | `1404/08/12` (Jalali) | What humans see/enter — the "real" field |
| `xxx_en` | `DATE` | `2025-11-02` (Gregorian) | What MySQL calculates with — auto-derived |

**`_fa` is the source of truth.** `_en` is just a translation of it, kept in sync,
that exists purely so the database can do math.

## Concrete Example: `emcore_internet_services` row #4

```
expiry_date_fa = '1404/08/12'   <- entered by a human, this is what they typed
expiry_date_en = '2025-11-02'   <- computed automatically from the line above
```

Now this works:

```sql
SELECT DATEDIFF(expiry_date_en, CURDATE()) AS days_left
FROM emcore_internet_services WHERE id = 4;
-- result: -221  (it expired 221 days ago)
```

If `expiry_date_en` didn't exist, this query would be impossible to write correctly —
you'd have to somehow teach SQL to parse Jalali dates inline, which it can't do natively.

## How `_en` Gets Filled In

We wrote a small MySQL function: `shamsi_slash_to_gregorian_date()`.

It takes a Jalali date string like `'1404/08/12'` and returns a real `DATE` value
(`2025-11-02`). That's it — it's a translator, nothing more.

```sql
SELECT shamsi_slash_to_gregorian_date('1404/08/12');
-- returns: 2025-11-02
```

## The "Sync" Part — What Was Actually Wrong

When we first added the `_mines` and `_mine_technical_managers` tables (and a few
columns to existing tables), the `_fa` values were entered/inserted, but the `_en`
columns were either:

- not created yet, or
- created but left `NULL` (nobody ran the conversion)

So we ran a **one-time backfill**:

```sql
UPDATE emcore_internet_services
SET expiry_date_en = shamsi_slash_to_gregorian_date(expiry_date_fa)
WHERE expiry_date_fa IS NOT NULL AND expiry_date_en IS NULL;
```

This says: *"for every row where the Jalali date is filled in but the Gregorian
translation is missing, compute it now."*

We did this check across all 6 tables that have expiry-type dates. Five were already
fine (`0` unsynced). One row in `internet_services` had been entered after the `_en`
column existed but before anyone ran the conversion for it — so it was the one
leftover. Now fixed.

## Going Forward — The Rule You Need to Remember

**Anywhere your CRUD form lets someone type/pick a `_fa` expiry date, the save
operation must update BOTH columns at once:**

```sql
UPDATE emcore_mines
SET license_validity_fa = :date_string,
    license_validity_en = shamsi_slash_to_gregorian_date(:date_string)
WHERE id = :id;
```

If you only ever update `_fa` and forget `_en`, you'll silently recreate the exact
bug we just fixed — the record will look fine in the UI (shows the Persian date)
but won't show up correctly in expiry alerts/dashboards, because `_en` will be
stale or `NULL`.

## Which Columns Have This Pair (current state — all synced)

| Table | `_fa` column | `_en` column |
|---|---|---|
| `emcore_memberships` | `expiry_date_fa` | `expiry_date_en` |
| `emcore_tokens` | `expiry_date_fa` | `expiry_date_en` |
| `emcore_internet_services` | `expiry_date_fa` | `expiry_date_en` |
| `emcore_mines` | `license_validity_fa` | `license_validity_en` |
| `emcore_mine_technical_managers` | `contract_validity_fa` | `contract_validity_en` |
| `emcore_company_persons` | `end_date_fa` | `end_date_en` |

Other `_fa` date fields (e.g. `registration_date_fa`, `license_date_fa`,
`contract_date_fa`, `birth_date_fa`) are **issue/start dates, not expiries** —
they're never used in `DATEDIFF`/alerts, so they intentionally have **no `_en`
counterpart**. Nothing to do there.

## TL;DR

- `_fa` = what's typed/displayed (Jalali, text)
- `_en` = auto-computed twin (Gregorian, real `DATE`) used for all math
- `shamsi_slash_to_gregorian_date(_fa)` is the function that keeps them in sync
- Every save that touches a `_fa` expiry field must also recompute its `_en`
- All dashboard/alert queries (`emcore_expiry_dashboard`) read `_en` only
