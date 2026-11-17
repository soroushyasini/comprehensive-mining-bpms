<?php
require_once __DIR__.'/_contracts_exports.php';

function ct_archive_schema()
{
    if (!ct_one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emcore_contract_history_files'")) {
        ct_fail(503,'به‌روزرسانی پایگاه دادهٔ قراردادها کامل نیست؛ مهاجرت ۰۲۵ را اجرا کنید.');
    }
}

// A file, its independent logical document and the optional history link are
// committed together. All callers already hold the owning contract row lock.
function ct_archive_upload($input,$contract,$file,&$stored)
{
    $id=$contract['id'];
    $history=null;
    if (!empty($input['history_id'])) {
        $history=ct_row('history',ct_id($input['history_id']),$id,true);
        ct_version($history,$input['history_version'] ?? null);
    }
    $title=ct_text($input['title'] ?? null,250) ?? mb_substr(pathinfo($file['original_filename'],PATHINFO_FILENAME),0,250);
    $doc=ct_document_write('document_create',[
        'title'=>$title,'role_code'=>$input['role_code'] ?? ($history?'attachment':'other'),
        'signature_status'=>$input['signature_status'] ?? 'unknown'
    ],$contract,null,$stored);
    $version=ct_version_create($doc['id'],$id,$file,ct_text($input['change_note'] ?? null,500),$stored);
    // Independent-document upload expressly selects its first file for display.
    $doc=ct_change('documents',$doc,['current_version_id'=>$version['id']],$id,'document_upload');
    if ($history) {
        ct_create_record('history_files',['history_id'=>$history['id'],'version_id'=>$version['id'],
            'created_by'=>ct_actor(),'created_at'=>ct_now()],$id,false);
        $history=ct_change('history',$history,[],$id,'history_upload');
    }
    return ['document'=>$doc,'version'=>$version,'history'=>$history];
}

function ct_history_file_delete($input,$contract)
{
    $history=ct_row('history',ct_id($input['history_id'] ?? null),$contract['id'],true);
    ct_version($history,$input['lock_version'] ?? null);
    $link=ct_row('history_files',ct_id($input['id'] ?? null),null,true);
    if ((string)$link['history_id']!==(string)$history['id'] || $link['deleted_at']) ct_fail(404,'پیوست در این سابقه یافت نشد');
    if ($contract['hold_reason']!==null) ct_fail(409,'پرونده دارای منع امحا است');
    ct_delete_record('history_files',$link,$input['reason'] ?? null,$contract['id']);
    return ct_change('history',$history,[],$contract['id'],'history_file_delete');
}

function ct_history_files($rows,$contract)
{
    if (!$rows) return $rows;
    $ids=array_column($rows,'id');
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $files=ct_all("SELECT f.id,f.history_id,f.version_id,v.document_id,v.original_filename,v.mime_type,v.file_size,
        v.deleted_at AS version_deleted_at,d.title AS document_title
        FROM emcore_contract_history_files f
        JOIN emcore_contract_versions v ON v.id=f.version_id
        JOIN emcore_contract_documents d ON d.id=v.document_id
        WHERE f.deleted_at IS NULL AND d.contract_id=? AND f.history_id IN ($marks) ORDER BY f.id",array_merge([$contract],$ids));
    $grouped=[];
    foreach ($files as $file) $grouped[$file['history_id']][]=$file;
    foreach ($rows as &$row) $row['attachments']=$grouped[$row['id']] ?? [];
    unset($row);
    return $rows;
}

function ct_history_file_used($version)
{
    // Preserve links even when the containing history is soft-deleted.
    return (bool)ct_one('SELECT id FROM emcore_contract_history_files WHERE version_id=? AND deleted_at IS NULL LIMIT 1',[$version]);
}
