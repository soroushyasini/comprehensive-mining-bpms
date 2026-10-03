// AFTER ROUTING on each task; also safe when the scheduler retries it.
if (!defined('EMCORE_NATIVE_CONTEXT')) define('EMCORE_NATIVE_CONTEXT', true);
require_once PATH_HTML . 'emcore_api/_procurement_workflow.php';
try {
    if (@@PROCESS !== emcore_pw_settings()['process']) throw new RuntimeException('Unexpected process.');
    $emcoreDb = emcore_db();
    $emcoreQuery = $emcoreDb->prepare('SELECT procurement_id FROM emcore_procurement_workflows WHERE app_uid=:app');
    $emcoreQuery->execute([':app'=>@@APPLICATION]);
    $emcoreId = $emcoreQuery->fetchColumn();
    if ($emcoreId) emcore_pw_reconcile_one($emcoreDb,$emcoreId);
} catch (Throwable $error) {
    // The native route may already be committed. Do not attempt to undo it;
    // leave a pending/error record for the scheduled reconciler.
    error_log('EMCORE after-route reconciliation: '.$error->getMessage());
}
