<?php
require_once __DIR__.'/_mine_history_storage.php';

$action=emcore_action(['lookups','summary','list','get','create','update','delete','upload','download','delete_file','category_create','category_update']);
$user=emcore_require_permission('mines','read');
$cap=['create'=>'create','category_create'=>'create','upload'=>'create','update'=>'update','category_update'=>'update','delete'=>'delete','delete_file'=>'delete'];
if (isset($cap[$action])) { emcore_require_permission('mines',$cap[$action]);emcore_require_csrf(); }
$db=emcore_db();

if ($action==='lookups') {
    emcore_json(['success'=>true,'csrf_token'=>emcore_csrf_token(),'permissions'=>emcore_module_permissions('mines'),
        'today'=>emcore_mh_today(),'storage_ready'=>emcore_mh_storage_ready(),'max_files'=>10,
        'max_upload_bytes'=>emcore_mh_storage_settings()['max_bytes'],
        'extensions'=>emcore_mh_extensions('attachment'),
        'categories'=>$db->query('SELECT id,title,sort_order,is_active,lock_version FROM emcore_mine_history_categories ORDER BY sort_order,id')->fetchAll()]);
}

if ($action==='category_create' || $action==='category_update') {
    $title=emcore_mh_text($_POST['title']??null,200,true);$key=emcore_mh_category_key($title);
    if ($key==='') throw new EmcoreHttpException(422,'عنوان دسته الزامی است');
    $order=emcore_mh_int($_POST['sort_order']??0,true);
    if ($order>2147483647) throw new EmcoreHttpException(422,'ترتیب نمایش نامعتبر است');
    $active=emcore_post_bool('is_active');$db->beginTransaction();
    try {
        $before=null;
        if ($action==='category_update') {
            $id=emcore_mh_int($_POST['id']??null);
            $before=emcore_mh_one('SELECT * FROM emcore_mine_history_categories WHERE id=? FOR UPDATE',[$id]);
            if (!$before) throw new EmcoreHttpException(404,'دسته یافت نشد');
            emcore_mh_version($before);
            $db->prepare('UPDATE emcore_mine_history_categories SET title=?,title_key=?,sort_order=?,is_active=?,updated_by_usr_uid=?,lock_version=lock_version+1 WHERE id=?')
                ->execute([$title,$key,$order,$active,$user['USR_UID'],$id]);
        } else {
            $db->prepare('INSERT INTO emcore_mine_history_categories(title,title_key,sort_order,is_active,created_by_usr_uid,updated_by_usr_uid) VALUES(?,?,?,?,?,?)')
                ->execute([$title,$key,$order,$active,$user['USR_UID'],$user['USR_UID']]);$id=(int)$db->lastInsertId();
        }
        $after=emcore_mh_one('SELECT * FROM emcore_mine_history_categories WHERE id=?',[$id]);
        emcore_audit('mines',$before?'update':'create','mine_history_category',$id,$before,$after);$db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') throw new EmcoreHttpException(409,'دسته‌ای با این عنوان وجود دارد');
        throw $e;
    }
    emcore_json(['success'=>true,'data'=>$after]);
}

$mineId=emcore_mh_int($_POST['mine_id']??null);emcore_mh_mine($mineId);
if ($action==='summary') {
    $s=$db->prepare('SELECT c.id,c.title,c.sort_order,c.is_active,COUNT(h.id) AS record_count FROM emcore_mine_history_categories c JOIN emcore_mine_history h ON h.category_id=c.id AND h.mine_id=? AND h.deleted_at IS NULL GROUP BY c.id ORDER BY c.sort_order,c.id');
    $s->execute([$mineId]);emcore_json(['success'=>true,'data'=>$s->fetchAll(),'mine'=>emcore_mh_mine($mineId)]);
}
if ($action==='list') {
    $category=emcore_mh_int($_POST['category_id']??null);$offset=emcore_mh_int($_POST['offset']??0,true);
    $s=$db->prepare('SELECT h.id FROM emcore_mine_history h WHERE mine_id=? AND category_id=? AND deleted_at IS NULL ORDER BY executed_on_en DESC,created_at DESC,id DESC LIMIT 26 OFFSET '.$offset);
    $s->execute([$mineId,$category]);$rows=$s->fetchAll();$more=count($rows)>25;
    $data=[];foreach (array_slice($rows,0,25) as $r) $data[]=emcore_mh_detail($r['id'],$mineId);
    emcore_json(['success'=>true,'data'=>$data,'has_more'=>$more,'next_offset'=>$offset+count($data)]);
}
if ($action==='get') emcore_json(['success'=>true,'data'=>emcore_mh_detail(emcore_mh_int($_POST['id']??null),$mineId)]);

// All writes lock the mine first, matching mine deletion, then the history row.
// This serializes attachment limits and protects the parent delete/create race.
$db->beginTransaction();$stored=null;
try {
    emcore_mh_mine($mineId,true);
    if ($action==='create') {
        $request=emcore_mh_request_id();
        $category=emcore_mh_int($_POST['category_id']??null);
        $description=emcore_mh_text($_POST['description']??null,5000,true);
        list($fa,$en)=emcore_mh_date($_POST['executed_on_fa']??null);
        $hash=hash('sha256',emcore_audit_json([$mineId,$category,$description,$fa]));
        $old=emcore_mh_one('SELECT * FROM emcore_mine_history WHERE created_by_usr_uid=? AND create_request_id=?',[$user['USR_UID'],$request]);
        if ($old) {
            if ($old['create_payload_hash']!==$hash || $old['deleted_at']!==null) throw new EmcoreHttpException(409,'شناسه درخواست قبلاً برای اطلاعات دیگری استفاده شده است');
            $data=emcore_mh_detail($old['id'],$mineId);$db->commit();emcore_json(['success'=>true,'data'=>$data,'replayed'=>true]);
        }
        $c=emcore_mh_one('SELECT * FROM emcore_mine_history_categories WHERE id=? FOR UPDATE',[$category]);
        if (!$c || !(int)$c['is_active']) throw new EmcoreHttpException(422,'یک دستهٔ فعال انتخاب کنید');
        $db->prepare('INSERT INTO emcore_mine_history(mine_id,category_id,description,executed_on_fa,executed_on_en,created_by_usr_uid,updated_by_usr_uid,create_request_id,create_payload_hash) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$mineId,$category,$description,$fa,$en,$user['USR_UID'],$user['USR_UID'],$request,$hash]);
        $id=(int)$db->lastInsertId();
        emcore_audit('mines','create','mine_history',$id,null,emcore_mh_record($id,$mineId));
        $data=emcore_mh_detail($id,$mineId);$db->commit();emcore_json(['success'=>true,'data'=>$data],201);
    }

    $id=emcore_mh_int($_POST['id']??null);$before=emcore_mh_record($id,$mineId,true);
    if ($action==='update' || $action==='delete') {
        emcore_mh_version($before);
        if ($action==='update') {
            $category=emcore_mh_int($_POST['category_id']??null);$description=emcore_mh_text($_POST['description']??null,5000,true);
            list($fa,$en)=emcore_mh_date($_POST['executed_on_fa']??null);
            $c=emcore_mh_one('SELECT * FROM emcore_mine_history_categories WHERE id=? FOR UPDATE',[$category]);
            if (!$c || (!(int)$c['is_active'] && $category!==(int)$before['category_id'])) throw new EmcoreHttpException(422,'یک دستهٔ فعال انتخاب کنید');
            $db->prepare('UPDATE emcore_mine_history SET category_id=?,description=?,executed_on_fa=?,executed_on_en=?,updated_by_usr_uid=?,edited_at=NOW(),lock_version=lock_version+1 WHERE id=?')
                ->execute([$category,$description,$fa,$en,$user['USR_UID'],$id]);
            $after=emcore_mh_record($id,$mineId);
        } else {
            $db->prepare('UPDATE emcore_mine_history SET deleted_at=NOW(),updated_by_usr_uid=?,lock_version=lock_version+1 WHERE id=?')->execute([$user['USR_UID'],$id]);
            $after=emcore_mh_one('SELECT * FROM emcore_mine_history WHERE id=?',[$id]);
        }
        emcore_audit('mines',$action,'mine_history',$id,$before,$after);
        $data=$action==='update'?emcore_mh_detail($id,$mineId):null;$db->commit();emcore_json(['success'=>true,'data'=>$data]);
    }

    if ($action==='upload') {
        $request=emcore_mh_request_id();$file=emcore_mh_validate_upload('attachment');
        $hash=hash('sha256',emcore_audit_json([$mineId,$id,$file['original_filename'],$file['sha256']]));
        $old=emcore_mh_one('SELECT * FROM emcore_mine_history_files WHERE uploaded_by_usr_uid=? AND upload_request_id=?',[$user['USR_UID'],$request]);
        if ($old) {
            if ($old['upload_payload_hash']!==$hash || $old['deleted_at']!==null) throw new EmcoreHttpException(409,'شناسه بارگذاری قبلاً استفاده شده است');
            $data=emcore_mh_public_file($old);$db->commit();emcore_json(['success'=>true,'data'=>$data,'lock_version'=>(int)$before['lock_version'],'replayed'=>true]);
        }
        $count=emcore_mh_one('SELECT COUNT(*) AS n FROM emcore_mine_history_files WHERE history_id=? AND deleted_at IS NULL',[$id]);
        if ((int)$count['n']>=10) throw new EmcoreHttpException(422,'هر سابقه حداکثر ۱۰ پیوست می‌تواند داشته باشد');
        emcore_mh_version($before);$stored=emcore_mh_store_upload($file,$id);
        $db->prepare('INSERT INTO emcore_mine_history_files(history_id,original_filename,stored_filename,storage_path,extension,mime_type,file_size,sha256,upload_request_id,upload_payload_hash,uploaded_by_usr_uid) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$stored['original_filename'],$stored['stored_filename'],$stored['storage_path'],$stored['extension'],$stored['mime_type'],$stored['file_size'],$stored['sha256'],$request,$hash,$user['USR_UID']]);
        $fileId=(int)$db->lastInsertId();$after=emcore_mh_one('SELECT * FROM emcore_mine_history_files WHERE id=?',[$fileId]);
        $db->prepare('UPDATE emcore_mine_history SET lock_version=lock_version+1,updated_by_usr_uid=? WHERE id=?')->execute([$user['USR_UID'],$id]);
        emcore_audit('mines','create','mine_history_file',$fileId,null,$after);
        $db->commit();emcore_json(['success'=>true,'data'=>emcore_mh_public_file($after),'lock_version'=>(int)$before['lock_version']+1],201);
    }

    $fileId=emcore_mh_int($_POST['file_id']??null);
    $file=emcore_mh_one('SELECT * FROM emcore_mine_history_files WHERE id=? AND history_id=? AND deleted_at IS NULL FOR UPDATE',[$fileId,$id]);
    if (!$file) throw new EmcoreHttpException(404,'پیوست یافت نشد');
    if ($action==='delete_file') {
        emcore_mh_version($before);
        $db->prepare('UPDATE emcore_mine_history_files SET deleted_at=NOW() WHERE id=?')->execute([$fileId]);
        $db->prepare('UPDATE emcore_mine_history SET lock_version=lock_version+1,updated_by_usr_uid=? WHERE id=?')->execute([$user['USR_UID'],$id]);
        emcore_audit('mines','delete','mine_history_file',$fileId,$file,emcore_mh_one('SELECT * FROM emcore_mine_history_files WHERE id=?',[$fileId]));
        $db->commit();emcore_json(['success'=>true]);
    }
    // Authorized read: no write-CSRF requirement. Open the file before committing
    // the log so missing files are not recorded as successful downloads.
    $path=emcore_mh_file_path($file['storage_path']);$stream=fopen($path,'rb');
    if ($stream===false) throw new EmcoreHttpException(404,'فایل در مخزن یافت نشد');
    $db->prepare('INSERT INTO emcore_mine_history_download_log(file_id,actor_usr_uid) VALUES(?,?)')->execute([$fileId,$user['USR_UID']]);
    $db->commit();session_write_close();header('Content-Type: '.$file['mime_type']);header('Content-Length: '.filesize($path));
    header("Content-Disposition: attachment; filename=\"attachment.".$file['extension']."\"; filename*=UTF-8''".rawurlencode($file['original_filename']));
    fpassthru($stream);fclose($stream);exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    emcore_mh_remove_failed_upload($stored);
    if ($e instanceof PDOException && $e->getCode()==='23000') throw new EmcoreHttpException(409,'اطلاعات وابسته تغییر کرده یا شناسهٔ درخواست قبلاً استفاده شده است؛ اطلاعات را بازخوانی کنید');
    throw $e;
}
