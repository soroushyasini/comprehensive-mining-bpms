// BEFORE DYNAFORM on each task. The API validates native session/task again.
if (!defined('EMCORE_NATIVE_CONTEXT')) define('EMCORE_NATIVE_CONTEXT', true);
require_once PATH_HTML . 'emcore_api/_procurement_workflow.php';
if (!emcore_pw_enabled() || @@PROCESS !== emcore_pw_settings()['process']) {
    die('<div dir="rtl" role="alert">فرایند پیگیری فراخوان پیکربندی نشده است.</div>');
}
@@emcore_command_id = '';
@@emcore_native_panel = file_get_contents(PATH_HTML . 'emcore_panels/emcore_procurement_workflow_panel.html');
if (!@@emcore_native_panel) die('<div dir="rtl" role="alert">فرم پیگیری در دسترس نیست.</div>');
