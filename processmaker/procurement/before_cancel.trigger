// Edit Process > trigger when canceling a case. PM runs it BEFORE cancellation.
if (!defined('EMCORE_NATIVE_CONTEXT')) define('EMCORE_NATIVE_CONTEXT', true);
require_once PATH_HTML . 'emcore_api/_procurement_workflow.php';
try {
    emcore_pw_native_cancel_guard(emcore_db(),@@APPLICATION,@@PROCESS);
} catch (Throwable $error) {
    error_log('EMCORE native cancel refused: '.$error->getMessage());
    die('<div dir="rtl" role="alert">لغو انجام نشد. مدیر باید ابتدا دلیل توقف پیگیری را در پنل فراخوان ثبت کند و سپس لغو بومی را تأیید کند.</div>');
}
