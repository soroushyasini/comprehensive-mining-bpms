<?php
// Test-only native PM simulator. NEVER deploy this router or expose it remotely.
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
if($path==='/lib/xlsx.full.min_2.js') {header('Content-Type: application/javascript');echo 'window.XLSX={write:function(){return new ArrayBuffer(1);}};';exit;}
session_start();
$role=$_SERVER['HTTP_X_EMCORE_FIXTURE_ACTOR']??'operator';
if($role==='anonymous')unset($_SESSION['USER_LOGGED']);
else $_SESSION['USER_LOGGED']=str_repeat($role==='manager'?'2':($role==='outsider'?'7':'1'),32);
if($path==='/__fixture/case') {
    define('EMCORE_NATIVE_CONTEXT',true);require_once $root.'/emcore_api/_procurement_workflow.php';
    $db=emcore_db();$app=$_POST['app_uid']??bin2hex(random_bytes(16));
    $q=$db->prepare("SELECT * FROM APP_DELEGATION WHERE APP_UID=:app AND DEL_THREAD_STATUS='OPEN' ORDER BY DEL_INDEX DESC LIMIT 1");$q->execute([':app'=>$app]);$task=$q->fetch();
    $mode=$_POST['mode']??'create';
    try {
        if($mode==='create'){
            $db->prepare("INSERT INTO APPLICATION VALUES (:app,:process,'DRAFT')")->execute([':app'=>$app,':process'=>str_repeat('3',32)]);
            $db->prepare("INSERT INTO APP_DELEGATION (APP_UID,DEL_INDEX,TAS_UID,USR_UID,DEL_THREAD_STATUS) VALUES (:app,1,:task,:actor,'OPEN')")->execute([':app'=>$app,':task'=>str_repeat('4',32),':actor'=>str_repeat('2',32)]);
            $_SESSION['APPLICATION']=$app;$_SESSION['INDEX']=1;
            emcore_json(['success'=>true,'app_uid'=>$app]);
        }
        if($mode==='before') {
            $_SESSION['APPLICATION']=$app;$_SESSION['INDEX']=$task['DEL_INDEX'];
            $result=emcore_pw_native_before_route($db,$_POST['request_id'],['app'=>$app,'process'=>str_repeat('3',32),'task'=>$task['TAS_UID'],'index'=>$task['DEL_INDEX'],'user'=>$_SESSION['USER_LOGGED']]);
            emcore_json(['success'=>true,'data'=>$result]);
        }
        if($mode==='route') {
            // Simulates PM's own committed routing; production code never does this.
            $stage=$_POST['stage'];
            if($stage==='stopped')emcore_pw_native_cancel_guard($db,$app,str_repeat('3',32));
            $db->beginTransaction();
            $db->prepare("UPDATE APP_DELEGATION SET DEL_THREAD_STATUS='CLOSED',DEL_FINISH_DATE=NOW() WHERE APP_UID=:app AND DEL_THREAD_STATUS='OPEN'")->execute([':app'=>$app]);
            $db->prepare('UPDATE APPLICATION SET APP_STATUS=:status WHERE APP_UID=:app')->execute([':status'=>$stage==='completed'?'COMPLETED':($stage==='stopped'?'CANCELLED':'TO_DO'),':app'=>$app]);
            if(in_array($stage,['follow_up','result_review'],true))$db->prepare("INSERT INTO APP_DELEGATION (APP_UID,DEL_INDEX,TAS_UID,USR_UID,DEL_THREAD_STATUS) VALUES (:app,:idx,:task,:actor,'OPEN')")->execute([':app'=>$app,':idx'=>(int)$task['DEL_INDEX']+1,':task'=>str_repeat($stage==='follow_up'?'5':'6',32),':actor'=>str_repeat($stage==='follow_up'?'1':'2',32)]);
            $db->commit();emcore_json(['success'=>true]);
        }
        if($mode==='reconcile') {
            $q=$db->prepare('SELECT procurement_id FROM emcore_procurement_workflows WHERE app_uid=:app');$q->execute([':app'=>$app]);
            emcore_json(['success'=>true,'state'=>emcore_pw_reconcile_one($db,$q->fetchColumn())]);
        }
        if($mode==='counts') {
            $counts=[];foreach(['events','notifications','commands','workflows','event_reads','files'] as $name)$counts[$name]=(int)$db->query('SELECT COUNT(*) FROM emcore_procurement_'.$name)->fetchColumn();
            emcore_json(['success'=>true,'data'=>$counts]);
        }
        if($mode==='historical_guarantee') {
            $db->prepare("UPDATE emcore_procurement_notices SET secondary_guarantee='سابقهٔ ضمانت' WHERE id=:id")->execute([':id'=>$_POST['id']]);emcore_json(['success'=>true]);
        }
        throw new RuntimeException('Unknown test operation.');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof EmcoreHttpException)emcore_json(['success'=>false,'error'=>$e->getMessage()],$e->status);emcore_json(['success'=>false,'error'=>$e->getMessage()],500);}
}
if($path==='/__fixture/disabled') {putenv('EMCORE_PROCUREMENT_WORKFLOW_ENABLED=false');require $root.'/emcore_api/emcore_procurement_notices.php';exit;}
if($path==='/emcore_api/emcore_procurement_notices.php') {require $root.'/emcore_api/emcore_procurement_notices.php';exit;}
if($path==='/emcore_api/emcore_procurement_analytics.php') {require $root.'/emcore_api/emcore_procurement_analytics.php';exit;}
http_response_code(404);
