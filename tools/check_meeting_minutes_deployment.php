<?php
// Read-only CLI preflight; never opens a ProcessMaker session or changes data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMCORE_NATIVE_CONTEXT', true);
require_once __DIR__.'/../emcore_api/_minutes_storage.php';
$checks=[];
$options=getopt('', ['web-root:']);
$web=isset($options['web-root']) ? realpath($options['web-root']) : false;
$checks['web_root_supplied']=$web!==false && is_dir($web);
if ($checks['web_root_supplied']) $_SERVER['DOCUMENT_ROOT']=$web;
foreach (['pdo_mysql','mbstring','fileinfo','zip'] as $extension) $checks['extension_'.$extension]=extension_loaded($extension);
$checks['private_storage']=$checks['web_root_supplied'] && emcore_minutes_storage_ready();
$missing=[];
try {
    $db=emcore_db();
    $tables=['emcore_meeting_minutes','emcore_minutes_company_codes','emcore_minutes_counters','emcore_minutes_participants','emcore_minutes_files','emcore_minutes_download_log','emcore_audit_log'];
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    foreach ($tables as $table) { $s->execute([':table'=>$table]); $checks[$table]=(int)$s->fetchColumn()===1; }
    if ($checks['emcore_minutes_company_codes']) {
        $missing=$db->query('SELECT c.id,c.name_fa FROM emcore_companies c LEFT JOIN emcore_minutes_company_codes p ON p.company_id=c.id WHERE c.deleted_at IS NULL AND c.is_active=1 AND p.company_id IS NULL')->fetchAll();
        $checks['active_company_codes']=count($missing)===0;
    }
    emcore_minutes_date($db,'1405/07/12'); $checks['jalali_conversion']=true;
} catch (Throwable $e) { $checks['database_and_conversion']=false; }
$success=!in_array(false,$checks,true);
echo json_encode(['success'=>$success,'checks'=>$checks,'companies_without_codes'=>$missing],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($success ? 0 : 1);
