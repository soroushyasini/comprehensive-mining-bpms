// Install BEFORE ASSIGNMENT on all three tasks. Not an asynchronous job.
if (!defined('EMCORE_NATIVE_CONTEXT')) define('EMCORE_NATIVE_CONTEXT', true);
require_once PATH_HTML . 'emcore_api/_procurement_workflow.php';
try {
    $emcoreRoute = emcore_pw_native_before_route(emcore_db(), @@emcore_command_id, [
        'app' => @@APPLICATION, 'process' => @@PROCESS, 'task' => @@TASK,
        'index' => @%INDEX, 'user' => @@USER_LOGGED
    ]);
    @@emcore_operator_uid = $emcoreRoute['operator_uid'];
    @@emcore_manager_uid = $emcoreRoute['manager_uid'];
    @@emcore_route = $emcoreRoute['route'];
} catch (Throwable $error) {
    error_log('EMCORE native assignment refused: ' . $error->getMessage());
    // Throwing alone can be swallowed by the PM trigger runner. Fail closed
    // before assignment/routing. No manual PMFDerivateCase call is made.
    die('<div dir="rtl" role="alert">ارجاع انجام نشد. فرمان یا نسخهٔ فراخوان معتبر نیست؛ فرم را دوباره باز کنید و در صورت تکرار، با مسئول سامانه تماس بگیرید.</div>');
}
