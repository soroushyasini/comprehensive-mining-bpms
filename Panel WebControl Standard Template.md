
# EMCORE — Panel WebControl Standard Template
**ProcessMaker 3.8 · Panel WebControl inside Dynaform**

---

## Key Rules

| Rule | Detail |
|---|---|
| Read field | `$("#fieldName").getValue()` |
| Write field | `$("#fieldName").setValue(value)` |
| Submit form | `PMDynaform.getView().submit()` |
| Init timing | Always wrap init inside `setTimeout` — PM needs time to register fields |
| Field name | Use the variable name only — no `@@`, no `form[]` wrapper |

---

## Why setTimeout

PM registers `.getValue()` / `.setValue()` on fields after the DOM is ready.
Calling them inside bare `$(document).ready()` fires too early.
A 500ms delay is safe. Tune down once the process is stable.

```javascript
$(document).ready(function () {
    setTimeout(function () {
        init();
    }, 500);
});
```

---

## Boilerplate Template

```html
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
  <meta charset="UTF-8">
  <style>
    /* your styles here */
    #errorMessage { color: red; font-size: 13px; margin-bottom: 10px; }
  </style>
</head>
<body>

  <div id="errorMessage"></div>

  <!-- your UI here -->
  <div id="tableContainer"></div>

  <script>
    // ── Global State ────────────────────────────────────────────────────────
    var ALL_DATA = [];

    // ── Init ────────────────────────────────────────────────────────────────
    $(document).ready(function () {
      setTimeout(function () {

        loadData();

        // wire up static buttons here
        $("#myButton").click(function () {
          doSomething();
        });

      }, 500); // PM needs this delay to register .getValue() / .setValue()
    });

    // ── Load Data from PM field ─────────────────────────────────────────────
    function loadData() {
      try {
        var jsonString = $("#your_json_field").getValue();

        if (!jsonString) {
          throw new Error("فیلد your_json_field خالی است — تریگر بارگذاری را بررسی کنید");
        }

        ALL_DATA = JSON.parse(jsonString);
        console.log("Data loaded:", ALL_DATA.length, "records");

        drawTable(ALL_DATA);
        $("#errorMessage").html("");

      } catch (error) {
        console.error("loadData error:", error);
        $("#tableContainer").html("");
        $("#errorMessage").html(error.message);
      }
    }

    // ── Draw / Render ───────────────────────────────────────────────────────
    function drawTable(data) {
      if (!data.length) {
        $("#tableContainer").html("<p>موردی یافت نشد</p>");
        return;
      }

      var html = "<table><tbody>";
      $.each(data, function (i, row) {
        html += "<tr><td>" + row.id + "</td><td>" + row.name + "</td></tr>";
      });
      html += "</tbody></table>";

      $("#tableContainer").html(html);
    }

    // ── Actions ─────────────────────────────────────────────────────────────
    // Pattern: set PM fields → submit form → trigger handles the rest

    function goCreate() {
      try {
        $("#action").setValue("create");
        $("#record_id").setValue("");
        pmSubmit();
      } catch (e) {
        showError(e.message);
      }
    }

    function goEdit(id) {
      try {
        $("#action").setValue("edit");
        $("#record_id").setValue(String(id));
        pmSubmit();
      } catch (e) {
        showError(e.message);
      }
    }

    function goDelete(id, label) {
      if (confirm("حذف «" + label + "»؟")) {
        try {
          $("#action").setValue("delete");
          $("#record_id").setValue(String(id));
          pmSubmit();
        } catch (e) {
          showError(e.message);
        }
      }
    }

    // ── PM Submit ───────────────────────────────────────────────────────────
    function pmSubmit() {
      try {
        PMDynaform.getView().submit();
      } catch (e) {
        // fallback
        $("button[type=submit], input[type=submit]").first().trigger("click");
      }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    function showError(msg) {
      console.error(msg);
      $("#errorMessage").html(msg);
    }

    function safeStr(s) {
      return (s || "").replace(/\\/g, "\\\\").replace(/'/g, "\\'");
    }

  </script>
</body>
</html>
```

---

## Required Dynaform Fields (per process)

Every process that uses this panel pattern needs these hidden fields in the Dynaform:

| Variable Name | Type | Purpose |
|---|---|---|
| `your_json_field` | Text | JSON data from trigger → panel reads it |
| `action` | Text | `create` / `edit` / `delete` — panel sets it |
| `record_id` | Text | ID of the target record — panel sets it |

Hide them in production via Dynaform field properties → `Hidden = true`.  
Keep visible during development for easy debugging.

---

## Trigger Pattern (companion to the panel)

### BEFORE task (load data into PM field)
```php
// Always default first — guarantees valid JSON even if query fails
@@your_json_field = '[]';

$db = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=wf_pishro;charset=utf8mb4',
    'root',
    'zxc123ASD456'
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->prepare("
    SELECT * FROM your_table
    WHERE deleted_at IS NULL
    ORDER BY id
");
$stmt->execute();

@@your_json_field = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
```

### AFTER task (read action + record_id, branch logic)
```php
$action    = @@action;
$record_id = (int) @@record_id;

if ($action === 'create') {
    // INSERT logic
} elseif ($action === 'edit' && $record_id > 0) {
    // UPDATE logic
} elseif ($action === 'delete' && $record_id > 0) {
    // Soft delete
    $db->prepare("UPDATE your_table SET deleted_at = NOW() WHERE id = :id")
       ->execute([':id' => $record_id]);
}
```

---

## Checklist Before Testing

- [ ] BEFORE trigger sets `@@your_json_field` (with `= '[]'` default at top)
- [ ] Dynaform has the 3 fields: json field, `action`, `record_id`
- [ ] Panel HTML file uploaded to process WebContent
- [ ] Panel WebControl added to Dynaform and pointing to that file
- [ ] `setTimeout` delay is 500ms or more
- [ ] Fields are visible during debug, hidden in production

---

*EMCORE Dev Reference · ProcessMaker 3.8 Panel WebControl Pattern · v1.0*
