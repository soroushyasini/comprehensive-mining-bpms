<?php
// Test-only panel fixture. NEVER deploy this router or expose it remotely.
if(PHP_SAPI!=='cli-server' || ($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'
    || getenv('EMCORE_DB_DSN')!=='mysql:host=127.0.0.1;port=33379;dbname=emcore_procurement_fixture;charset=utf8mb4') {http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=dirname(__DIR__,2);
if($path==='/panel') {
    header('Content-Type: text/html; charset=utf-8');
    // PM normally supplies jQuery. This isolated harness supplies that runtime.
    echo str_replace('<script>','<script src="/lib/jquery-1.11.js"></script><script>',file_get_contents($root.'/panels/emcore_procurement_notices_panel.html'));exit;
}
if($path==='/lib/jquery-1.11.js') {header('Content-Type: application/javascript');readfile(getenv('EMCORE_FIXTURE_JQUERY'));exit;}
if($path==='/lib/xlsx.full.min_2.js') {header('Content-Type: application/javascript');echo 'window.XLSX={write:function(workbook){window.__fixtureWorkbook=workbook;return new ArrayBuffer(1);}};';exit;}
session_start();
putenv('EMCORE_PROCUREMENT_WORKFLOW_OPERATOR_MANAGED=' . (($_SERVER['HTTP_X_EMCORE_FIXTURE_OPERATOR_MANAGED'] ?? '') === '1' ? 'true' : 'false'));
$role=$_SERVER['HTTP_X_EMCORE_FIXTURE_ACTOR']??'operator';
if($role==='anonymous')unset($_SESSION['USER_LOGGED']);
else $_SESSION['USER_LOGGED']=str_repeat($role==='manager'?'2':($role==='outsider'?'7':'1'),32);
if($path==='/__fixture/state') {
    require_once $root.'/emcore_api/_procurement_workflow.php';
    $db=emcore_db();$mode=$_POST['mode']??'counts';
    try {
        if($mode==='counts') {
            $counts=[];foreach(['events','notifications','commands','workflows','event_reads','files'] as $name)$counts[$name]=(int)$db->query('SELECT COUNT(*) FROM emcore_procurement_'.$name)->fetchColumn();
            emcore_json(['success'=>true,'data'=>$counts]);
        }
        if($mode==='schema') {
            $native=0;foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)if(in_array(strtoupper($table),['TASK','APPLICATION','APP_DELEGATION'],true))++$native;
            $column=$db->query("SHOW COLUMNS FROM emcore_procurement_workflows LIKE 'app_uid'")->fetch();
            emcore_json(['success'=>true,'native_table_count'=>$native,'app_uid_nullable'=>$column['Null']==='YES']);
        }
        if($mode==='historical_guarantee') {
            $db->prepare("UPDATE emcore_procurement_notices SET secondary_guarantee='سابقهٔ ضمانت' WHERE id=:id")->execute([':id'=>$_POST['id']]);emcore_json(['success'=>true]);
        }
        if($mode==='legacy_stage') {
            $db->prepare("UPDATE emcore_procurement_notices SET follow_up_stage=NULL,participation_status='won',interest_reason='دلیل تاریخی',workflow_history_only=1 WHERE id=:id")->execute([':id'=>$_POST['id']]);emcore_json(['success'=>true]);
        }
        if($mode==='operator_write_permission') {
            $db->prepare("UPDATE emcore_user_permissions SET can_update=:allowed WHERE usr_uid=:uid AND module_key='procurement_notices'")->execute([':allowed'=>($_POST['allowed']??'')==='1'?1:0,':uid'=>str_repeat('1',32)]);emcore_json(['success'=>true]);
        }
        if($mode==='native_history') {
            $db->prepare("INSERT INTO emcore_procurement_workflows (procurement_id,app_uid,workflow_stage) VALUES (:id,:app,'follow_up')")
                ->execute([':id'=>$_POST['id'],':app'=>bin2hex(random_bytes(16))]);emcore_json(['success'=>true]);
        }
        if($mode==='fail_notification') {
            $db->exec("CREATE TRIGGER fixture_fail_notification BEFORE INSERT ON emcore_procurement_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture injected notification failure'");
            emcore_json(['success'=>true]);
        }
        if($mode==='reset_failure') {
            $db->exec('DROP TRIGGER IF EXISTS fixture_fail_notification');emcore_json(['success'=>true]);
        }
        throw new RuntimeException('Unknown test operation.');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();emcore_json(['success'=>false,'error'=>$e->getMessage()],500);}
}
if($path==='/__fixture/disabled') {putenv('EMCORE_PROCUREMENT_WORKFLOW_ENABLED=false');require $root.'/emcore_api/emcore_procurement_notices.php';exit;}
if($path==='/emcore_api/emcore_procurement_notices.php') {require $root.'/emcore_api/emcore_procurement_notices.php';exit;}
if($path==='/emcore_api/emcore_procurement_analytics.php') {require $root.'/emcore_api/emcore_procurement_analytics.php';exit;}
http_response_code(404);
