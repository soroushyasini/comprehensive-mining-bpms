<?php
require_once __DIR__ . '/_procurement_workflow.php';

function emcore_pw_actions()
{
    return ['list_events','create_event','mark_events_seen','list_notifications',
        'set_review_decision','prepare_workflow_action','get_workflow','record_submission',
        'get_native_context','cancel_stop_request'];
}

function emcore_pw_attachments()
{
    if (empty($_FILES['attachments'])) return [];
    $raw = $_FILES['attachments'];
    if (!is_array($raw['name'] ?? null) || count($raw['name']) > 20) throw new EmcoreHttpException(422, 'تعداد پیوست‌ها مجاز نیست.');
    $files = []; $total = 0;
    foreach ($raw['name'] as $index => $name) {
        $file = [];
        foreach (['name','tmp_name','size','error','type'] as $key) $file[$key] = $raw[$key][$index] ?? null;
        if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new EmcoreHttpException(422, 'بارگذاری پیوست کامل نشده است.');
        $file['name'] = emcore_procurement_clean_filename($name);
        $file['size'] = filesize($file['tmp_name']);
        if ($file['size'] <= 0 || $file['size'] > emcore_procurement_max_upload_bytes()) throw new EmcoreHttpException(422, 'اندازهٔ پیوست مجاز نیست.');
        $total += $file['size'];
        $file['sha256'] = hash_file('sha256', $file['tmp_name']);
        $files[] = $file;
    }
    if ($total > 52428800) throw new EmcoreHttpException(422, 'مجموع پیوست‌های اقدام نباید بیش از ۵۰ مگابایت باشد.');
    return $files;
}

function emcore_pw_add_files($db, $notice, $event, $actor, $files, &$stored)
{
    foreach ($files as $upload) {
        $file = emcore_procurement_store_upload_data($upload, $notice);
        $stored[] = $file;
        $db->prepare("INSERT INTO emcore_procurement_files
            (procurement_id,event_id,file_role,record_origin,original_filename,stored_filename,storage_path,extension,mime_type,file_size,sha256,uploaded_by_usr_uid)
            VALUES (:notice,:event,'activity_attachment','managed',:name,:stored,:path,:extension,:mime,:size,:hash,:actor)")
            ->execute([':notice'=>$notice,':event'=>$event,':name'=>$file['original_filename'],':stored'=>$file['stored_filename'],
                ':path'=>$file['storage_path'],':extension'=>$file['extension'],':mime'=>$file['mime_type'],':size'=>$file['file_size'],':hash'=>$file['sha256'],':actor'=>$actor]);
    }
}

function emcore_pw_dispatch($db, $action)
{
    if (!emcore_pw_enabled()) throw new EmcoreHttpException(404, 'گردش کار هنوز فعال نشده است.');
    $role = emcore_pw_user_role(); $actor = emcore_current_user()['USR_UID'];
    $scope = emcore_pw_scope();
    if ($action === 'list_notifications') {
        $page = emcore_procurement_page_value('page', 1);
        $where = "n.recipient_usr_uid=:recipient AND p.deleted_at IS NULL AND {$scope['sql']}";
        $params = $scope['params'] + [':recipient'=>$actor];
        $from = ' FROM emcore_procurement_notifications n JOIN emcore_procurement_events e ON e.id=n.event_id JOIN emcore_procurement_notices p ON p.id=e.procurement_id ';
        $q = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(n.read_at IS NULL),0) AS unread_count {$from} WHERE {$where}");
        $q->execute($params); $counts = $q->fetch();
        $q = $db->prepare("SELECT n.id,n.event_id,n.read_at,n.created_at,e.procurement_id,e.event_type,e.body,p.title {$from} WHERE {$where} ORDER BY n.id DESC LIMIT 25 OFFSET " . (($page-1)*25));
        $q->execute($params);
        emcore_json(['success'=>true,'data'=>$q->fetchAll(),'unread_count'=>(int)$counts['unread_count'],'total'=>(int)$counts['total']]);
    }
    if ($action === 'get_native_context') {
        // These values belong to the authenticated native case session, not POST.
        $app = $_SESSION['APPLICATION'] ?? ''; $index = (int)($_SESSION['INDEX'] ?? 0);
        $case = emcore_pw_case_state($db, $app); $task = $case['tasks'][0] ?? null;
        if (!$task || $task['USR_UID'] !== $actor || (int)$task['DEL_INDEX'] !== $index) throw new EmcoreHttpException(403, 'گام جاری به شما ارجاع نشده است.');
        $settings = emcore_pw_settings();
        if ($task['TAS_UID'] === $settings['activation_task'] && $role === 'manager') {
            $q = $db->prepare("SELECT c.request_id,p.id,p.title,JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.body')) AS body
                FROM emcore_procurement_commands c JOIN emcore_procurement_notices p ON p.id=c.procurement_id
                WHERE c.actor_usr_uid=:actor AND c.command_type='activate'
                AND c.expected_version=p.lock_version AND p.deleted_at IS NULL AND {$scope['sql']}
                AND ((c.command_state='prepared' AND NOT EXISTS (SELECT 1 FROM emcore_procurement_workflows w WHERE w.procurement_id=p.id))
                    OR (c.command_state='pending' AND c.app_uid=:native_app AND c.source_del_index=:native_index))
                ORDER BY p.id DESC LIMIT 100");
            $q->execute($scope['params'] + [':actor'=>$actor,':native_app'=>$app,':native_index'=>$index]);
            emcore_json(['success'=>true,'data'=>['stage'=>'activation','commands'=>$q->fetchAll()]]);
        }
        $q = $db->prepare('SELECT procurement_id FROM emcore_procurement_workflows WHERE app_uid=:app');
        $q->execute([':app'=>$app]); $id=$q->fetchColumn();
        if (!$id) throw new EmcoreHttpException(404, 'فراخوان مرتبط یافت نشد.');
        $row = emcore_pw_record($db,$id);
        $expected = $settings[$row['workflow_stage']==='result_review'?'result_task':'follow_up_task'];
        if ($task['TAS_UID'] !== $expected) throw new EmcoreHttpException(409,'گام پرونده با فراخوان همگام نیست.');
        $q=$db->prepare("SELECT request_id,payload,command_type FROM emcore_procurement_commands WHERE procurement_id=:id AND app_uid=:app AND actor_usr_uid=:actor AND source_del_index=:idx AND command_state='pending' LIMIT 1");
        $q->execute([':id'=>$id,':app'=>$app,':actor'=>$actor,':idx'=>$index]);$retry=$q->fetch();
        if($retry)$retry['payload']=json_decode($retry['payload'],true);
        emcore_json(['success'=>true,'data'=>['stage'=>$row['workflow_stage'],'notice'=>emcore_pw_snapshot($row),'workflow'=>emcore_pw_workflow($db,$row),'retry_command'=>$retry?:null]]);
    }
    $id = emcore_positive_id('id');
    $row = emcore_pw_record($db,$id);
    if ($action === 'get_workflow') emcore_json(['success'=>true,'data'=>emcore_pw_workflow($db,$row)]);
    if ($action === 'list_events') {
        $page = emcore_procurement_page_value('page',1); $size=emcore_procurement_page_value('page_size',25,50);
        $target=emcore_string('target_event_id',false,20);
        if ($target!==null) {
            if (!preg_match('/^[1-9][0-9]*$/',$target)) throw new EmcoreHttpException(422,'شناسهٔ رویداد معتبر نیست.');
            $q=$db->prepare('SELECT id FROM emcore_procurement_events WHERE procurement_id=:id AND id=:event');$q->execute([':id'=>$id,':event'=>$target]);
            if (!$q->fetchColumn()) throw new EmcoreHttpException(404,'رویداد یافت نشد.');
            $q=$db->prepare('SELECT COUNT(*) FROM emcore_procurement_events WHERE procurement_id=:id AND id>:event');$q->execute([':id'=>$id,':event'=>$target]);
            $page=(int)floor((int)$q->fetchColumn()/$size)+1;
        }
        $q=$db->prepare('SELECT COUNT(*) FROM emcore_procurement_events WHERE procurement_id=:id');$q->execute([':id'=>$id]);$total=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT e.id,e.procurement_id,e.actor_usr_uid,e.actor_role,e.event_type,e.body,e.corrects_event_id,e.created_at,e.before_data,e.after_data,
            CONCAT_WS(' ',u.USR_FIRSTNAME,u.USR_LASTNAME) AS author_name FROM emcore_procurement_events e LEFT JOIN USERS u ON u.USR_UID=e.actor_usr_uid
            WHERE e.procurement_id=:id ORDER BY e.id DESC LIMIT {$size} OFFSET " . (($page-1)*$size));
        $q->execute([':id'=>$id]); $events=$q->fetchAll();
        $fileMap=[];$readerMap=[];
        if($events) {
            $eventIds=array_column($events,'id');$placeholders=implode(',',array_fill(0,count($eventIds),'?'));
            $f=$db->prepare("SELECT event_id,id,original_filename,file_size,extension FROM emcore_procurement_files WHERE event_id IN ({$placeholders}) AND deleted_at IS NULL ORDER BY id");
            $f->execute($eventIds);foreach($f->fetchAll() as $file)$fileMap[$file['event_id']][]=$file;
            $r=$db->prepare("SELECT r.event_id,r.usr_uid,r.first_seen_at,r.last_seen_at,CONCAT_WS(' ',u.USR_FIRSTNAME,u.USR_LASTNAME) AS user_name
                FROM emcore_procurement_event_reads r LEFT JOIN USERS u ON u.USR_UID=r.usr_uid WHERE r.event_id IN ({$placeholders}) ORDER BY r.first_seen_at");
            $r->execute($eventIds);foreach($r->fetchAll() as $reader)$readerMap[$reader['event_id']][]=$reader;
        }
        foreach ($events as &$event) {
            $event['files']=$fileMap[$event['id']]??[];
            $event['readers']=$readerMap[$event['id']]??[];
            $event['before_data']=$event['before_data']?json_decode($event['before_data'],true):null;
            $event['after_data']=$event['after_data']?json_decode($event['after_data'],true):null;
        }
        unset($event);
        emcore_json(['success'=>true,'data'=>$events,'workflow'=>emcore_pw_workflow($db,$row),'pagination'=>['page'=>$page,'total'=>$total,'total_pages'=>max(1,(int)ceil($total/$size))]]);
    }
    emcore_require_csrf();
    if ($action === 'mark_events_seen') {
        $ids=json_decode(emcore_string('event_ids',true,2000),true);
        if (!is_array($ids) || !$ids || count($ids)>50) throw new EmcoreHttpException(422,'فهرست رویدادها معتبر نیست.');
        foreach ($ids as $event) if (!is_int($event) || $event<1) throw new EmcoreHttpException(422,'شناسهٔ رویداد معتبر نیست.');
        $ids=array_unique($ids); $db->beginTransaction();
        try {
            emcore_pw_record($db,$id,true);
            foreach ($ids as $event) {
                $q=$db->prepare('SELECT id FROM emcore_procurement_events WHERE id=:event AND procurement_id=:notice');$q->execute([':event'=>$event,':notice'=>$id]);
                if (!$q->fetchColumn()) throw new EmcoreHttpException(404,'رویداد یافت نشد.');
                $db->prepare("INSERT INTO emcore_procurement_event_reads (event_id,usr_uid,first_seen_at,last_seen_at) VALUES (:event,:actor,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen_at=NOW()")
                    ->execute([':event'=>$event,':actor'=>$actor]);
                $db->prepare('UPDATE emcore_procurement_notifications SET read_at=COALESCE(read_at,NOW()) WHERE event_id=:event AND recipient_usr_uid=:actor')->execute([':event'=>$event,':actor'=>$actor]);
            }
            $seenAt=$db->query("SELECT DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s')")->fetchColumn();
            $db->commit();emcore_json(['success'=>true,'seen_at'=>$seenAt]);
        } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    }
    $request=emcore_pw_request_id(); $stored=[];
    $db->beginTransaction();
    try {
        $row=emcore_pw_record($db,$id,true);
        if ($action==='create_event') {
            $body=emcore_string('body',true,5000);$corrects=emcore_string('corrects_event_id',false,20);
            $files=emcore_pw_attachments();
            $hash=hash('sha256',emcore_audit_json([$id,$actor,$body,$corrects,array_map(function($f){return [$f['name'],$f['size'],$f['sha256']];},$files)]));
            $q=$db->prepare('SELECT id,procurement_id,actor_usr_uid,after_data FROM emcore_procurement_events WHERE request_id=:request');$q->execute([':request'=>$request]);$existing=$q->fetch();
            if ($existing) {
                if ($existing['procurement_id']!=$id || $existing['actor_usr_uid']!==$actor || (json_decode($existing['after_data'],true)['publication_hash']??'')!==$hash) throw new EmcoreHttpException(409,'شناسهٔ انتشار برای دادهٔ دیگری استفاده شده است.');
                $db->commit();emcore_json(['success'=>true,'event_id'=>(int)$existing['id'],'replayed'=>true]);
            }
            if ($corrects!==null) {
                if(!preg_match('/^[1-9][0-9]*$/',$corrects))throw new EmcoreHttpException(422,'شناسه اصلاحیه معتبر نیست.');
                $q=$db->prepare('SELECT actor_usr_uid FROM emcore_procurement_events WHERE id=:event AND procurement_id=:notice');$q->execute([':event'=>$corrects,':notice'=>$id]);
                if($q->fetchColumn()!==$actor)throw new EmcoreHttpException(403,'اصلاحیه فقط برای نوشتهٔ خودتان مجاز است.');
            }
            $event=emcore_pw_event($db,$row,$actor,$role,$corrects?'correction':'note',$body,$request,null,['publication_hash'=>$hash],$corrects);
            emcore_pw_add_files($db,$id,$event,$actor,$files,$stored);
            $db->commit();$stored=[];emcore_json(['success'=>true,'event_id'=>$event]);
        }
        if ($action==='cancel_stop_request') {
            if($role!=='manager')throw new EmcoreHttpException(403,'فقط مدیر می‌تواند درخواست توقف را پس بگیرد.');
            $q=$db->prepare("SELECT * FROM emcore_procurement_commands WHERE request_id=:request AND procurement_id=:id FOR UPDATE");$q->execute([':request'=>$request,':id'=>$id]);$cmd=$q->fetch();
            if(!$cmd || $cmd['command_type']!=='request_stop' || $cmd['actor_usr_uid']!==$actor)throw new EmcoreHttpException(409,'درخواست توقف معتبر نیست.');
            if($cmd['command_state']==='abandoned'){$db->commit();emcore_json(['success'=>true]);}
            $case=emcore_pw_case_state($db,$row['app_uid']);
            if($cmd['command_state']!=='pending' || !in_array($case['APP_STATUS'],['TO_DO','DRAFT'],true))throw new EmcoreHttpException(409,'پرونده قبلاً تغییر کرده است.');
            $task=$case['tasks'][0]??null;
            if(!$task || (int)$task['DEL_INDEX']!==(int)$cmd['source_del_index'])throw new EmcoreHttpException(409,'گام پرونده قبلاً تغییر کرده است.');
            $db->prepare("UPDATE emcore_procurement_commands SET command_state='abandoned',completed_at=NOW() WHERE request_id=:request")->execute([':request'=>$request]);
            $db->prepare("UPDATE emcore_procurement_workflows SET sync_state='ready',last_sync_error=NULL WHERE procurement_id=:id")->execute([':id'=>$id]);
            emcore_pw_audit($db,$actor,$id,'procurement_stop_request',$cmd,['command_state'=>'abandoned'],$request);
            $db->commit();emcore_json(['success'=>true]);
        }
        $type=$action==='set_review_decision'?'not_interested':($action==='record_submission'?'record_submission':emcore_string('command_type',true,32));
        if(!in_array($type,['not_interested','record_submission','activate','propose_result','approve_result','return_follow_up','request_stop'],true))throw new EmcoreHttpException(422,'فرمان معتبر نیست.');
        if ($action==='prepare_workflow_action' && in_array($type,['not_interested','record_submission'],true))throw new EmcoreHttpException(422,'از عملیات رسمی همین تصمیم استفاده کنید.');
        $body=emcore_string('body',true,5000);$result=emcore_string('result',false,10);$version=emcore_positive_id('lock_version');
        $command=emcore_pw_prepare($db,$row,$actor,$role,$type,$body,$result,$request,$version);
        if(in_array($type,['not_interested','record_submission'],true) && $command['command_state']==='prepared') {
            $next=emcore_pw_next($row,$role,$type,$result);
            emcore_pw_business_update($db,$row,$next,$actor,$body,$request);
            emcore_pw_event($db,$row,$actor,$role,$type,$body,$request,emcore_pw_snapshot($row),$next);
            $db->prepare("UPDATE emcore_procurement_commands SET command_state='completed',completed_at=NOW() WHERE request_id=:request")->execute([':request'=>$request]);
            $command['command_state']='completed';
        }
        $db->commit();
        emcore_json(['success'=>true,'data'=>$command,'native_url'=>$type==='activate'?emcore_pw_settings()['start_url']:emcore_pw_case_url($row)]);
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        foreach($stored as $file)emcore_procurement_remove_failed_upload($file);
        throw $e;
    }
}
