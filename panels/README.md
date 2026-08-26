# EMCORE ProcessMaker panels

These are the deployable Panel WebControl files corresponding to the secured APIs:

| Panel | API | Permission module |
|---|---|---|
| `emcore_companies_panel.html` | `/emcore_api/emcore_companies_api.php` | `companies` |
| `emcore_persons_panel.html` | `/emcore_api/emcore_persons_api.php` | `persons` |
| `emcore_mines_panel.html` | `/emcore_api/emcore_mines.php` | `mines` |
| `emcore_mine_technical_managers_panel.html` | `/emcore_api/emcore_mine_technical_managers.php` | `mine_technical_managers` |
| `emcore_drilling_reports_panel.html` | `/emcore_api/emcore_drilling_reports.php` | `drilling_daily_reports` |
| `emcore_visitor_log_panel.html` | `/emcore_api/emcore_visitor_log.php` | `visitor_log` |

## Security integration

Each panel:

1. loads data using the module's `list` action;
2. captures the returned `csrf_token`;
3. sends it as `X-CSRF-Token` on later jQuery requests;
4. reads the returned CRUD capability map;
5. hides create, edit, and delete controls the current user cannot use; and
6. escapes database-backed values before inserting them into generated HTML.

The APIs remain the enforcement boundary. Hiding a button is only a user-interface improvement and is not treated as authorization.

## Deployment

- Replace the corresponding ProcessMaker WebContent/Panel content with these files.
- Keep panels and APIs on the same origin so the ProcessMaker PHP session cookie is included.
- Do not paste a fixed CSRF token into a panel; tokens are session-specific and are obtained from the API.
- After deployment, force-refresh the Dynaform to avoid an old cached WebControl.
