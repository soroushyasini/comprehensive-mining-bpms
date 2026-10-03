// Edit Process > trigger when deleting a case. Linked cases must retain history.
if (!defined('EMCORE_NATIVE_CONTEXT')) define('EMCORE_NATIVE_CONTEXT', true);
require_once PATH_HTML . 'emcore_api/_procurement_workflow.php';
try {
    if (!emcore_pw_enabled() || @@PROCESS !== emcore_pw_settings()['process'] || emcore_pw_user_role() !== 'manager') throw new RuntimeException('Deletion role denied.');
    $q=emcore_db()->prepare('SELECT procurement_id FROM emcore_procurement_workflows WHERE app_uid=:app');
    $q->execute([':app'=>@@APPLICATION]);
    if ($q->fetchColumn()) throw new RuntimeException('Linked native case must not be deleted.');
} catch (Throwable $error) {
    error_log('EMCORE native deletion refused: '.$error->getMessage());
    die('<div dir="rtl" role="alert">حذف پروندهٔ مرتبط مجاز نیست؛ برای توقف، دلیل ثبت و از لغو رسمی استفاده کنید.</div>');
}
