<?php
// This initializer changes only EMCORE ownership, never USERS/PM task tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMCORE_NATIVE_CONTEXT', true);
require_once __DIR__ . '/../emcore_api/_procurement_workflow.php';
try {
    $settings=emcore_pw_settings();
    if (!$settings['enabled']) throw new RuntimeException('Set complete workflow configuration and enable it during a maintenance window first.');
    $db=emcore_db(); $db->beginTransaction();
    foreach (['operator','manager'] as $role) {
        $q=$db->prepare("SELECT USR_UID,USR_USERNAME,USR_FIRSTNAME,USR_LASTNAME FROM USERS WHERE USR_UID=:uid AND USR_STATUS='ACTIVE'");
        $q->execute([':uid'=>$settings[$role]]);$user=$q->fetch();
        if(!$user)throw new RuntimeException('Configured active user not found: '.$role);
        $q=$db->prepare("SELECT can_read,can_create,can_update FROM emcore_user_permissions WHERE usr_uid=:uid AND module_key='procurement_notices'");
        $q->execute([':uid'=>$user['USR_UID']]);$permission=$q->fetch();
        if (!$permission || !$permission['can_read'] || ($role==='operator' && (!$permission['can_create'] || !$permission['can_update']))) throw new RuntimeException('Grant required module permissions first: '.$role);
        $users[$role]=$user;
    }
    foreach (['activation_task','follow_up_task','result_task'] as $key) {
        $q=$db->prepare('SELECT TAS_UID FROM TASK WHERE TAS_UID=:task AND PRO_UID=:process');
        $q->execute([':task'=>$settings[$key],':process'=>$settings['process']]);
        if(!$q->fetchColumn())throw new RuntimeException('Task does not belong to configured process: '.$key);
    }
    $q=$db->prepare('SELECT COUNT(*) FROM emcore_procurement_notices WHERE (owner_usr_uid IS NOT NULL AND owner_usr_uid<>:owner) OR (manager_usr_uid IS NOT NULL AND manager_usr_uid<>:manager)');
    $q->execute([':owner'=>$settings['operator'],':manager'=>$settings['manager']]);
    if($q->fetchColumn())throw new RuntimeException('Existing participant assignments differ; do not overwrite automatically.');
    $q=$db->prepare('UPDATE emcore_procurement_notices SET owner_usr_uid=:owner,manager_usr_uid=:manager,updated_at=updated_at WHERE owner_usr_uid IS NULL OR manager_usr_uid IS NULL');
    $q->execute([':owner'=>$settings['operator'],':manager'=>$settings['manager']]);$count=$q->rowCount();
    $commit=in_array('--commit',$argv,true);
    if($commit)$db->commit();else $db->rollBack();
    echo json_encode(['mode'=>$commit?'commit':'dry-run','participants'=>$users,'assigned_count'=>$count,'historical_events_created'=>0,'cases_created'=>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e) {if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
