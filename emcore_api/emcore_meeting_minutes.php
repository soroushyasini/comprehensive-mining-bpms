<?php

require_once __DIR__ . '/_minutes_storage.php';

const EMCORE_MINUTES_API_REVISION = '2026-10-04.2';
// Fail clearly when only the endpoint was deployed, or OPcache still runs an
// older helper. Do not allow a mixture of calendar/validation implementations.
if (!defined('EMCORE_MINUTES_DOMAIN_REVISION') || EMCORE_MINUTES_DOMAIN_REVISION !== EMCORE_MINUTES_API_REVISION) {
    error_log('EMCORE meeting minutes revision mismatch: deploy all three minutes API files and refresh the web PHP OPcache.');
    throw new EmcoreHttpException(503, 'نسخهٔ سرویس صورت جلسات کامل به‌روزرسانی نشده است؛ مدیر سامانه باید فایل‌های ماژول را همگام کند.');
}
if (!defined('EMCORE_NATIVE_CONTEXT')) header('X-EMCORE-Minutes-Revision: '.EMCORE_MINUTES_API_REVISION);

function emcore_minutes_select()
{
    return "SELECT m.id,m.company_id,m.company_name_snapshot,m.company_code_snapshot,m.record_origin,
        m.meeting_number,m.numbering_year,m.sequence_number,m.title,m.meeting_date_fa,m.meeting_date_en,
        m.start_time,m.end_time,m.ends_next_day,m.agenda,m.notes,m.metadata_complete,m.lock_version,
        m.created_by_usr_uid,m.updated_by_usr_uid,m.created_at,m.updated_at,
        (SELECT COUNT(*) FROM emcore_minutes_files f WHERE f.meeting_id=m.id AND f.file_role='scan' AND f.deleted_at IS NULL AND f.superseded_at IS NULL) scan_count,
        (SELECT COUNT(*) FROM emcore_minutes_files f WHERE f.meeting_id=m.id AND f.file_role='attachment' AND f.deleted_at IS NULL AND f.superseded_at IS NULL) attachment_count,
        (SELECT p.name_snapshot FROM emcore_minutes_participants p WHERE p.meeting_id=m.id AND p.is_chair=1 LIMIT 1) chair_name,
        (SELECT p.name_snapshot FROM emcore_minutes_participants p WHERE p.meeting_id=m.id AND p.is_secretary=1 LIMIT 1) secretary_name
        FROM emcore_meeting_minutes m";
}

function emcore_minutes_file_select()
{
    return 'SELECT id,meeting_id,file_role,original_filename,extension,mime_type,file_size,sha256,
        replaces_file_id,replacement_reason,superseded_at,deleted_at,uploaded_by_usr_uid,created_at FROM emcore_minutes_files';
}

function emcore_minutes_get($db, $id)
{
    $stmt=$db->prepare(emcore_minutes_select().' WHERE m.id=:id AND m.deleted_at IS NULL');
    $stmt->execute([':id'=>$id]); $row=$stmt->fetch();
    if (!$row) throw new EmcoreHttpException(404,'صورت‌جلسه یافت نشد');
    $row['scan_status']=(int)$row['scan_count'] > 0 ? 'archived' : 'awaiting_scan';
    $stmt=$db->prepare('SELECT person_key,usr_uid,name_snapshot,organization_snapshot,attendance,is_chair,is_secretary FROM emcore_minutes_participants WHERE meeting_id=:id ORDER BY id');
    $stmt->execute([':id'=>$id]); $row['participants']=$stmt->fetchAll();
    $stmt=$db->prepare(emcore_minutes_file_select().' WHERE meeting_id=:id AND deleted_at IS NULL ORDER BY id');
    $stmt->execute([':id'=>$id]); $row['files']=$stmt->fetchAll();
    return $row;
}

function emcore_minutes_lock($db, $id)
{
    $stmt=$db->prepare('SELECT id,company_id,record_origin,lock_version FROM emcore_meeting_minutes WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
    $stmt->execute([':id'=>$id]); $row=$stmt->fetch();
    if (!$row) throw new EmcoreHttpException(404,'صورت‌جلسه یافت نشد');
    return $row;
}

function emcore_minutes_version($row)
{
    if ((int)$row['lock_version'] !== emcore_minutes_int('lock_version')) throw new EmcoreHttpException(409,'اطلاعات این جلسه تغییر کرده است؛ ابتدا دوباره آن را باز کنید',['current_version'=>(int)$row['lock_version']]);
}

function emcore_minutes_lock_actor($db, $uid)
{
    // Serializes idempotency keys even when requests target different companies.
    $s=$db->prepare("SELECT USR_UID FROM USERS WHERE USR_UID=:uid AND USR_STATUS='ACTIVE' FOR UPDATE");
    $s->execute([':uid'=>$uid]);
    if (!$s->fetchColumn()) throw new EmcoreHttpException(401,'کاربر فعال یافت نشد');
}

function emcore_minutes_bump($db,$id,$uid)
{
    $s=$db->prepare('UPDATE emcore_meeting_minutes SET lock_version=lock_version+1,updated_by_usr_uid=:uid,updated_at=NOW() WHERE id=:id');
    $s->execute([':uid'=>$uid,':id'=>$id]);
}

function emcore_minutes_save_people($db,$id,$people)
{
    $db->prepare('DELETE FROM emcore_minutes_participants WHERE meeting_id=:id')->execute([':id'=>$id]);
    $s=$db->prepare('INSERT INTO emcore_minutes_participants (meeting_id,person_key,usr_uid,name_snapshot,organization_snapshot,attendance,is_chair,is_secretary)
        VALUES (:meeting_id,:person_key,:usr_uid,:name_snapshot,:organization_snapshot,:attendance,:is_chair,:is_secretary)');
    foreach ($people as $p) { $p['meeting_id']=$id; $s->execute($p); }
}

function emcore_minutes_transaction($db,$callback)
{
    $db->beginTransaction();
    try { $result=$callback(); $db->commit(); return $result; }
    catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') throw new EmcoreHttpException(409,'شماره یا کد قبلاً ثبت شده است');
        if ($e instanceof PDOException && $e->getCode()==='40001') throw new EmcoreHttpException(409,'ثبت هم‌زمان رخ داده است؛ دوباره تلاش کنید');
        throw $e;
    }
}

function emcore_minutes_filters($db)
{
    $where=['m.deleted_at IS NULL']; $args=[];
    if (!empty($_POST['company_id'])) { $where[]='m.company_id=:company'; $args[':company']=emcore_minutes_int('company_id'); }
    $search=emcore_minutes_text($_POST['search'] ?? null,500);
    if ($search !== null) {
        $where[]="(m.title LIKE :title_search OR m.agenda LIKE :agenda_search OR m.number_key LIKE :number_search)";
        $args[':title_search']='%'.$search.'%'; $args[':agenda_search']='%'.$search.'%';
        $args[':number_search']='%'.emcore_minutes_number_key($search).'%';
    }
    foreach (['date_from'=>'>=','date_to'=>'<='] as $key=>$operator) {
        list($fa,$en)=emcore_minutes_date($db,$_POST[$key] ?? null);
        if ($en !== null) { $where[]='m.meeting_date_en '.$operator.' :'.$key; $args[':'.$key]=$en; }
    }
    if (isset($args[':date_from'],$args[':date_to']) && $args[':date_from']>$args[':date_to']) throw new EmcoreHttpException(422,'بازه تاریخ معکوس است');
    if (!empty($_POST['record_origin'])) { $where[]='m.record_origin=:origin'; $args[':origin']=emcore_minutes_enum('record_origin',['managed','legacy']); }
    if (isset($_POST['metadata_complete']) && $_POST['metadata_complete'] !== '') {
        $where[]='m.metadata_complete=:complete'; $args[':complete']=emcore_minutes_bool('metadata_complete');
    }
    if (!empty($_POST['person_key'])) {
        $key=emcore_minutes_text($_POST['person_key'],66,true);
        if (!preg_match('/^(u:[A-Za-z0-9]{32}|e:[a-f0-9]{64})$/',$key)) throw new EmcoreHttpException(422,'فیلتر شخص نامعتبر است');
        $where[]='EXISTS (SELECT 1 FROM emcore_minutes_participants p WHERE p.meeting_id=m.id AND p.person_key=:person)'; $args[':person']=$key;
    }
    if (!empty($_POST['scan_status'])) {
        $status=emcore_minutes_enum('scan_status',['archived','awaiting_scan']);
        $where[]=($status==='awaiting_scan' ? 'NOT ' : '')."EXISTS (SELECT 1 FROM emcore_minutes_files f WHERE f.meeting_id=m.id AND f.file_role='scan' AND f.deleted_at IS NULL AND f.superseded_at IS NULL)";
    }
    return [implode(' AND ',$where),$args];
}

if (isset($_POST['action']) && !is_string($_POST['action'])) throw new EmcoreHttpException(400,'action نامعتبر');
$action=emcore_action(['lookups','search_users','search_participants','number_preview','list','get','create','update',
    'upload_file','replace_file','download_file','delete_file','delete','set_company_code']);
$capabilities=['lookups'=>'read','search_users'=>'read','search_participants'=>'read','number_preview'=>'read','list'=>'read','get'=>'read',
    'create'=>'create','update'=>'update','upload_file'=>'update','replace_file'=>'update','download_file'=>'read','delete_file'=>'delete','delete'=>'delete'];
$actor=$action==='set_company_code' ? emcore_require_permission('authorization','update') : emcore_require_permission(EMCORE_MINUTES_MODULE,$capabilities[$action]);
$db=emcore_db();
if (!in_array($action,['lookups','search_users','search_participants','number_preview','list','get','download_file'],true)) emcore_require_csrf();
$token=emcore_csrf_token();
// Long uploads/downloads and concurrent writes must not hold the PM session lock.
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();

if ($action==='lookups') {
    $companies=$db->query('SELECT c.id,c.name_fa,c.is_active,p.code,p.locked_at FROM emcore_companies c LEFT JOIN emcore_minutes_company_codes p ON p.company_id=c.id WHERE c.deleted_at IS NULL ORDER BY c.name_fa')->fetchAll();
    emcore_json(['success'=>true,'csrf_token'=>$token,'permissions'=>emcore_module_permissions(EMCORE_MINUTES_MODULE),
        'data'=>['release'=>['api'=>EMCORE_MINUTES_API_REVISION,'domain'=>EMCORE_MINUTES_DOMAIN_REVISION,'calendar'=>'php','optional_meeting_times'=>true],
            'companies'=>$companies,'today_gregorian'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d'),
            'storage_ready'=>emcore_minutes_storage_ready(),'max_upload_bytes'=>emcore_minutes_storage_settings()['max_bytes'],
            'scan_extensions'=>emcore_minutes_extensions('scan'),'attachment_extensions'=>emcore_minutes_extensions('attachment'),
            'can_manage_codes'=>emcore_module_permissions('authorization')['can_update']]]);
}

if ($action==='search_users') {
    $search=emcore_minutes_text($_POST['search'] ?? '',200) ?? '';
    $s=$db->prepare("SELECT USR_UID usr_uid,COALESCE(NULLIF(TRIM(CONCAT_WS(' ',USR_FIRSTNAME,USR_LASTNAME)),''),USR_USERNAME) name_snapshot,
        USR_USERNAME username FROM USERS WHERE USR_STATUS='ACTIVE' AND (USR_FIRSTNAME LIKE :first OR USR_LASTNAME LIKE :last OR USR_USERNAME LIKE :username)
        ORDER BY USR_FIRSTNAME,USR_LASTNAME,USR_UID LIMIT 30");
    $s->execute([':first'=>'%'.$search.'%',':last'=>'%'.$search.'%',':username'=>'%'.$search.'%']);
    emcore_json(['success'=>true,'data'=>$s->fetchAll()]);
}

if ($action==='search_participants') {
    $search=emcore_minutes_text($_POST['search'] ?? '',200) ?? '';
    $s=$db->prepare('SELECT p.person_key,MAX(p.name_snapshot) name_snapshot,MAX(p.organization_snapshot) organization_snapshot
        FROM emcore_minutes_participants p JOIN emcore_meeting_minutes m ON m.id=p.meeting_id AND m.deleted_at IS NULL
        WHERE p.name_snapshot LIKE :search GROUP BY p.person_key ORDER BY name_snapshot LIMIT 30');
    $s->execute([':search'=>'%'.$search.'%']); emcore_json(['success'=>true,'data'=>$s->fetchAll()]);
}

if ($action==='number_preview') {
    $company=emcore_minutes_int('company_id'); list($fa,$en)=emcore_minutes_date($db,$_POST['meeting_date_fa'] ?? null);
    if ($fa===null) throw new EmcoreHttpException(422,'تاریخ جلسه لازم است');
    $year=(int)substr($fa,0,4);
    $s=$db->prepare('SELECT p.code,COALESCE(n.next_sequence,1) next_sequence FROM emcore_minutes_company_codes p
        JOIN emcore_companies c ON c.id=p.company_id AND c.deleted_at IS NULL AND c.is_active=1
        LEFT JOIN emcore_minutes_counters n ON n.company_id=p.company_id AND n.jalali_year=:year WHERE p.company_id=:company');
    $s->execute([':year'=>$year,':company'=>$company]); $p=$s->fetch();
    if (!$p) throw new EmcoreHttpException(422,'کد این شرکت تعریف نشده یا شرکت غیرفعال است');
    $available=emcore_minutes_available_number($db,$company,$p['code'],$year,$p['next_sequence']);
    emcore_json(['success'=>true,'data'=>['meeting_number'=>$available['number'],'provisional'=>true]]);
}

if ($action==='list') {
    list($where,$args)=emcore_minutes_filters($db);
    $base=emcore_minutes_select().' WHERE '.$where;
    $s=$db->prepare('SELECT COUNT(*) total,COALESCE(SUM(scan_count>0),0) archived,COALESCE(SUM(scan_count=0),0) awaiting_scan,
        COALESCE(SUM(metadata_complete=0),0) incomplete,COALESCE(SUM(record_origin=\'legacy\'),0) legacy FROM ('.$base.') filtered');
    $s->execute($args); $summary=$s->fetch();
    $page=emcore_minutes_int('page',1); $size=emcore_minutes_int('page_size',25,100);
    $pages=max(1,(int)ceil($summary['total']/$size)); $page=min($page,$pages);
    $sort=emcore_minutes_enum('sort_by',['id','meeting_date_en','meeting_number','title','company_name_snapshot','chair_name','secretary_name','scan_count','attachment_count'],'id');
    $direction=strtoupper(emcore_minutes_enum('sort_order',['asc','desc'],'desc'));
    $s=$db->prepare($base.' ORDER BY '.$sort.' '.$direction.',m.id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size));
    $s->execute($args); $rows=$s->fetchAll();
    foreach ($rows as &$r) $r['scan_status']=(int)$r['scan_count']>0 ? 'archived' : 'awaiting_scan'; unset($r);
    emcore_json(['success'=>true,'data'=>$rows,'summary'=>$summary,'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>(int)$summary['total'],'total_pages'=>$pages],
        'csrf_token'=>$token,'permissions'=>emcore_module_permissions(EMCORE_MINUTES_MODULE)]);
}

if ($action==='get') emcore_json(['success'=>true,'data'=>emcore_minutes_get($db,emcore_minutes_int('id'))]);

if ($action==='create') {
    $request=emcore_minutes_request_id(); $company=emcore_minutes_int('company_id');
    $origin=emcore_minutes_enum('record_origin',['managed','legacy']);
    // Fingerprint the submitted values before resolving live USERS. Replays remain
    // stable after a user's name/status changes, but changed payloads are rejected.
    $raw=[]; foreach (['company_id','record_origin','meeting_number','title','meeting_date_fa','start_time','end_time','ends_next_day','agenda','notes','participants'] as $key) $raw[$key]=$_POST[$key] ?? null;
    $hash=emcore_minutes_hash($raw);
    $result=emcore_minutes_transaction($db,function() use($db,$actor,$request,$company,$origin,$hash) {
        emcore_minutes_lock_actor($db,$actor['USR_UID']);
        $s=$db->prepare('SELECT id,create_payload_hash,deleted_at FROM emcore_meeting_minutes WHERE created_by_usr_uid=:uid AND create_request_id=:request');
        $s->execute([':uid'=>$actor['USR_UID'],':request'=>$request]); $prior=$s->fetch();
        if ($prior) {
            if (!hash_equals($prior['create_payload_hash'],$hash) || $prior['deleted_at']!==null) throw new EmcoreHttpException(409,'این شناسه درخواست قبلاً با اطلاعات دیگری استفاده شده است');
            return ['id'=>(int)$prior['id'],'replayed'=>true];
        }
        $input=emcore_minutes_input($db,$origin);
        $s=$db->prepare('SELECT id,name_fa,is_active FROM emcore_companies WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
        $s->execute([':id'=>$company]); $c=$s->fetch();
        if (!$c || ($origin==='managed' && (int)$c['is_active']!==1)) throw new EmcoreHttpException(422,'شرکت قابل انتخاب نیست');
        $s=$db->prepare('SELECT code FROM emcore_minutes_company_codes WHERE company_id=:id FOR UPDATE');
        $s->execute([':id'=>$company]); $code=$s->fetchColumn() ?: null;
        $year=null; $sequence=null;
        if ($origin==='managed') {
            if ($code===null) throw new EmcoreHttpException(422,'ابتدا کد شرکت را تعریف کنید');
            $year=(int)substr($input['meeting_date_fa'],0,4);
            $s=$db->prepare('INSERT INTO emcore_minutes_counters (company_id,jalali_year,next_sequence) VALUES (:company,:year,1) ON DUPLICATE KEY UPDATE company_id=company_id');
            $s->execute([':company'=>$company,':year'=>$year]);
            $s=$db->prepare('SELECT next_sequence FROM emcore_minutes_counters WHERE company_id=:company AND jalali_year=:year FOR UPDATE');
            $s->execute([':company'=>$company,':year'=>$year]); $sequence=$s->fetchColumn();
            $available=emcore_minutes_available_number($db,$company,$code,$year,$sequence);
            $number=$available['number']; $sequence=$available['sequence'];
            $s=$db->prepare('UPDATE emcore_minutes_counters SET next_sequence=:next WHERE company_id=:company AND jalali_year=:year');
            $s->execute([':next'=>(int)$sequence+1,':company'=>$company,':year'=>$year]);
            $db->prepare('UPDATE emcore_minutes_company_codes SET locked_at=COALESCE(locked_at,NOW()) WHERE company_id=:id')->execute([':id'=>$company]);
        } else $number=emcore_minutes_text($_POST['meeting_number'] ?? null,100,true);
        $people=$input['participants']; unset($input['participants']);
        $values=array_merge($input,['company_id'=>$company,'company_name_snapshot'=>$c['name_fa'],'company_code_snapshot'=>$code,
            'record_origin'=>$origin,'meeting_number'=>$number,'number_key'=>emcore_minutes_number_key($number),'numbering_year'=>$year,
            'sequence_number'=>$sequence,'create_request_id'=>$request,'create_payload_hash'=>$hash,
            'created_by_usr_uid'=>$actor['USR_UID'],'updated_by_usr_uid'=>$actor['USR_UID']]);
        $columns=array_keys($values);
        $s=$db->prepare('INSERT INTO emcore_meeting_minutes ('.implode(',',$columns).') VALUES (:'.implode(',:',$columns).')'); $s->execute($values);
        $id=(int)$db->lastInsertId(); emcore_minutes_save_people($db,$id,$people);
        emcore_audit(EMCORE_MINUTES_MODULE,'create','meeting_minutes',$id,null,emcore_minutes_get($db,$id));
        return ['id'=>$id,'replayed'=>false];
    });
    emcore_json(['success'=>true,'replayed'=>$result['replayed'],'data'=>emcore_minutes_get($db,$result['id'])],$result['replayed'] ? 200 : 201);
}

if ($action==='update' || $action==='delete') {
    $id=emcore_minutes_int('id');
    $result=emcore_minutes_transaction($db,function() use($db,$id,$actor,$action) {
        $row=emcore_minutes_lock($db,$id); emcore_minutes_version($row); $before=emcore_minutes_get($db,$id);
        if ($action==='delete') {
            $s=$db->prepare('UPDATE emcore_meeting_minutes SET deleted_at=NOW(),lock_version=lock_version+1,updated_by_usr_uid=:uid WHERE id=:id');
            $s->execute([':uid'=>$actor['USR_UID'],':id'=>$id]);
            emcore_audit(EMCORE_MINUTES_MODULE,'delete','meeting_minutes',$id,$before,['id'=>$id,'deleted'=>true]); return null;
        }
        foreach (['company_id','record_origin','meeting_number'] as $key) {
            if (isset($_POST[$key]) && (string)$_POST[$key] !== (string)$before[$key]) throw new EmcoreHttpException(422,'شرکت، نوع ثبت و شماره صادرشده قابل تغییر نیستند');
        }
        $input=emcore_minutes_input($db,$row['record_origin'],$before['participants']); $people=$input['participants']; unset($input['participants']);
        $sets=[]; foreach(array_keys($input) as $key) $sets[]=$key.'=:'.$key;
        $input['id']=$id;
        $db->prepare('UPDATE emcore_meeting_minutes SET '.implode(',',$sets).' WHERE id=:id')->execute($input);
        emcore_minutes_save_people($db,$id,$people); emcore_minutes_bump($db,$id,$actor['USR_UID']);
        $after=emcore_minutes_get($db,$id); emcore_audit(EMCORE_MINUTES_MODULE,'update','meeting_minutes',$id,$before,$after); return $after;
    });
    emcore_json(['success'=>true,'data'=>$result]);
}

if ($action==='upload_file' || $action==='replace_file') {
    $id=emcore_minutes_int('id'); $request=emcore_minutes_request_id();
    $role=emcore_minutes_enum('file_role',['scan','attachment']);
    $replace=$action==='replace_file' ? emcore_minutes_int('file_id') : null;
    if ($replace!==null && $role!=='scan') throw new EmcoreHttpException(422,'جایگزینی نسخه برای اسکن صورت‌جلسه است');
    $reason=$replace!==null ? emcore_minutes_text($_POST['replacement_reason'] ?? null,1000,true) : null;
    $candidate=emcore_minutes_validate_upload($role);
    $hash=emcore_minutes_hash([$id,$role,$replace,$reason,$candidate['original_filename'],$candidate['sha256']]);
    $stored=null;
    try {
        $result=emcore_minutes_transaction($db,function() use($db,$id,$request,$role,$replace,$reason,$candidate,$hash,$actor,&$stored) {
            emcore_minutes_lock_actor($db,$actor['USR_UID']); $meeting=emcore_minutes_lock($db,$id);
            $s=$db->prepare('SELECT id,upload_payload_hash FROM emcore_minutes_files WHERE uploaded_by_usr_uid=:uid AND upload_request_id=:request');
            $s->execute([':uid'=>$actor['USR_UID'],':request'=>$request]); $prior=$s->fetch();
            if ($prior) {
                if (!hash_equals($prior['upload_payload_hash'],$hash)) throw new EmcoreHttpException(409,'شناسه بارگذاری قبلاً با فایل دیگری استفاده شده است');
                return ['file_id'=>(int)$prior['id'],'replayed'=>true];
            }
            emcore_minutes_version($meeting); $old=null;
            if ($replace!==null) {
                $s=$db->prepare(emcore_minutes_file_select()." WHERE id=:file AND meeting_id=:meeting AND file_role='scan' AND deleted_at IS NULL AND superseded_at IS NULL FOR UPDATE");
                $s->execute([':file'=>$replace,':meeting'=>$id]); $old=$s->fetch();
                if (!$old) throw new EmcoreHttpException(409,'نسخه انتخاب‌شده دیگر اسکن فعال این جلسه نیست');
            }
            $stored=emcore_minutes_store_upload($candidate,$id);
            $v=['meeting_id'=>$id,'file_role'=>$role,'replaces_file_id'=>$replace,'replacement_reason'=>$reason,'upload_request_id'=>$request,
                'upload_payload_hash'=>$hash,'uploaded_by_usr_uid'=>$actor['USR_UID']];
            foreach(['original_filename','stored_filename','storage_path','extension','mime_type','file_size','sha256'] as $key) $v[$key]=$stored[$key];
            $keys=array_keys($v); $s=$db->prepare('INSERT INTO emcore_minutes_files ('.implode(',',$keys).') VALUES (:'.implode(',:',$keys).')'); $s->execute($v);
            $fileId=(int)$db->lastInsertId();
            if ($replace!==null) $db->prepare('UPDATE emcore_minutes_files SET superseded_at=NOW() WHERE id=:id')->execute([':id'=>$replace]);
            emcore_minutes_bump($db,$id,$actor['USR_UID']);
            $s=$db->prepare(emcore_minutes_file_select().' WHERE id=:id'); $s->execute([':id'=>$fileId]);
            emcore_audit(EMCORE_MINUTES_MODULE,$replace!==null ? 'update' : 'create','minutes_file',$fileId,$old,$s->fetch(),['meeting_id'=>$id,'operation'=>$replace!==null ? 'replace_file' : 'upload_file']);
            return ['file_id'=>$fileId,'replayed'=>false];
        });
    } catch (Throwable $e) { emcore_minutes_remove_failed_upload($stored); throw $e; }
    emcore_json(['success'=>true,'file_id'=>$result['file_id'],'replayed'=>$result['replayed'],'data'=>emcore_minutes_get($db,$id)],$result['replayed'] ? 200 : 201);
}

if ($action==='delete_file') {
    $id=emcore_minutes_int('id'); $fileId=emcore_minutes_int('file_id');
    $result=emcore_minutes_transaction($db,function() use($db,$id,$fileId,$actor) {
        emcore_minutes_version(emcore_minutes_lock($db,$id));
        $s=$db->prepare(emcore_minutes_file_select().' WHERE id=:file AND meeting_id=:meeting AND deleted_at IS NULL FOR UPDATE');
        $s->execute([':file'=>$fileId,':meeting'=>$id]); $before=$s->fetch();
        if (!$before) throw new EmcoreHttpException(404,'فایل یافت نشد');
        $db->prepare('UPDATE emcore_minutes_files SET deleted_at=NOW() WHERE id=:id')->execute([':id'=>$fileId]);
        emcore_minutes_bump($db,$id,$actor['USR_UID']);
        emcore_audit(EMCORE_MINUTES_MODULE,'delete','minutes_file',$fileId,$before,['id'=>$fileId,'deleted'=>true],['meeting_id'=>$id]);
        return emcore_minutes_get($db,$id);
    }); emcore_json(['success'=>true,'data'=>$result]);
}

if ($action==='download_file') {
    $fileId=emcore_minutes_int('file_id');
    // Lock the parent while checking visibility and recording the download.
    // No credentials, public URLs or physical storage paths leave this endpoint.
    $file=emcore_minutes_transaction($db,function() use($db,$fileId,$actor) {
        $s=$db->prepare('SELECT f.meeting_id FROM emcore_minutes_files f WHERE f.id=:id'); $s->execute([':id'=>$fileId]);
        $meeting=$s->fetchColumn(); if (!$meeting) throw new EmcoreHttpException(404,'فایل یافت نشد');
        emcore_minutes_lock($db,$meeting);
        $s=$db->prepare('SELECT original_filename,storage_path,mime_type,file_size,sha256 FROM emcore_minutes_files WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
        $s->execute([':id'=>$fileId]); $file=$s->fetch(); if (!$file) throw new EmcoreHttpException(404,'فایل یافت نشد');
        $file['absolute_path']=emcore_minutes_file_path($file['storage_path']);
        if (!hash_equals($file['sha256'],hash_file('sha256',$file['absolute_path']))) throw new EmcoreHttpException(409,'صحت فایل مخزن قابل تأیید نیست');
        $s=$db->prepare('INSERT INTO emcore_minutes_download_log (file_id,actor_usr_uid,ip_address,user_agent) VALUES (:file,:uid,:ip,:agent)');
        $s->execute([':file'=>$fileId,':uid'=>$actor['USR_UID'],':ip'=>substr($_SERVER['REMOTE_ADDR'] ?? '',0,45),':agent'=>mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255,'UTF-8')]);
        return $file;
    });
    header('Content-Type: '.$file['mime_type']); header('Content-Length: '.$file['file_size']);
    header('Content-Disposition: attachment; filename="meeting-document"; filename*=UTF-8\'\''.rawurlencode($file['original_filename']));
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); readfile($file['absolute_path']); exit;
}

if ($action==='set_company_code') {
    $company=emcore_minutes_int('company_id'); $code=strtoupper(emcore_minutes_text($_POST['code'] ?? null,32,true));
    if (!preg_match('/^[A-Z][A-Z0-9-]{1,31}$/',$code)) throw new EmcoreHttpException(422,'کد شرکت باید ۲ تا ۳۲ حرف لاتین، عدد یا خط تیره باشد');
    emcore_minutes_transaction($db,function() use($db,$company,$code,$actor) {
        $s=$db->prepare('SELECT id FROM emcore_companies WHERE id=:id AND deleted_at IS NULL FOR UPDATE'); $s->execute([':id'=>$company]);
        if (!$s->fetchColumn()) throw new EmcoreHttpException(404,'شرکت یافت نشد');
        $s=$db->prepare('SELECT company_id,code,locked_at FROM emcore_minutes_company_codes WHERE company_id=:id FOR UPDATE'); $s->execute([':id'=>$company]); $before=$s->fetch();
        if ($before && $before['locked_at']!==null && $before['code']!==$code) throw new EmcoreHttpException(409,'کد شرکت پس از اولین شماره صادرشده قابل تغییر نیست');
        // Never upsert through the unique code: it could target another company.
        $check=$db->prepare('SELECT company_id FROM emcore_minutes_company_codes WHERE code=:code AND company_id<>:company');
        $check->execute([':code'=>$code,':company'=>$company]); if ($check->fetchColumn()) throw new EmcoreHttpException(409,'این کد متعلق به شرکت دیگری است');
        if ($before) $db->prepare('UPDATE emcore_minutes_company_codes SET code=:code,updated_by_usr_uid=:uid,updated_at=NOW() WHERE company_id=:company')->execute([':code'=>$code,':uid'=>$actor['USR_UID'],':company'=>$company]);
        else $db->prepare('INSERT INTO emcore_minutes_company_codes (company_id,code,updated_by_usr_uid) VALUES (:company,:code,:uid)')->execute([':company'=>$company,':code'=>$code,':uid'=>$actor['USR_UID']]);
        emcore_audit(EMCORE_MINUTES_MODULE,'update','minutes_company_code',$company,$before ?: null,['company_id'=>$company,'code'=>$code]);
    }); emcore_json(['success'=>true]);
}
