<?php
require_once __DIR__.'/_contracts_archive.php';

$reads = ['lookups','list','get','category_list','action_list','document_list','version_list','extracted_text','download',
    'history_list','audit_list','evidence_list','source_list','source_download','import_preview','import_status','structure_preview','dashboard','export_list','export_case'];
$writes = [
    'create'=>['create'],'update'=>['update'],'verify'=>['update'],'archive'=>['update'],'unarchive'=>['update'],
    'delete'=>['delete'],'restore'=>['update','delete'],'party_create'=>['update'],'party_update'=>['update'],'mine_set'=>['update'],
    'category_create'=>['update'],'category_update'=>['update'],
    'action_create'=>['create'],'action_update'=>['update'],'action_complete'=>['update'],'action_reopen'=>['update'],
    'history_create'=>['create'],'history_update'=>['update'],'history_delete'=>['delete'],
    'document_upload'=>['update'],'history_file_delete'=>['delete','update'],
    'document_create'=>['update'],'document_update'=>['update'],'upload'=>['update'],'set_current_version'=>['update'],
    'delete_version'=>['delete'],'restore_version'=>['update','delete'],
    'amendment_create'=>['update'],'amendment_update'=>['update'],'amendment_apply'=>['update'],
    'obligation_create'=>['update'],'obligation_update'=>['update'],'obligation_delete'=>['delete'],
    'import_apply_batch'=>['create','update'],'source_attach'=>['update'],
    'split'=>['create','update'],'merge'=>['update'],'move_document'=>['update']
];
$action = emcore_action(array_merge($reads,array_keys($writes)));
emcore_require_permission('contracts','read');
if (!ct_one("SELECT module_key FROM emcore_modules WHERE module_key='contracts' AND is_active=1")) ct_fail(403,'ماژول قراردادها غیرفعال است');
ct_archive_schema();
// This connection is request-local. The shared audit helper uses NOW().
emcore_db()->exec("SET time_zone = '+00:00'");
if (isset($writes[$action])) {
    foreach ($writes[$action] as $capability) emcore_require_permission('contracts',$capability);
    emcore_require_csrf();
}
$input = isset($_POST['payload']) ? ct_decode($_POST['payload']) : $_POST;
unset($input['csrf_token'],$input['action']);
$requestId = $input['request_id'] ?? null;
unset($input['request_id']);
$needsReplay = ['document_upload','create','action_complete','upload','amendment_apply','import_apply_batch','source_attach','split','merge','move_document'];
if (isset($writes[$action]) && (substr($action,-7)==='_create' || in_array($action,$needsReplay,true)) && $requestId===null) {
    ct_fail(422,'شناسهٔ درخواست الزامی است');
}
$id = ct_id($input['contract_id'] ?? null,true);

if (!isset($writes[$action])) {
    switch ($action) {
        case 'lookups': $data=ct_lookups(); break;
        case 'list': $data=ct_list($input); break;
        case 'dashboard': $data=ct_dashboard(); break;
        case 'category_list':
            if ($id) ct_row('contracts',$id);
            $data=ct_categories($id);
            break;
        case 'action_list': $data=ct_action_list($input); break;
        case 'import_preview': $data=ct_import_preview(ct_hash($input['manifest_hash'] ?? null),$input); break;
        case 'import_status': $data=ct_import_status(ct_hash($input['manifest_hash'] ?? null),$input); break;
        case 'structure_preview':
            $data=ct_structure(ct_enum($input['operation'] ?? null,['split','merge','move_document']),$input,false);
            break;
        case 'export_list': ct_export_list($input); break;
        case 'source_download':
            $source = ct_row('sources',ct_id($input['id'] ?? null));
            if (!in_array($source['status'],['reference','ungrouped','container'],true) || !$source['storage_key']) ct_fail(404,'اصل مرجع قابل دریافت نیست');
            $item = ct_decode($source['metadata']);
            $path = ct_private_path($source['storage_key']);
            ct_log_download(null,null,'archive_source');
            ct_stream($path,$item['filename'],'application/octet-stream');
            break;
        default:
            $case=ct_row('contracts',ct_id($id));
            switch ($action) {
                case 'get': $data=ct_detail($id); break;
                case 'document_list': $data=ct_documents($id,$input); break;
                case 'version_list': $data=ct_versions($id,$input); break;
                case 'history_list': $data=ct_case_feed('history',$id,$input); break;
                case 'audit_list': $data=ct_case_feed('audit',$id,$input); break;
                case 'evidence_list': $data=ct_case_feed('evidence',$id,$input); break;
                case 'source_list': $data=ct_case_feed('sources',$id,$input); break;
                case 'extracted_text':
                    $version=ct_version_in_contract($input['version_id'] ?? null,$id);
                    $data=['text'=>$version['extracted_text']];
                    break;
                case 'download': ct_download($input,$id); break;
                case 'export_case': ct_export_case($id); break;
            }
    }
    emcore_json(['success'=>true,'data'=>ct_public($data),'csrf_token'=>emcore_csrf_token(),
        'permissions'=>emcore_module_permissions('contracts'),'today'=>ct_today(),'storage_ready'=>ct_storage_ready()]);
}

$file = in_array($action,['upload','document_upload'],true) ? ct_upload_file() : null;
[$data,$status] = ct_mutate($action,$requestId,$input,$file,static function (&$stored) use ($action,$id,$input,$file) {
    if ($action==='create') return ct_contract_write($action,$input,null);
    if ($action==='import_apply_batch') return ct_import_batch(ct_hash($input['manifest_hash'] ?? null),$input,$stored);
    if (in_array($action,['split','merge','move_document'],true)) return ct_structure($action,$input,true);
    $case = $id ? ct_row('contracts',$id,null,true,$action==='restore') : null;
    if ($case && $case['merged_into']) ct_fail(409,'این پرونده به پروندهٔ دیگری منتقل شده است');
    if (in_array($action,['category_create','category_update'],true)) return ct_category_write($action,$input,$id);
    if (!$case) ct_fail(422,'شناسهٔ پرونده الزامی است');
    if (in_array($action,['update','verify','archive','unarchive','delete','restore','party_create','party_update','mine_set'],true)) {
        ct_version($case,$input['lock_version'] ?? null);
        if ($action==='verify') return ct_verify($input,$case);
        $result=ct_contract_write($action,$input,$case);
        if (in_array($action,['party_create','party_update','mine_set'],true)) {
            // Make concurrent edits based on an older case detail fail predictably.
            $fresh=ct_row('contracts',$id);
            if ((int)$fresh['lock_version']===(int)$case['lock_version']) ct_change('contracts',$fresh,[],$id,$action);
        }
        return $result;
    }
    if (in_array($action,['document_create','document_update','upload','set_current_version','delete_version','restore_version'],true)) {
        return ct_document_write($action,$input,$case,$file,$stored);
    }
    if ($action==='document_upload') return ct_archive_upload($input,$case,$file,$stored);
    if ($action==='history_file_delete') return ct_history_file_delete($input,$case);
    if ($action==='source_attach') return ct_attach_source($input,$case,$stored);
    $handlers = ['action'=>'actions','history'=>'history','amendment'=>'amendments','obligation'=>'obligations'];
    [$prefix,$operation]=explode('_',$action,2);
    $entity=$handlers[$prefix];
    $before=null;
    if ($operation!=='create') {
        $before=ct_row($entity,ct_id($input['id'] ?? null),$id,true);
        ct_version($before,$input['lock_version'] ?? null);
    }
    switch ($prefix) {
        case 'action': return ct_action_write($action,$input,$case,$before);
        case 'history': return ct_history_write($action,$input,$id,$before);
        case 'amendment': return ct_amendment_write($action,$input,$case,$before);
        case 'obligation': return ct_obligation_write($action,$input,$id,$before);
    }
    ct_fail(400,'عملیات نامعتبر است');
});
emcore_json(['success'=>true,'data'=>$data],$status);
