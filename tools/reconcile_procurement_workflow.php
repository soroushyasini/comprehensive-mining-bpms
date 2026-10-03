<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMCORE_NATIVE_CONTEXT', true);
require_once __DIR__ . '/../emcore_api/_procurement_workflow.php';
try {
    $settings=emcore_pw_settings();
    if(!$settings['enabled'])throw new RuntimeException('Procurement workflow is disabled.');
    $db=emcore_db();
    // A MySQL advisory lock prevents overlapping scheduler runs on any host.
    if((int)$db->query("SELECT GET_LOCK('emcore_procurement_workflow_reconcile',0)")->fetchColumn()!==1) {
        echo '{"state":"already_running"}'.PHP_EOL;exit;
    }
    try {
        $q=$db->prepare("SELECT p.id FROM emcore_procurement_notices p JOIN emcore_procurement_workflows w ON w.procurement_id=p.id
            WHERE p.deleted_at IS NULL AND p.owner_usr_uid=:owner AND p.manager_usr_uid=:manager
            AND (w.workflow_stage NOT IN ('completed','stopped') OR w.sync_state<>'ready') ORDER BY w.updated_at,p.id");
        $q->execute([':owner'=>$settings['operator'],':manager'=>$settings['manager']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $commit=in_array('--commit',$argv,true);$counts=['inspected'=>0,'completed'=>0,'pending'=>0,'unchanged'=>0,'errors'=>0];
        foreach($ids as $id) {
            ++$counts['inspected'];
            if(!$commit)continue;
            try {$state=emcore_pw_reconcile_one($db,$id);++$counts[$state];}
            catch(Throwable $error){++$counts['errors'];error_log('Procurement reconcile notice '.(int)$id.': '.$error->getMessage());}
        }
        echo json_encode(['mode'=>$commit?'commit':'dry-run','stats'=>$counts],JSON_UNESCAPED_UNICODE).PHP_EOL;
    } finally {$db->query("SELECT RELEASE_LOCK('emcore_procurement_workflow_reconcile')");}
    if($counts['errors'])exit(1);
} catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
